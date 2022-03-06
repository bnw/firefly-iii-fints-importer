<?php
namespace App\StepFunction;

use App\FinTsFactory;
use App\Step;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

function Choose2FADevice()
{
    global $request, $session, $twig;

    $session->invalidate();
    $session->set('bank_username', $request->request->get('bank_username'));
    // Hm, this most likely stores the password on disk somewhere. Could we at least scramble it a bit?
    $session->set('bank_password', $request->request->get('bank_password'));
    $session->set('bank_url', $request->request->get('bank_url'));
    $session->set('bank_code', $request->request->get('bank_code'));
    $session->set('bank_2fa', $request->request->get('bank_2fa'));
    $session->set('firefly_url', $request->request->get('firefly_url'));
    $session->set('firefly_access_token', $request->request->get('firefly_access_token'));
    $session->set('automate', $request->request->get('automate'));
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