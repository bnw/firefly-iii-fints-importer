<?php
namespace App\StepFunction;

use App\Step;

function PwdInput()
{
    global $twig, $request, $session;
    
    $key = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();

    if (!$request->request->has('bank_password')) {
        $session->invalidate();
        
        echo $twig->render(
            'pwd-input.twig',
            array(
                'key' => bin2hex($key),
                'next_step' => Step::STEP_ENC0_INPUT,
            ));
        return;
    } else {
        $session->set('bank_password', $request->request->get('bank_password'));
        $session->set('key', $request->request->get('key'));
    }
    
    return Step::STEP_ENC1_RESULT;
}
