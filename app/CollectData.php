<?php
namespace App\StepFunction;

use App\FinTsFactory;
use App\ConfigurationFactory;
use App\Step;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

function CollectData()
{
    global $request, $session, $twig, $automate_without_js;

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

        if ($request->request->has('bank_username')) {
            $configuration->bank_username = $request->request->get('bank_username');
        }
        if ($request->request->has('bank_password')) {
            $configuration->bank_password = $request->request->get('bank_password');
        }
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

        $session->set('bank_username',           $configuration->bank_username);
        $session->set('bank_password',           $configuration->bank_password);
        $session->set('bank_url',                $configuration->bank_url);
        $session->set('bank_code',               $configuration->bank_code);
        $session->set('bank_2fa',                $configuration->bank_2fa);
        $session->set('firefly_url',             $configuration->firefly_url);
        $session->set('firefly_access_token',    $configuration->firefly_access_token);
        $session->set('skip_transaction_review', $configuration->skip_transaction_review);
        $session->set('bank_account_iban' ,      $configuration->bank_account_iban);
        $session->set('firefly_account_id',      $configuration->firefly_account_id);
        $session->set('choose_account_from' ,    $configuration->choose_account_from);
        $session->set('choose_account_to',       $configuration->choose_account_to);
        $session->set('description_regex_match', $configuration->description_regex_match);
        $session->set('description_regex_replace', $configuration->description_regex_replace);

        $fin_ts   = FinTsFactory::create_from_session($session);
        $tan_mode = FinTsFactory::get_tan_mode($fin_ts, $session);

        if ($tan_mode->needsTanMedium()) {
            $tan_devices = $fin_ts->getTanMedia($tan_mode);
            if (count($tan_devices) == 1) {
                if ($automate_without_js) {
                    $session->set('bank_2fa_device', $tan_devices[0]->getName());
                    return Step::STEP2_LOGIN;
                }

                $auto_skip_form = true;
            } else {
                $auto_skip_form = false;
            }
            echo $twig->render(
                'choose-2fa-device.twig',
                array(
                    'next_step' => Step::STEP2_LOGIN,
                    'devices' => $fin_ts->getTanMedia($tan_mode),
                    'auto_skip_form' => $auto_skip_form
                ));
        } else {
            if ($automate_without_js)
            {
                return Step::STEP2_LOGIN;
            }
            echo $twig->render(
                'skip-form.twig',
                array(
                    'next_step' => Step::STEP2_LOGIN,
                    'message' => "Your chosen tan mode does not require you to choose a device."
                )
            );
        }
    }
    return Step::DONE;
}
