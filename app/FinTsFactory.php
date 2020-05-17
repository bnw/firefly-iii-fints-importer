<?php


namespace App;

use Symfony\Component\HttpFoundation\Session\Session;


class FinTsFactory
{
    static function create_from_session(Session $session)
    {
        foreach(['bank_url','bank_code','bank_username','bank_password','bank_2fa'] as $required_session_value){
            assert($session->has($required_session_value), "Missing value in sessions for: " . $required_session_value);
        }
        $finTs = new \Fhp\FinTsNew(
            $session->get('bank_url'),
            $session->get('bank_code'),
            $session->get('bank_username'),
            $session->get('bank_password'),
            '0F4CA8A225AC9799E6BE3F334', // https://github.com/firefly-iii/firefly-iii/issues/3233#issuecomment-609050579
            '1.0'
        );
        $finTs->selectTanMode(intval($session->get('bank_2fa')));
        if ($session->has('persistedFints')) {
            $finTs->loadPersistedInstance($session->get('persistedFints'));
        }
        return $finTs;
    }
}