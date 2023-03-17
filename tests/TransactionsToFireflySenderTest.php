<?php

use App\ConfigurationFactory;
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
                    'sepa_ct_id' => '',
                    'notes' => null,
                )
            )
        );
        $actual   = TransactionsToFireflySender::transform_transaction_to_firefly_request_body(
            $transaction,
            $this->firefly_account_id, "", ""
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
        $transaction->setStructuredDescription(array('SVWZ' => 'description', 'ABWA' => 'bakery'));

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
                    'sepa_ct_id' => '',
                    'notes' => 'bakery',
                )
            )
        );
        $actual   = TransactionsToFireflySender::transform_transaction_to_firefly_request_body(
            $transaction,
            $this->firefly_account_id, "", ""
        );

        $this->assertEquals($expected, $actual);
    }

    public function test_transaction_processing_debit_regex()
    {
        $transaction = new Transaction;
        $transaction->setAccountNumber($this->valid_iban);
        $transaction->setCreditDebit(Transaction::CD_DEBIT);
        $transaction->setValutaDate(new DateTime('2020-06-01'));
        $transaction->setAmount(3.14);
        $transaction->setName('destination_name');
        $transaction->setStructuredDescription(array('SVWZ' => 'LASTSCHRIFT / BELASTUNGExample StoreKARTE 000000000KDN-REF 000000Ref. XXXX/0000', 'ABWA' => 'bakery'));

        $configuration = ConfigurationFactory::load_from_file('data/configurations/example.json');

        $exp_regex_match = '/^(Übertrag \/ Überweisung|Lastschrift \/ Belastung)(.*)(END-TO-END-REF.*|Karte.*|KFN.*)(Ref\..*)$/mi';
        $exp_regex_replace = '$2 [$1 | $3 | $4]';

        $this->assertEquals($exp_regex_match, $configuration->description_regex_match);
        $this->assertEquals($exp_regex_replace, $configuration->description_regex_replace);

        $expected = array(
            'apply_rules' => true,
            'error_if_duplicate_hash' => true,
            'transactions' => array(
                array(
                    'type' => 'withdrawal',
                    'date' => '2020-06-01',
                    'amount' => 3.14,
                    'description' => 'Example Store [LASTSCHRIFT / BELASTUNG | KARTE 000000000KDN-REF 000000 | Ref. XXXX/0000]',
                    'source_name' => null,
                    'source_id' => $this->firefly_account_id,
                    'source_iban' => null,
                    'destination_name' => 'destination_name',
                    'destination_id' => null,
                    'destination_iban' => $this->valid_iban,
                    'sepa_ct_id' => '',
                    'notes' => 'bakery',
                )
            )
        );
        $actual   = TransactionsToFireflySender::transform_transaction_to_firefly_request_body(
            $transaction,
            $this->firefly_account_id, $configuration->description_regex_match, $configuration->description_regex_replace
        );

        $this->assertEquals($expected, $actual);
    }

    public function test_transaction_processing_debit_incorrect_regex()
    {
        $transaction = new Transaction;
        $transaction->setAccountNumber($this->valid_iban);
        $transaction->setCreditDebit(Transaction::CD_DEBIT);
        $transaction->setValutaDate(new DateTime('2020-06-01'));
        $transaction->setAmount(3.14);
        $transaction->setName('destination_name');
        $transaction->setStructuredDescription(array('SVWZ' => 'LASTSCHRIFT / BELASTUNGExample StoreKARTE 000000000KDN-REF 000000Ref. XXXX/0000', 'ABWA' => 'bakery'));

        $regex_match = '/^(Übertrag \/ Überweisung|Lastschrift \/ Belastung)(.*)(END-TO-END-REF.*|Karte.*|KFN.*)(Ref\..*)$'; // not a valid PHP regex, missing / at the end
        $regex_replace = '$2 [$1 | $3 | $4]';

        set_error_handler(function() { /* ignore errors */ }); // required to suppress E_WARNING from preg_replace and test for an exception being thrown
        $this->expectException(Exception::class);
        $actual   = TransactionsToFireflySender::transform_transaction_to_firefly_request_body(
            $transaction,
            $this->firefly_account_id, $regex_match, $regex_replace
        );
        restore_error_handler();
    }

}
