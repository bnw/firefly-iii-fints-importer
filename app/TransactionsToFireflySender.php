<?php

namespace App;

use App\Logger;
use Fhp\Model\StatementOfAccount\Transaction;
use GrumpyDictator\FFIIIApiSupport\Model\TransactionType;
use GrumpyDictator\FFIIIApiSupport\Request\PostTransactionRequest;
use GrumpyDictator\FFIIIApiSupport\Response\PostTransactionResponse;
use GrumpyDictator\FFIIIApiSupport\Response\ValidationErrorResponse;
use GrumpyDictator\FFIIIApiSupport\Request\GetAccountsRequest;
use GrumpyDictator\FFIIIApiSupport\Response\GetAccountsResponse;

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
        string $regex_match,
        string $regex_replace,
        array $regex_rules = []
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

        // Get initial description from transaction
        $description = $transaction->getMainDescription() ?: $transaction->getBookingText() ?: $transaction->getDescription1() ?: "";

        // Apply transformation rules
        if (!empty($description)) {
            
            // Priority: regex_match/replace (legacy) takes precedence if provided, 
            // otherwise use the new multiple rules array.
            if (!empty($regex_match) && !empty($regex_replace)) {
                $activeRules = [['from' => $regex_match, 'to' => $regex_replace]];
            } else {
                $activeRules = $regex_rules;
            }
            
            foreach ($activeRules as $rule) {
                $from = $rule['from'] ?? '';
                $to   = $rule['to'] ?? '';
                
                if ($from === '') continue;

                // Check if it's a Regex (starts/ends with same non-alphanumeric delimiter)
                $is_regex = preg_match('/^([^\w\s]).*?\1[imsxuADU]*$/', $from);

                if ($is_regex) {
                    $result = @preg_replace($from, $to, $description);
                    if ($result !== null) {
                        if ($result !== $description) {
                            Logger::debug("Regex replacement applied: '$description' -> '$result'");
                            $description = $result;
                        }
                    } elseif (preg_last_error() !== PREG_NO_ERROR) {
                        Logger::error("Invalid Regex pattern or error: '$from' (Error code: " . preg_last_error() . ")");
                    }
                } else {
                    $old_description = $description;
                    $description = str_ireplace($from, $to, $description);
                    if ($old_description !== $description) {
                        Logger::debug("String replacement applied: '$old_description' -> '$description'");
                    }
                }

                if (empty($description)) break;
            }
        }

        // Final safety check
        if ($description === null) {
             throw new \Exception("Error in description transformation!");
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

    public function send_transactions()
    {
        $result = array();
        foreach ($this->transactions as $transaction) {
            $request = new PostTransactionRequest($this->firefly_url, $this->firefly_access_token);

            $request->setBody(
                self::transform_transaction_to_firefly_request_body($transaction, $this->firefly_account_id, $this->firefly_accounts, $this->regex_match, $this->regex_replace)
            );

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
