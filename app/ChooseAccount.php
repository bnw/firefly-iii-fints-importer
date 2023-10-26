<?php
namespace App\StepFunction;

use App\FinTsFactory;
use App\Step;
use App\TanHandler;
use App\TransactionsHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use GrumpyDictator\FFIIIApiSupport\Request\GetAccountsRequest;
use GrumpyDictator\FFIIIApiSupport\Response\GetAccountResponse;

function ChooseAccount()
{
    global $request, $session, $twig, $fin_ts, $automate_without_js;

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

        $default_from_date = 'now - 1 month';
        $default_to_date = 'now';

        $can_be_automated = false;

        if (!is_null($session->get('choose_account_from')) && !is_null($session->get('choose_account_to')))
        {
            $can_be_automated = true;
        }
        
        $default_from_date = getDateTime($session->get('choose_account_from'), $default_from_date, $requested_firefly_id);
        $default_to_date = getDateTime($session->get('choose_account_to'), $default_to_date, $requested_firefly_id);


        if (empty($error)) {
            $session->set('accounts', serialize($bank_accounts));
            if ($can_be_automated && $automate_without_js)
            {
                $request->request->set('bank_account', $requested_bank_index);
                $request->request->set('firefly_account', $requested_firefly_id);
                $request->request->set('date_from', $default_from_date->format('Y-m-d'));
                $request->request->set('date_to', $default_to_date->format('Y-m-d'));

                $session->set('persistedFints', $fin_ts->persist());
                return Step::STEP4_GET_IMPORT_DATA;
            }
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
                    'auto_submit_form_via_js' => $can_be_automated
                )
            );
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
    return Step::DONE;
}

function getDateTime(?string $date, string $default, ?int $firefly_account_id) 
{
    if (!is_null($firefly_account_id) && $date == 'last') {
        global $session;
        
        $firefly_transactions_helper = new TransactionsHelper($session->get('firefly_url'), $session->get('firefly_access_token'), $firefly_account_id);
        $last_transaction = $firefly_transactions_helper->get_last_transaction();
        if (!is_null($last_transaction)) {
            $date = $last_transaction->date;
        } else {
            $date = $default;    
        }
    } else if (is_null($date)) {
        $date = $default;
    }
    return new \DateTime($date);   
}

