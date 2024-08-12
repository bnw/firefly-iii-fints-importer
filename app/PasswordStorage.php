<?php
namespace App;

class PasswordStorage
{
    
    static function set(string $pwd)
    {
        if (self::apcuAvailable()) {
            apcu_store('firefly_fints_bank_password', $pwd);
        } else {
            global $session;
            $session->set('firefly_fints_bank_password', $pwd);
        }
    }
    
    static function get()
    {        
        if (self::apcuAvailable()) {
            return apcu_fetch('firefly_fints_bank_password');
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