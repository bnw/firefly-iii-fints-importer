<?php
namespace App;

class PasswordStorage
{
    
    static function set(string $pwd)
    {
        if (self::apcuAvailable()) {
            $result = apcu_store('firefly_fints_bank_password', $pwd);
            assert($result, "APCu store failed");
        } else {
            global $session;
            $session->set('firefly_fints_bank_password', $pwd);
        }
    }
    
    static function get()
    {        
        if (self::apcuAvailable()) {
            $result = apcu_fetch('firefly_fints_bank_password');
            assert($result, "APCu fetch failed");
            return $result;
        } else {
            global $session;
            return $session->get('firefly_fints_bank_password');
        }
    }
    
    static function clear()
    {
        if (self::apcuAvailable()) {
            apcu_clear_cache();
        } else {
            global $session;
            $session->set('firefly_fints_bank_password', '');
        }
    }
    
    static function apcuAvailable()
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

}