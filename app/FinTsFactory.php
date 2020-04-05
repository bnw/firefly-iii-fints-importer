<?php


namespace App;

use Symfony\Component\HttpFoundation\Session\Session;


class FinTsFactory
{
    static function create_from_session(Session $session)
    {
        foreach(['url','bank_code','username','password','2fa'] as $required_session_value){
            assert($session->has($required_session_value));
        }
        $finTs = new \Fhp\FinTsNew(
            $session->get('url'),
            $session->get('bank_code'),
            $session->get('username'),
            $session->get('password'),
            '0F4CA8A225AC9799E6BE3F334', // https://github.com/firefly-iii/firefly-iii/issues/3233#issuecomment-609050579
            '1.0'
        );
        $finTs->selectTanMode(intval($session->get('2fa')));
        if ($session->has('persistedFints')) {
            echo "<p>Loaded persisted fints</p>"; //TODO debug
            $finTs->loadPersistedInstance($session->get('persistedFints'));
        }
        return $finTs;
    }
}