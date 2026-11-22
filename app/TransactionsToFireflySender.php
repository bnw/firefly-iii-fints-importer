<?php

namespace App;

use App\Logger;
use Fhp\Model\StatementOfAccount\Transaction;
use GrumpyDictator\FFIIIApiSupport\Model\TransactionType;
use GrumpyDictator\FFIIIApiSupport\Request\PostTransactionRequest;
use GrumpyDictator\FFIIIApiSupport\Response\PostTransactionResponse;
use GrumpyDictator\FFIIIApiSupport\Response\ValidationErrorResponse;
use GrumpyDictator\FFIIIApiSupport\Request\GetAccountsRequest;
use GrumpyDictator\FFIIIApiSupport\Request\GetTransactionsRequest;
use GrumpyDictator\FFIIIApiSupport\Response\GetAccountsResponse;
use GrumpyDictator\FFIIIApiSupport\Response\GetTransactionsResponse;

class TransactionsToFireflySender
{
    /**
     * TransactionsToFireflySender constructor.
     * @param $transactions Transaction[]
     * @param $firefly_url string
     * @param $firefly_access_token string
     * @param int $firefly_account_id
     */
    public function __construct(array $transactions, string $firefly_url, string $firefly_access_token, 
                                int $firefly_account_id,
                                string $regex_match, string $regex_replace)
    {
        $this->transactions         = $transactions;
        $this->firefly_url          = $firefly_url;
        $this->firefly_access_token = $firefly_access_token;
        $this->firefly_account_id   = $firefly_account_id;
        $this->regex_match          = $regex_match;
        $this->regex_replace        = $regex_replace;

        $firefly_accounts_request = new GetAccountsRequest($this->firefly_url, $this->firefly_access_token);
        $firefly_accounts_request->setType(GetAccountsRequest::ASSET);
        $this->firefly_accounts = $firefly_accounts_request->get();
    }

    public static function get_iban(Transaction $transaction)
    {
        $iban_helper = new \PHP_IBAN\IBAN;
        if ($iban_helper->Verify($transaction->getAccountNumber())) {
            return $transaction->getAccountNumber();
        } else {
            return null;
        }
    }

    public static function transform_transaction_to_firefly_request_body(
        Transaction $transaction,
        int $firefly_account_id,
        GetAccountsResponse $firefly_accounts,
        string $regex_match, string $regex_replace
    )
    {
        $debitOrCredit = $transaction->getCreditDebit();
        $amount        = $transaction->getAmount();
        $source        = array('id' => $firefly_account_id);
        $destination   = array('iban' => self::get_iban($transaction), 'name' => $transaction->getName());

        Logger::trace("Transfer detection - counterparty IBAN: " . ($destination['iban'] ?? 'null') . ", name: " . ($destination['name'] ?? 'null') . ", source account ID: $firefly_account_id");

        $firefly_accounts->rewind();
        for ($acc = $firefly_accounts->current(); $firefly_accounts->valid(); $acc = $firefly_accounts->current()) {
            // Match counterparty IBAN, but exclude the source account to avoid source=destination
            if ($destination['iban'] !== null && $acc->iban == $destination['iban'] && $acc->id != $firefly_account_id) {
                break;
            }
            $firefly_accounts->next();
        }
        if ($firefly_accounts->valid()) {
            Logger::trace("Transfer detected: matched account {$acc->name} (ID: {$acc->id}) with IBAN {$acc->iban}");
            $destination = array('id' => $acc->id);
            $type = TransactionType::TRANSFER;

            if ($debitOrCredit !== Transaction::CD_DEBIT) {
                [$source, $destination] = [$destination, $source];
            }
        } else {
            Logger::trace("No transfer match found - treating as " . ($debitOrCredit !== Transaction::CD_CREDIT ? "withdrawal" : "deposit"));
            if ($debitOrCredit !== Transaction::CD_CREDIT) {
                $type = TransactionType::WITHDRAWAL;
            } else {
                $type = TransactionType::DEPOSIT;
                [$source, $destination] = [$destination, $source];
            }
        }
        $firefly_accounts->rewind();

        $description = $transaction->getMainDescription();
        if ($description == "") {
            $description = $transaction->getBookingText();
        }

        if ($description == "") {
            $description = $transaction->getDescription1();
        }

        if (!empty($regex_match) && !empty($regex_replace) && !empty($description)) {
            $result = preg_replace($regex_match, $regex_replace, $description);
            if ($result !== null) {
                $description = $result;
            }
        }

        if($description == null) {
            throw new \Exception("Error in regular expression!\nMatch expression {$regex_match}\nReplace expression {$regex_replace}");
        }

        // Get currency code from structured description (set by CAMT parser)
        $structuredDesc = $transaction->getStructuredDescription();
        $currencyCode = $structuredDesc['CURR'] ?? null;

        // Build transaction array and filter out null values
        $transactionData = array_filter([
            'type' => $type,
            'date' => $transaction->getValutaDate()->format('Y-m-d'),
            'amount' => $amount,
            'description' => $description,
            'currency_code' => $currencyCode,
            'source_name' => $source['name'] ?? null,
            'source_id' => $source['id'] ?? null,
            'source_iban' => $source['iban'] ?? null,
            'destination_name' => $destination['name'] ?? null,
            'destination_id' => $destination['id'] ?? null,
            'destination_iban' => $destination['iban'] ?? null,
            'sepa_ct_id' => $transaction->getEndToEndID() ?: null,
            'notes' => $structuredDesc['ABWA'] ?? $destination['name'] ?? null,
        ], fn($value) => $value !== null);

        return array(
            'apply_rules' => true,
            'error_if_duplicate_hash' => true,
            'transactions' => array($transactionData)
        );
    }

