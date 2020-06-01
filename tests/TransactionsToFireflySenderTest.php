<?php

use App\TransactionsToFireflySender;
use Fhp\Model\StatementOfAccount\Transaction;
use PHPUnit\Framework\TestCase;

final class TransactionsToFireflySenderTest extends TestCase
{
    private $valid_iban = 'DE75512108001245126199';
    private $invalid_iban = 'DE75512108001245126198';
    private $firefly_account_id = 15;

    public function test_get_iban()
    {
        $transaction = new Transaction;
        $transaction->setAccountNumber($this->valid_iban);
        $this->assertEquals($this->valid_iban, TransactionsToFireflySender::get_iban($transaction));
        $transaction->setAccountNumber($this->invalid_iban);
        $this->assertEquals(null, TransactionsToFireflySender::get_iban($transaction));
    }

    public function test_transaction_processing_credit()
    {
        $transaction = new Transaction;
        $transaction->setAccountNumber($this->valid_iban);
        $transaction->setCreditDebit(Transaction::CD_CREDIT);
        $transaction->setValutaDate(new DateTime('2020-06-01'));
        $transaction->setAmount(3.14);
        $transaction->setName('source_name');
        $transaction->setStructuredDescription(array('SVWZ' => 'description'));

        $expected = array(
            'apply_rules' => true,
            'error_if_duplicate_hash' => true,
            'transactions' => array(
                array(
                    'type' => 'deposit',
                    'date' => '2020-06-01',
                    'amount' => 3.14,
                    'description' => 'description',
                    'source_name' => 'source_name',
                    'source_id' => null,
                    'source_iban' => $this->valid_iban,
                    'destination_name' => null,
                    'destination_id' => $this->firefly_account_id,
                    'destination_iban' => null,
                )
            )
        );
        $actual   = TransactionsToFireflySender::transform_transaction_to_firefly_request_body(
            $transaction,
            $this->firefly_account_id
        );

        $this->assertEquals($expected, $actual);
    }

    public function test_transaction_processing_debit()
    {
        $transaction = new Transaction;
        $transaction->setAccountNumber($this->valid_iban);
        $transaction->setCreditDebit(Transaction::CD_DEBIT);
        $transaction->setValutaDate(new DateTime('2020-06-01'));
        $transaction->setAmount(3.14);
        $transaction->setName('destination_name');
        $transaction->setStructuredDescription(array('SVWZ' => 'description'));

        $expected = array(
            'apply_rules' => true,
            'error_if_duplicate_hash' => true,
            'transactions' => array(
                array(
                    'type' => 'withdrawal',
                    'date' => '2020-06-01',
                    'amount' => 3.14,
                    'description' => 'description',
                    'source_name' => null,
                    'source_id' => $this->firefly_account_id,
                    'source_iban' => null,
                    'destination_name' => 'destination_name',
                    'destination_id' => null,
                    'destination_iban' => $this->valid_iban,
                )
            )
        );
        $actual   = TransactionsToFireflySender::transform_transaction_to_firefly_request_body(
            $transaction,
            $this->firefly_account_id
        );

        $this->assertEquals($expected, $actual);
    }

}