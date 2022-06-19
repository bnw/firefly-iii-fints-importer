<?php
namespace App\StepFunction;

use App\FinTsFactory;
use App\Step;
use App\TanHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use GrumpyDictator\FFIIIApiSupport\Request\GetAccountsRequest;
use GrumpyDictator\FFIIIApiSupport\Response\GetAccountResponse;

function ChooseAccount()
{
    global $request, $session, $twig, $fin_ts;

    $fin_ts = FinTsFactory::create_from_session($session);
    $current_step  = new Step($request->request->get("step", Step::STEP0_SETUP));
    $list_accounts_handler = new TanHandler(
        function () {
            global $fin_ts;
            $get_sepa_accounts = \Fhp\Action\GetSEPAAccounts::create();
            $fin_ts->execute($get_sepa_accounts);
            return $get_sepa_accounts;
        },
        'list-accounts',
        $session,
        $twig,
        $fin_ts,
        $current_step,
        $request
    );
    if ($list_accounts_handler->needs_tan()) {
        $list_accounts_handler->pose_and_render_tan_challenge();
    } else {
        $bank_accounts            = $list_accounts_handler->get_finished_action()->getAccounts();
        $firefly_accounts_request = new GetAccountsRequest($session->get('firefly_url'), $session->get('firefly_access_token'));
        $firefly_accounts_request->setType(GetAccountsRequest::ASSET);
        $firefly_accounts = $firefly_accounts_request->get();

        $requested_bank_index = -1;
        $requested_bank_iban = $session->get('bank_account_iban');
        $requested_firefly_id = $session->get('firefly_account_id');
        $error = '';

        if (!is_null($requested_bank_iban)) {
            for ($i = 0; $i < count($bank_accounts); $i++) {
                if ($bank_accounts[$i]->getIban() == $requested_bank_iban) {
                    $requested_bank_index = $i;
                    break;
                }
            }
            if ($requested_bank_index == -1) {
                $error = $error . 'Could not find IBAN "' . $requested_bank_iban . '" in your bank accounts.' . "\n";
                $error = $error . 'Please review your configuration.' . "\n";
            }
        }
        if (!is_null($requested_firefly_id)) {
            $firefly_accounts->rewind();
            for ($acc = $firefly_accounts->current(); $firefly_accounts->valid(); $acc = $firefly_accounts->current()) {
                if ($acc->id == $requested_firefly_id) {
                    break;
                }
                $firefly_accounts->next();
            }
            if (!$firefly_accounts->valid()) {
                $error = $error . 'Could not find the Firefly ID "' . $requested_firefly_id . '" in your Firefly III account.' . "\n";
                $error = $error . 'Please review your configuration.' . "\n";
            }
            $firefly_accounts->rewind();
        }

        $default_from_date = new \DateTime('now - 1 month');
        $default_to_date = new \DateTime('now');

        $automate = false;

        if (!is_null($session->get('choose_account_from')) && !is_null($session->get('choose_account_to')))
        {
            $automate = true;
        }
        if (!is_null($session->get('choose_account_from')))
        {
            $default_from_date = new \DateTime($session->get('choose_account_from'));
        }
        if (!is_null($session->get('choose_account_to')))
        {
            $default_to_date = new \DateTime($session->get('choose_account_to'));
        }


        if (empty($error)) {
            echo $twig->render(
                'choose-account.twig',
                array(
                    'next_step' => Step::STEP4_GET_IMPORT_DATA,
                    'bank_accounts' => $bank_accounts,
                    'firefly_accounts' => $firefly_accounts,
                    'default_from_date' => $default_from_date,
                    'default_to_date' => $default_to_date,
                    'bank_account_iban' => $requested_bank_iban,
                    'bank_account_index' => $requested_bank_index,
                    'firefly_account_id' => $requested_firefly_id,
                    'automate' => $automate
                )
            );
            $session->set('accounts', serialize($bank_accounts));
        } else {
            echo $twig->render(
                'error.twig',
                array(
                    'error_header' => 'Failed to verify given Information',
                    'error_message' => $error
                )
            );
        }
    }
    $session->set('persistedFints', $fin_ts->persist());
}