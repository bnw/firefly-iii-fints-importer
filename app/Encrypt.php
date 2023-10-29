<?php
namespace App\StepFunction;

use App\Step;

function Encrypt()
{
    global $twig, $session;
    
    
    $nonce = \random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $pwd = $session->get('bank_password');
    $key = hex2bin($session->get('key'));
    
    $pwd_encrypted = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($pwd, '', $nonce, $key);
    echo $twig->render(
        'encrypt.twig',
        array(
            'pwd_encrypted' => bin2hex($pwd_encrypted),
            'nonce' => bin2hex($nonce),
            'key' => bin2hex($key)
        ));
    session_destroy();
    return Step::DONE;
}
