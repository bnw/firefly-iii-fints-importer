<?php
namespace App\StepFunction;

use App\FinTsFactory;
use App\Logger;
use App\Step;
use App\TanHandler;
use Fhp\Model\CreditCardAccount;
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
        /** @var \Fhp\Action\GetSEPAAccounts $get_sepa_accounts_action */
        $get_sepa_accounts_action = $list_accounts_handler->get_finished_action();
        $bank_accounts            = array_values($get_sepa_accounts_action->getAccounts());

        // Credit card accounts have no IBAN and are therefore not part of GetSEPAAccounts (HKSPA).
        // GetCreditCardAccounts reads them from the UPD received during login and needs no request to
        // the bank, so this cannot pose a TAN challenge. Append them to the same list; from here on an
        // account is identified by its index, regardless of its type.
        try {
            $get_credit_card_accounts = \Fhp\Action\GetCreditCardAccounts::create();
            $fin_ts->execute($get_credit_card_accounts);
            $bank_accounts = array_merge($bank_accounts, array_values($get_credit_card_accounts->getAccounts()));
        } catch (\Throwable $e) {
            // A bank that does not offer credit card statements should not break the regular flow.
            Logger::info('Could not determine credit card accounts: ' . $e->getMessage());
        }

        // The account objects differ (SEPAAccount vs. CreditCardAccount) and expose different fields,
        // so pre-compute a display label per account instead of calling type-specific getters in the
        // template.
        $account_labels = array();
        foreach ($bank_accounts as $bank_account) {
            if ($bank_account instanceof CreditCardAccount) {
                $account_labels[] = trim(($bank_account->getProductName() ?: 'Credit card')
                    . ' - ' . $bank_account->getAccountNumber());
            } else {
                $account_labels[] = ($bank_account->getIban() ? $bank_account->getIban() . ' - ' : '')
                    . $bank_account->getSubAccount() . ', ' . $bank_account->getAccountNumber();
            }
        }

        $firefly_accounts_request = new GetAccountsRequest($session->get('firefly_url'), $session->get('firefly_access_token'));
        $firefly_accounts_request->setType(GetAccountsRequest::ASSET);
        /** @var \GrumpyDictator\FFIIIApiSupport\Response\GetAccountsResponse $firefly_accounts */
        $firefly_accounts = $firefly_accounts_request->get();

        $requested_bank_index = -1;
        $requested_bank_iban = $session->get('bank_account_iban');
        $requested_bank_number = $session->get('bank_account_number');
        $requested_firefly_id = $session->get('firefly_account_id');
        $error = '';

        // A regular account is selected by IBAN, a credit card account by its account number.
        if (!is_null($requested_bank_iban) || !is_null($requested_bank_number)) {
            for ($i = 0; $i < count($bank_accounts); $i++) {
                $candidate = $bank_accounts[$i];
                if ($candidate instanceof CreditCardAccount) {
                    if (!is_null($requested_bank_number) && $candidate->getAccountNumber() == $requested_bank_number) {
                        $requested_bank_index = $i;
                        break;
                    }
                } else {
                    if (!is_null($requested_bank_iban) && $candidate->getIban() == $requested_bank_iban) {
                        $requested_bank_index = $i;
                        break;
                    }
                }
            }
            if ($requested_bank_index == -1) {
                $wanted = is_null($requested_bank_iban)
                    ? 'credit card account number "' . $requested_bank_number . '"'
                    : 'IBAN "' . $requested_bank_iban . '"';
                $error = $error . 'Could not find ' . $wanted . ' in your bank accounts.' . "\n";
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

        $can_be_automated = false;

        if (!is_null($session->get('choose_account_from')) && !is_null($session->get('choose_account_to')))
        {
            $can_be_automated = true;
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
                    'account_labels' => $account_labels,
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
