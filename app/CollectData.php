<?php
namespace App\StepFunction;

use App\FinTsFactory;
use App\ConfigurationFactory;
use App\Step;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

function CollectData()
{
    global $request, $session, $twig;

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
                return;
            }
        }

        $session->set('bank_username',           $configuration->bank_username);
        $session->set('bank_password',           $configuration->bank_password);
        $session->set('bank_url',                $configuration->bank_url);
        $session->set('bank_code',               $configuration->bank_code);
        $session->set('bank_2fa',                $configuration->bank_2fa);
        $session->set('firefly_url',             $configuration->firefly_url);
        $session->set('firefly_access_token',    $configuration->firefly_access_token);
        $session->set('skip_transaction_review', $configuration->skip_transaction_review);

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
}