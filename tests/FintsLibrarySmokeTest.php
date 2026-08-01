<?php

use PHPUnit\Framework\TestCase;

final class FintsLibrarySmokeTest extends TestCase
{
    /**
     * Every Fhp\* class this app references directly (via `use` statements
     * or fully-qualified names) in app/*.php. If nemiah/php-fints renames
     * or removes one of these, this test fails instead of the breakage
     * only surfacing during a real bank login.
     */
    public function test_classes_used_by_app_exist(): void
    {
        $classes = [
            \Fhp\FinTs::class,
            \Fhp\BaseAction::class,
            \Fhp\Model\NoPsd2TanMode::class,
            \Fhp\Model\TanRequestChallengeImage::class,
            \Fhp\Model\StatementOfAccount\StatementOfAccount::class,
            \Fhp\Model\StatementOfAccount\Transaction::class,
            \Fhp\Options\Credentials::class,
            \Fhp\Options\FinTsOptions::class,
            \Fhp\Action\GetSEPAAccounts::class,
            \Fhp\Action\GetStatementOfAccount::class,
            \Fhp\Action\GetStatementOfAccountXML::class,
            \Fhp\Protocol\UnexpectedResponseException::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(
                class_exists($class) || interface_exists($class),
                "Expected $class to exist in nemiah/php-fints"
            );
        }
    }

    public function test_methods_used_by_app_exist(): void
    {
        $this->assertTrue(
            method_exists(\Fhp\Action\GetSEPAAccounts::class, 'create'),
            'GetSEPAAccounts::create() is used in app/ChooseAccount.php'
        );
        $this->assertTrue(
            method_exists(\Fhp\Action\GetStatementOfAccount::class, 'create'),
            'GetStatementOfAccount::create() is used in app/GetImportData.php'
        );
        $this->assertTrue(
            method_exists(\Fhp\Action\GetStatementOfAccountXML::class, 'create'),
            'GetStatementOfAccountXML::create() is used in app/GetImportData.php'
        );
        $this->assertTrue(
            method_exists(\Fhp\Action\GetStatementOfAccountXML::class, 'getBookedXML'),
            'getBookedXML() is used in app/GetImportData.php'
        );
    }
}
