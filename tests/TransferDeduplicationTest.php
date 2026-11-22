<?php

use App\TransactionsToFireflySender;
use GrumpyDictator\FFIIIApiSupport\Model\TransactionType;
use GrumpyDictator\FFIIIApiSupport\Response\GetTransactionsResponse;
use PHPUnit\Framework\TestCase;

final class TransferDeduplicationTest extends TestCase
{
    private const CHECKING = 10;
    private const SAVINGS  = 20;

    private function body(string $type, string $description, string $date = '2026-11-20', float $amount = 104.09,
                          int $source = self::CHECKING, int $destination = self::SAVINGS): array
    {
        return array(
            'apply_rules'             => true,
            'error_if_duplicate_hash' => true,
            'transactions'            => array(array(
                'type'           => $type,
                'date'           => $date,
                'amount'         => $amount,
                'description'    => $description,
                'source_id'      => $source,
                'destination_id' => $destination,
            )),
        );
    }

    private function existing(array $transfers): GetTransactionsResponse
    {
        $groups = array();
        foreach ($transfers as $i => [$date, $amount, $source, $destination]) {
            $groups[] = array(
                'id'         => 100 + $i,
                'attributes' => array(
                    'group_title'  => null,
                    'transactions' => array(array(
                        'transaction_journal_id'          => 100 + $i,
                        'description'                     => 'whatever the other side wrote',
                        'destination_name'                => 'dest',
                        'destination_id'                  => (string)$destination,
                        'source_name'                     => 'src',
                        'source_id'                       => (string)$source,
                        'type'                            => 'transfer',
                        'date'                            => $date . 'T00:00:00+01:00',
                        'notes'                           => null,
                        'amount'                          => $amount,
                        'currency_code'                   => 'EUR',
                        'currency_id'                     => '1',
                        'currency_decimal_places'         => 2,
                        'foreign_currency_id'             => null,
                        'foreign_currency_code'           => null,
                        'foreign_currency_decimal_places' => null,
                        'foreign_amount'                  => null,
                        'category_id'                     => null,
                        'budget_id'                       => null,
                    )),
                ),
            );
        }
        return new GetTransactionsResponse($groups);
    }

    private function descriptions(array $bodies): array
    {
        return array_map(fn($b) => $b['transactions'][0]['description'], $bodies);
    }

    private function counts(array $transfers): array
    {
        return TransactionsToFireflySender::count_existing_transfers($this->existing($transfers));
    }

    public function test_transfer_key_ignores_description_and_amount_formatting()
    {
        $a = TransactionsToFireflySender::transfer_key($this->body(TransactionType::TRANSFER, 'DATUM 19.11.2026')['transactions'][0]);
        $b = TransactionsToFireflySender::transfer_key($this->body(TransactionType::TRANSFER, 'UEBERTRAG', '2026-11-20', 104.090)['transactions'][0]);
        $this->assertNotNull($a);
        $this->assertSame($a, $b);
    }

    public function test_transfer_key_is_null_for_non_transfers()
    {
        $withdrawal = $this->body(TransactionType::WITHDRAWAL, 'x')['transactions'][0];
        $this->assertNull(TransactionsToFireflySender::transfer_key($withdrawal));
    }

    public function test_existing_transfers_are_counted_per_key()
    {
        $counts = $this->counts(array(
            array('2026-11-20', '104.09000000', self::CHECKING, self::SAVINGS),
            array('2026-11-20', '104.09', self::CHECKING, self::SAVINGS),
            array('2026-11-21', '5.00', self::CHECKING, self::SAVINGS),
        ));
        $this->assertSame(2, $counts['2026-11-20|104.09|10|20']);
        $this->assertSame(1, $counts['2026-11-21|5|10|20']);
    }

    public function test_counterpart_side_of_an_existing_transfer_is_dropped()
    {
        // The debit side was imported earlier; the credit side comes with a different description.
        $existing = $this->counts(array(array('2026-11-20', '104.09', self::CHECKING, self::SAVINGS)));
        $bodies   = array($this->body(TransactionType::TRANSFER, 'UEBERTRAG (GUTSCHRIFT UEBERWEISUNG)'));

        $this->assertSame(array(), TransactionsToFireflySender::drop_existing_transfers($bodies, $existing));
    }

    public function test_new_transfer_is_kept()
    {
        $bodies = array($this->body(TransactionType::TRANSFER, 'DATUM 19.11.2026'));
        $this->assertSame($bodies, TransactionsToFireflySender::drop_existing_transfers($bodies, array()));
    }

    public function test_two_genuine_transfers_survive_when_firefly_has_none()
    {
        $bodies = array(
            $this->body(TransactionType::TRANSFER, 'first'),
            $this->body(TransactionType::TRANSFER, 'second'),
        );
        $this->assertCount(2, TransactionsToFireflySender::drop_existing_transfers($bodies, array()));
    }

    public function test_two_genuine_transfers_are_not_doubled_by_the_counterpart_import()
    {
        $existing = $this->counts(array(
            array('2026-11-20', '104.09', self::CHECKING, self::SAVINGS),
            array('2026-11-20', '104.09', self::CHECKING, self::SAVINGS),
        ));
        $bodies = array(
            $this->body(TransactionType::TRANSFER, 'first'),
            $this->body(TransactionType::TRANSFER, 'second'),
        );
        $this->assertSame(array(), TransactionsToFireflySender::drop_existing_transfers($bodies, $existing));
    }

    public function test_only_the_missing_one_of_two_transfers_is_posted()
    {
        $existing  = $this->counts(array(array('2026-11-20', '104.09', self::CHECKING, self::SAVINGS)));
        $bodies    = array(
            $this->body(TransactionType::TRANSFER, 'first'),
            $this->body(TransactionType::TRANSFER, 'second'),
        );
        $remaining = TransactionsToFireflySender::drop_existing_transfers($bodies, $existing);
        $this->assertSame(array('second'), $this->descriptions($remaining));
    }

    public function test_different_day_amount_or_direction_is_not_a_duplicate()
    {
        $existing = $this->counts(array(array('2026-11-20', '104.09', self::CHECKING, self::SAVINGS)));
        $bodies   = array(
            $this->body(TransactionType::TRANSFER, 'other day', '2026-11-21'),
            $this->body(TransactionType::TRANSFER, 'other amount', '2026-11-20', 104.10),
            $this->body(TransactionType::TRANSFER, 'reverse direction', '2026-11-20', 104.09, self::SAVINGS, self::CHECKING),
        );
        $this->assertCount(3, TransactionsToFireflySender::drop_existing_transfers($bodies, $existing));
    }

    public function test_withdrawals_and_deposits_are_never_dropped()
    {
        $existing = $this->counts(array(array('2026-11-20', '104.09', self::CHECKING, self::SAVINGS)));
        $bodies   = array(
            $this->body(TransactionType::WITHDRAWAL, 'shop'),
            $this->body(TransactionType::DEPOSIT, 'salary'),
        );
        $this->assertCount(2, TransactionsToFireflySender::drop_existing_transfers($bodies, $existing));
    }
}
