<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

use App\FinTsFactory;
use App\ConfigurationFactory;
use App\TanHandler;
use App\TransactionsToFireflySender;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use App\Step;
use GrumpyDictator\FFIIIApiSupport\Request\GetAccountsRequest;

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/public/html');
$twig   = new \Twig\Environment($loader);

$request = Request::createFromGlobals();

$current_step = new Step($request->request->get("step", Step::STEP0_SETUP));

$session = new Session();
$session->start();


switch ((string)$current_step) {
    case Step::STEP0_SETUP:
        $configuration_files = array();
        $dirs = array('/app/configurations', 'data/configurations');
        foreach($dirs as $dir){
            if (file_exists($dir))
                $configuration_files = array_merge($configuration_files,
                    preg_filter('/^/', $dir.DIRECTORY_SEPARATOR, array_diff(scandir($dir), array('.', '..') )));
        }

        echo $twig->render(
            'setup.twig',
            array(
                'files' => $configuration_files,
                'next_step' => Step::STEP1_COLLECTING_DATA
            ));
        break;
    case Step::STEP1_COLLECTING_DATA:
        if($request->request->get('data_collect_mode') == "createNewDataset"){
            echo $twig->render(
                'collecting-data.twig',
                array(
                    'next_step' => Step::STEP1p5_CHOOSE_2FA_DEVICE
                ));
        } else {
            $session->invalidate();

            $filename = $request->request->get('data_collect_mode');
            $configuration = ConfigurationFactory::load_from_file($filename);

            if ($configuration->bank_username == "" || $configuration->bank_password == "") {
                $configuration->bank_username = $request->request->get('bank_username');
                $configuration->bank_password = $request->request->get('bank_password');
                if ($configuration->bank_username == "" || $configuration->bank_password == "") {
                    echo $twig->render(
                        'collecting-data.twig',
                        array(
                            'next_step' => Step::STEP1_COLLECTING_DATA,
                            'configuration' => $configuration,
                            'data_collect_mode' => $filename
                        ));
                    break;
                }
            }

            $session->set('bank_username',        $configuration->bank_username);
            $session->set('bank_password',        $configuration->bank_password);
            $session->set('bank_url',             $configuration->bank_url);
            $session->set('bank_code',            $configuration->bank_code);
            $session->set('bank_2fa',             $configuration->bank_2fa);
            $session->set('firefly_url',          $configuration->firefly_url);
            $session->set('firefly_access_token', $configuration->firefly_access_token);

            $fin_ts   = FinTsFactory::create_from_session($session);
            $tan_mode = FinTsFactory::get_tan_mode($fin_ts, $session);

            if ($tan_mode->needsTanMedium()) {
                echo $twig->render(
                    'choose-2fa-device.twig',
                    array(
                        'next_step' => Step::STEP2_LOGIN,
                        'devices' => $fin_ts->getTanMedia($tan_mode)
                    ));
            } else {
                echo $twig->render(
                    'skip-form.twig',
                    array(
                        'next_step' => Step::STEP2_LOGIN,
                        'message' => "Your chosen tan mode does not require you to choose a device."
                    )
                );
            }
        }



        break;
    case Step::STEP1p5_CHOOSE_2FA_DEVICE:
        $session->invalidate();
        $session->set('bank_username', $request->request->get('bank_username'));
        // Hm, this most likely stores the password on disk somewhere. Could we at least scramble it a bit?
        $session->set('bank_password', $request->request->get('bank_password'));
        $session->set('bank_url', $request->request->get('bank_url'));
        $session->set('bank_code', $request->request->get('bank_code'));
        $session->set('bank_2fa', $request->request->get('bank_2fa'));
        $session->set('firefly_url', $request->request->get('firefly_url'));
        $session->set('firefly_access_token', $request->request->get('firefly_access_token'));
        $fin_ts   = FinTsFactory::create_from_session($session);
        $tan_mode = FinTsFactory::get_tan_mode($fin_ts, $session);

        if ($tan_mode->needsTanMedium()) {
            echo $twig->render(
                'choose-2fa-device.twig',
                array(
                    'next_step' => Step::STEP2_LOGIN,
                    'devices' => $fin_ts->getTanMedia($tan_mode)
                ));
        } else {
            echo $twig->render(
                'skip-form.twig',
                array(
                    'next_step' => Step::STEP2_LOGIN,
                    'message' => "Your chosen tan mode does not require you to choose a device."
                )
            );
        }
        break;
    case Step::STEP2_LOGIN:
        if ($request->request->has('bank_2fa_device')) {
            $session->set('bank_2fa_device', $request->request->get('bank_2fa_device'));
        }

        $fin_ts        = FinTsFactory::create_from_session($session);
        $login_handler = new TanHandler(
            function () {
                global $fin_ts;
                return $fin_ts->login();
            },
            'login-action',
            $session,
            $twig,
            $fin_ts,
            $current_step,
            $request
        );
        if ($login_handler->needs_tan()) {
            $login_handler->pose_and_render_tan_challenge();
        } else {
            echo $twig->render(
                'skip-form.twig',
                array(
                    'next_step' => Step::STEP3_CHOOSE_ACCOUNT,
                    'message' => "The connection to both your bank and your Firefly III instance could be established."
                )
            );
        }
        $session->set('persistedFints', $fin_ts->persist());
        break;
    case Step::STEP3_CHOOSE_ACCOUNT:
        $fin_ts                = FinTsFactory::create_from_session($session);
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
            echo $twig->render(
                'choose-account.twig',
                array(
                    'next_step' => Step::STEP4_GET_IMPORT_DATA,
                    'bank_accounts' => $bank_accounts,
                    'firefly_accounts' => $firefly_accounts,
                    'default_from_date' => new \DateTime('now - 1 month'),
                    'default_to_date' => new \DateTime('now')
                )
            );
            $session->set('accounts', serialize($bank_accounts));
        }
        $session->set('persistedFints', $fin_ts->persist());
        break;
    case Step::STEP4_GET_IMPORT_DATA:
        $fin_ts   = FinTsFactory::create_from_session($session);
        $accounts = unserialize($session->get('accounts'));
        $soa_handler = new TanHandler(
            function () {
                global $fin_ts, $request, $accounts, $session;
                assert($request->request->has('bank_account'));
                assert($request->request->has('firefly_account'));
                assert($request->request->has('date_from'));
                assert($request->request->has('date_to'));
                $bank_account = $accounts[intval($request->request->get('bank_account'))];
                $from         = new \DateTime($request->request->get('date_from'));
                $to           = new \DateTime($request->request->get('date_to'));
                $session->set('firefly_account', $request->request->get('firefly_account'));
                $get_statement = \Fhp\Action\GetStatementOfAccount::create($bank_account, $from, $to);
                $fin_ts->execute($get_statement);
                return $get_statement;
            },
            'soa',
            $session,
            $twig,
            $fin_ts,
            $current_step,
            $request
        );
        if ($soa_handler->needs_tan()) {
            $soa_handler->pose_and_render_tan_challenge();
        } else {
            /** @var \Fhp\Model\StatementOfAccount\StatementOfAccount $soa */
            $soa          = $soa_handler->get_finished_action()->getStatement();
            $transactions = \App\StatementOfAccountHelper::get_all_transactions($soa);
            echo $twig->render(
                'show-transactions.twig',
                array(
                    'transactions' => $transactions,
                    'next_step' => Step::STEP5_RUN_IMPORT
                )
            );
            $session->set('transactions_to_import', serialize($transactions));
            $session->set('num_transactions_processed', 0);
            $session->set('import_messages', serialize(array()));
        }
        $session->set('persistedFints', $fin_ts->persist());
        break;
    case Step::STEP5_RUN_IMPORT:
        $num_transactions_to_import_at_once = 5;
        assert($session->has('transactions_to_import'));
        assert($session->has('num_transactions_processed'));
        assert($session->has('import_messages'));
        assert($session->has('firefly_account'));
        $transactions                = unserialize($session->get('transactions_to_import'));
        $num_transactions_processed  = $session->get('num_transactions_processed');
        $import_messages             = unserialize($session->get('import_messages'));
        $transactions_to_process_now = array_slice($transactions, $num_transactions_processed, $num_transactions_to_import_at_once);
        if (empty($transactions_to_process_now)) {
            echo $twig->render(
                'done.twig',
                array(
                    'import_messages' => $import_messages,
                    'total_num_transactions' => count($transactions)
                )
            );
            $session->invalidate();
        } else {
            $num_transactions_processed += count($transactions_to_process_now);
            $sender                     = new TransactionsToFireflySender(
                $transactions_to_process_now,
                $session->get('firefly_url'),
                $session->get('firefly_access_token'),
                $session->get('firefly_account')
            );
            $result                     = $sender->send_transactions();
            if (is_array($result)) {
                $import_messages = array_merge($import_messages, $result);
            }

            $session->set('num_transactions_processed', $num_transactions_processed);
            $session->set('import_messages', serialize($import_messages));

            echo $twig->render(
                'import-progress.twig',
                array(
                    'num_transactions_processed' => $num_transactions_processed,
                    'total_num_transactions' => count($transactions),
                    'next_step' => Step::STEP5_RUN_IMPORT
                )
            );
        }
        break;

    default:
        echo "Unknown step $current_step";
        break;
}
