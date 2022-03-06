<?php
namespace App\StepFunction;

use App\FinTsFactory;
use App\Step;
use App\TanHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use GrumpyDictator\FFIIIApiSupport\Request\GetAccountsRequest;

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
}