    /**
     * Key identifying "the same" transfer independent of its description: valuta date, amount, source and
     * destination account. Returns null for anything that is not a transfer between two known accounts.
     */
    public static function transfer_key(array $transactionData): ?string
    {
        if (($transactionData['type'] ?? null) !== TransactionType::TRANSFER
            || !isset($transactionData['source_id'], $transactionData['destination_id'], $transactionData['date'])) {
            return null;
        }
        return self::build_transfer_key(
            $transactionData['date'], $transactionData['amount'],
            (int)$transactionData['source_id'], (int)$transactionData['destination_id']
        );
    }

    private static function build_transfer_key(string $date, $amount, int $source_id, int $destination_id): string
    {
        $normalized_amount = rtrim(rtrim(number_format(abs((float)$amount), 8, '.', ''), '0'), '.');
        return substr($date, 0, 10) . '|' . $normalized_amount . '|' . $source_id . '|' . $destination_id;
    }

    /**
     * Counts the transfers Firefly III already knows, per transfer_key().
     *
     * @return int[] key => number of existing transfers
     */
    public static function count_existing_transfers(GetTransactionsResponse $existing): array
    {
        $counts = array();
        foreach ($existing as $group) {
            foreach ($group->transactions as $transaction) {
                if ($transaction->type !== TransactionType::TRANSFER) {
                    continue;
                }
                $key = self::build_transfer_key(
                    $transaction->date, $transaction->amount, $transaction->sourceId, $transaction->destinationId
                );
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * Both accounts of an internal transfer are imported separately, and each import produces a transfer for
     * it. Their descriptions differ (the debit side carries the purpose, the credit side often only the
     * booking text), so Firefly III's hash based duplicate detection does not recognise them as one.
     *
     * Removes the transfers Firefly III already has, comparing date, amount and both accounts instead of the
     * description. This counts instead of just skipping: if a statement contains the same transfer twice
     * (two genuine transfers of the same amount on the same day), both are kept unless Firefly III already has
     * them, so a repeated import or the counterpart account's import adds nothing, but real repeats survive.
     *
     * @param array[] $request_bodies bodies as built by transform_transaction_to_firefly_request_body()
     * @param int[]   $existing_counts result of count_existing_transfers()
     * @return array[] the bodies that still have to be posted, in their original order
     */
    public static function drop_existing_transfers(array $request_bodies, array $existing_counts): array
    {
        $remaining = $existing_counts;
        $result    = array();
        foreach ($request_bodies as $body) {
            $key = self::transfer_key($body['transactions'][0]);
            if ($key !== null && ($remaining[$key] ?? 0) > 0) {
                $remaining[$key]--;
                Logger::trace("Skipping transfer already present in Firefly III: $key");
                continue;
            }
            $result[] = $body;
        }
        return $result;
    }

    private function remove_transfers_already_in_firefly(array $request_bodies): array
    {
        $dates = array();
        foreach ($request_bodies as $body) {
            if (self::transfer_key($body['transactions'][0]) !== null) {
                $dates[] = substr($body['transactions'][0]['date'], 0, 10);
            }
        }
        if (count($dates) === 0) {
            return $request_bodies;
        }

        try {
            $request = new GetTransactionsRequest($this->firefly_url, $this->firefly_access_token);
            $request->setFilter(min($dates), max($dates), 'transfers');
            $existing = $request->get();
        } catch (\Exception $e) {
            // Fall back to the previous behaviour rather than failing the whole import.
            Logger::trace("Could not load existing transfers, importing without transfer de-duplication: " . $e->getMessage());
            return $request_bodies;
        }

        return self::drop_existing_transfers($request_bodies, self::count_existing_transfers($existing));
    }

    public function send_transactions()
    {
        $request_bodies = array();
        foreach ($this->transactions as $index => $transaction) {
            $request_bodies[$index] = self::transform_transaction_to_firefly_request_body(
                $transaction, $this->firefly_account_id, $this->firefly_accounts, $this->regex_match, $this->regex_replace
            );
        }
        $to_send = $this->remove_transfers_already_in_firefly($request_bodies);

        $result = array();
        foreach ($to_send as $index => $body) {
            $transaction = $this->transactions[$index];
            $request = new PostTransactionRequest($this->firefly_url, $this->firefly_access_token);

            $request->setBody($body);

            $response = $request->post();
            if ($response instanceof ValidationErrorResponse) {
                $errors   = $response->errors->all();
                $errors[] = "Firefly III request: " . json_encode($request->getBody());
                $errors[] = "Transaction data: " . print_r($transaction, true);
                $result[] = array('transaction' => $transaction, 'messages' => $errors);
            } else if ($response instanceof PostTransactionResponse) {
                //everything went fine :)
            } else {
                throw new \Exception('Import went wrong');
            }
        }
        return $result;
    }

    private $transactions;
    private $firefly_url;
    private $firefly_access_token;
    private $firefly_account_id;
    private $firefly_accounts;
    private $regex_match;
    private $regex_replace;
}
