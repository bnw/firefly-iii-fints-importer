<?php

use App\ConfigurationFactory;
use App\TransactionsToFireflySender;
use Fhp\Model\StatementOfAccount\Transaction;
use GrumpyDictator\FFIIIApiSupport\Response\GetAccountsResponse;
use PHPUnit\Framework\TestCase;

final class IgnoreCounterpartyIbanTest extends TestCase
{
    private const PROCESSOR_IBAN = 'DE75512108001245126199';
    private const OWN_IBAN       = 'AO81655475361786327281217';
    private const CARD_REGEX     = '/Debitk\.|KARTE/i';

    private $firefly_accounts;

    public function setUp(): void
    {
        $this->firefly_accounts = new GetAccountsResponse(array(
            array('id' => 15, 'attributes' => array(
                'name' => 'checking', 'type' => 'asset', 'iban' => 'DE93500105176891219573',
                'account_number' => '1', 'bic' => 'BIC1', 'current_balance' => '0',
                'current_balance_date' => '2020-01-01T00:00:00+00:00', 'currency_code' => 'EUR',
            )),
            array('id' => 19, 'attributes' => array(
                'name' => 'savings', 'type' => 'asset', 'iban' => self::OWN_IBAN,
                'account_number' => '2', 'bic' => 'BIC2', 'current_balance' => '0',
                'current_balance_date' => '2020-01-01T00:00:00+00:00', 'currency_code' => 'EUR',
            )),
        ));
    }

    private function card_payment(string $iban, string $booking_text, string $purpose): Transaction
    {
        $transaction = new Transaction;
        $transaction->setAccountNumber($iban);
        $transaction->setCreditDebit(Transaction::CD_DEBIT);
        $transaction->setValutaDate(new DateTime('2026-01-15'));
        $transaction->setAmount(4.20);
        $transaction->setName('Example Bakery');
        $transaction->setBookingText($booking_text);
        $transaction->setStructuredDescription(array('SVWZ' => $purpose));
        return $transaction;
    }

    private function body(Transaction $transaction, string $ignore_regex = ""): array
    {
        $result = TransactionsToFireflySender::transform_transaction_to_firefly_request_body(
            $transaction, 15, $this->firefly_accounts, "", "", $ignore_regex
        );
        return $result['transactions'][0];
    }

    public function test_iban_is_sent_by_default()
    {
        $body = $this->body($this->card_payment(self::PROCESSOR_IBAN, 'DIGITAL CARD', '2026-01-14T10:00 Debitk.21 2028-12'));
        $this->assertSame(self::PROCESSOR_IBAN, $body['destination_iban']);
        $this->assertSame('Example Bakery', $body['destination_name']);
    }

    public function test_iban_is_dropped_when_the_booking_text_matches()
    {
        $body = $this->body($this->card_payment(self::PROCESSOR_IBAN, 'KARTENZAHLUNG', 'something else'), self::CARD_REGEX);
        $this->assertArrayNotHasKey('destination_iban', $body);
        $this->assertSame('Example Bakery', $body['destination_name']);
        $this->assertSame('withdrawal', $body['type']);
    }

    public function test_iban_is_dropped_when_the_description_matches()
    {
        $body = $this->body($this->card_payment(self::PROCESSOR_IBAN, '', '2026-01-14T10:00 Debitk.21 2028-12'), self::CARD_REGEX);
        $this->assertArrayNotHasKey('destination_iban', $body);
        $this->assertSame('Example Bakery', $body['destination_name']);
    }

    public function test_iban_is_kept_when_nothing_matches()
    {
        $body = $this->body($this->card_payment(self::PROCESSOR_IBAN, 'DIRECT DEBIT', 'monthly fee'), self::CARD_REGEX);
        $this->assertSame(self::PROCESSOR_IBAN, $body['destination_iban']);
    }

    public function test_matching_transaction_is_not_turned_into_a_transfer()
    {
        // The IBAN belongs to one of the user's own accounts. Without the option this is a transfer.
        $transaction = $this->card_payment(self::OWN_IBAN, 'KARTENZAHLUNG', 'purpose');
        $this->assertSame('transfer', $this->body($transaction)['type']);
        $this->assertSame('withdrawal', $this->body($transaction, self::CARD_REGEX)['type']);
    }

    public function test_non_matching_transfer_is_still_detected()
    {
        $transaction = $this->card_payment(self::OWN_IBAN, 'TRANSFER', 'rent');
        $this->assertSame('transfer', $this->body($transaction, self::CARD_REGEX)['type']);
    }

    public function test_invalid_regex_raises_an_error()
    {
        $transaction = $this->card_payment(self::PROCESSOR_IBAN, 'KARTENZAHLUNG', 'purpose');
        $this->expectException(Exception::class);
        $this->body($transaction, '/missing delimiter');
    }

    public function test_must_ignore_counterparty_iban()
    {
        $transaction = $this->card_payment(self::PROCESSOR_IBAN, 'KARTENZAHLUNG', 'purpose');
        $this->assertFalse(TransactionsToFireflySender::must_ignore_counterparty_iban($transaction, ""));
        $this->assertTrue(TransactionsToFireflySender::must_ignore_counterparty_iban($transaction, self::CARD_REGEX));
        $this->assertFalse(TransactionsToFireflySender::must_ignore_counterparty_iban($transaction, '/nomatch/'));
    }

    public function test_configuration_defaults_to_an_empty_regex()
    {
        $file = $this->write_configuration(array());
        $this->assertSame("", ConfigurationFactory::load_from_file($file)->ignore_counterparty_iban_regex);
        unlink($file);
    }

    public function test_configuration_reads_the_regex()
    {
        $file = $this->write_configuration(array('ignore_counterparty_iban_regex' => self::CARD_REGEX));
        $this->assertSame(self::CARD_REGEX, ConfigurationFactory::load_from_file($file)->ignore_counterparty_iban_regex);
        unlink($file);
    }

    private function write_configuration(array $extra): string
    {
        $file = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($file, json_encode(array_merge(array(
            'bank_username' => 'u', 'bank_password' => 'p', 'bank_url' => 'https://example.invalid',
            'bank_code' => '00000000', 'bank_2fa' => '900', 'firefly_url' => 'https://example.invalid',
            'firefly_access_token' => 't', 'skip_transaction_review' => 'false',
            'description_regex_match' => '', 'description_regex_replace' => '',
        ), $extra)));
        return $file;
    }
}
