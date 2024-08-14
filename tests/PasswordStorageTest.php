<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use App\PasswordStorage;

$session = new Session();
$session->start();

final class PasswordStorageTest extends TestCase
{

    public function test_store()
    {
        PasswordStorage::set("somePwd123");
        $this->assertEquals("somePwd123", PasswordStorage::get());      
        PasswordStorage::clear();
    }
    
    public function test_apcu()
    {
        $this->assertEquals(true, PasswordStorage::apcuAvailable());
        PasswordStorage::set("somePwd123");
        
        $this->assertEquals("somePwd123", apcu_fetch('firefly_fints_bank_password'));
        
        global $session;
        $this->assertNotEquals("somePwd123", $session->get('firefly_fints_bank_password'));
        PasswordStorage::clear();
        
        $this->expectException(AssertionError::class);
        PasswordStorage::get();
    }

}
