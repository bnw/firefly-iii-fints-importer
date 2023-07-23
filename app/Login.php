<?php
namespace App\StepFunction;

use App\FinTsFactory;
use App\Step;
use App\TanHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

function Login()
{
    global $request, $session, $twig, $fin_ts, $automate_without_js;

    if ($request->request->has('bank_2fa_device')) {
        $session->set('bank_2fa_device', $request->request->get('bank_2fa_device'));
    }
    $fin_ts = FinTsFactory::create_from_session($session);

    $current_step  = new Step($request->request->get("step", Step::STEP0_SETUP));
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
        if ($automate_without_js)
        {
            $session->set('persistedFints', $fin_ts->persist());
            return Step::STEP3_CHOOSE_ACCOUNT;
        }
        echo $twig->render(
            'skip-form.twig',
            array(
                'next_step' => Step::STEP3_CHOOSE_ACCOUNT,
                'message' => "The connection to your bank was tested sucessfully."
            )
        );
    }
    $session->set('persistedFints', $fin_ts->persist());
    return Step::DONE;
}