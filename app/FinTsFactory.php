<?php


namespace App;

use Fhp\FinTs;
use Fhp\Model\NoPsd2TanMode;
use Fhp\Options\Credentials;
use Fhp\Options\FinTsOptions;
use Symfony\Component\HttpFoundation\Session\Session;


class FinTsFactory
{
    static function create_from_session(Session $session)
    {
        foreach(['bank_url','bank_code','bank_username','bank_password','bank_2fa'] as $required_session_value){
            assert($session->has($required_session_value), "Missing value in sessions for: " . $required_session_value);
        }
        $options = new FinTsOptions();
        $options->url = $session->get('bank_url');
        $options->bankCode = $session->get('bank_code');
        $options->productName = '0F4CA8A225AC9799E6BE3F334'; // https://github.com/firefly-iii/firefly-iii/issues/3233#issuecomment-609050579
        $options->productVersion = '1.0';
        
        $credentials = Credentials::create($session->get('bank_username'), $session->get('bank_password'));
        
        $finTs = FinTs::new($options, $credentials);
        
        $tanMode = self::get_tan_mode($finTs, $session);

        if ($tanMode->needsTanMedium() and $session->has('bank_2fa_device')) {
            $finTs->selectTanMode($tanMode, $session->get('bank_2fa_device'));
        } elseif (!$tanMode->needsTanMedium()) {
            $finTs->selectTanMode($tanMode);
        } else {
            // we dont have the necessary tan medium yet
        }

        if ($session->has('persistedFints')) {
            $finTs->loadPersistedInstance($session->get('persistedFints'));
        }
        return $finTs;
    }

    static function get_tan_mode(FinTs $finTs, Session $session)
    {
        $tanModeId = $session->get('bank_2fa');
        
        if($tanModeId == 'NoPsd2TanMode'){
            // See https://github.com/nemiah/phpFinTS/issues/57
            return new NoPsd2TanMode();
        }else{
            $tanModeId = intval($tanModeId);
            $tanModes = $finTs->getTanModes();
            assert(
                array_key_exists($tanModeId, $tanModes), 
                "Your bank did not accept your tan mode $tanModeId. Accepted modes are: " . implode(", ", array_keys($tanModes))
            );
            $tanMode = $tanModes[$tanModeId];
            assert($tanMode->getId() == $tanModeId);
            return $tanMode;
        }
    }
}