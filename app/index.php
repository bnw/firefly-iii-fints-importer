<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use App\Step;

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/public/html');
$twig   = new \Twig\Environment($loader);

$request = Request::createFromGlobals();

$current_step = new Step($request->request->get("step", Step::STEP1_COLLECTING_DATA));
echo $current_step; //TODO debug

$session = new Session();
$session->start();


switch ((string)$current_step) {
    case Step::STEP1_COLLECTING_DATA:
        echo $twig->render(
            'collecting-data.twig',
            array(
                'next_step' => Step::STEP2_LOGIN
            ));
        break;
    case Step::STEP2_LOGIN:
        $session->invalidate();
        $session->set('username', $request->request->get('username'));
        // Hm, this most likely stores the password on disk somewhere. Could we at least scramble it a bit?
        $session->set('password', $request->request->get('password'));
        $session->set('url', $request->request->get('url'));
        $session->set('bank_code', $request->request->get('bank_code'));
        $session->set('2fa', $request->request->get('2fa'));

        $fin_ts        = \App\FinTsFactory::create_from_session($session);
        $login_handler = new \App\TanHandler(
            function () {
                global $fin_ts;
                return $fin_ts->login();
            },
            'login-action',
            $session,
            $twig,
            $fin_ts,
            $current_step
        );
        if ($login_handler->needs_tan()) {
            $login_handler->pose_and_render_tan_challenge();
        } else {
            echo $twig->render(
                'skip-form.twig',
                array(
                    'next_step' => Step::STEP3_CHOOSE_ACCOUNT
                )
            );
        }
        $session->set('persistedFints', $fin_ts->persist());
        break;
    case Step::STEP3_CHOOSE_ACCOUNT:
        $fin_ts                = \App\FinTsFactory::create_from_session($session);
        $list_accounts_handler = new \App\TanHandler(
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
            $current_step
        );
        if ($list_accounts_handler->needs_tan()) {
            $list_accounts_handler->pose_and_render_tan_challenge();
        } else {
            /** @var \Fhp\Action\GetSEPAAccounts $action */
            $action = $list_accounts_handler->get_finished_action();
            echo $twig->render(
                'choose-account.twig',
                array(
                    'next_step' => Step::STEP4_GET_IMPORT_DATA,
                    'accounts' => $action->getAccounts(),
                    'default_from_date' => new \DateTime('now - 1 month'),
                    'default_to_date' => new \DateTime('now')
                )
            );
            $session->set('accounts', serialize($action->getAccounts()));
        }
        $session->set('persistedFints', $fin_ts->persist());
        break;
    case Step::STEP4_GET_IMPORT_DATA:
        $fin_ts   = \App\FinTsFactory::create_from_session($session);
        $accounts = unserialize($session->get('accounts'));
        assert($request->request->has('account'));
        $account     = $accounts[intval($request->request->get('account'))];
        $from        = new \DateTime($request->request->get('date_from'));
        $to          = new \DateTime($request->request->get('date_to'));
        $soa_handler = new \App\TanHandler(
            function () {
                global $fin_ts, $account, $from, $to;
                $get_statement = \Fhp\Action\GetStatementOfAccount::create($account, $from, $to);
                $fin_ts->execute($get_statement);
                return $get_statement;
            },
            'soa',
            $session,
            $twig,
            $fin_ts,
            $current_step
        );
        if ($soa_handler->needs_tan()) {
            $soa_handler->pose_and_render_tan_challenge();
        } else {
            /** @var \Fhp\Model\StatementOfAccount\StatementOfAccount $soa */
            $soa = $soa_handler->get_finished_action()->getStatement();
            echo $twig->render(
                'show-transactions.twig',
                array(
                    'statements' => $soa->getStatements()
                )
            );
        }
        $session->set('persistedFints', $fin_ts->persist());
        //TODO get the transactions to firefly-iii
        break;
    default:
        echo "Unknown step $current_step";
        break;
}
