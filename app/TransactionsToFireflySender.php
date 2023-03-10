<?php

namespace App;

use Fhp\Model\StatementOfAccount\Transaction;
use GrumpyDictator\FFIIIApiSupport\Model\TransactionType;
use GrumpyDictator\FFIIIApiSupport\Request\PostTransactionRequest;
use GrumpyDictator\FFIIIApiSupport\Response\PostTransactionResponse;
use GrumpyDictator\FFIIIApiSupport\Response\ValidationErrorResponse;

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
                                int $firefly_account_id, string $regex_match, string $regex_replace)
    {
        $this->transactions         = $transactions;
        $this->firefly_url          = $firefly_url;
        $this->firefly_access_token = $firefly_access_token;
        $this->firefly_account_id   = $firefly_account_id;
        $this->regex_match          = $regex_match;
        $this->regex_replace        = $regex_replace;
    }

    public static function get_iban(Transaction $transaction)
    {
        $iban_helper = new \IBAN;
        if ($iban_helper->Verify($transaction->getAccountNumber())) {
            return $transaction->getAccountNumber();
        } else {
            return null;
        }
    }

    public static function transform_transaction_to_firefly_request_body(
        Transaction $transaction,
        int $firefly_account_id,
        string $regex_match, string $regex_replace
    )
    {
        $debitOrCredit = $transaction->getCreditDebit();
        $amount        = $transaction->getAmount();
        $source        = array('id' => $firefly_account_id);
        $destination   = array('iban' => self::get_iban($transaction), 'name' => $transaction->getName());

        if ($debitOrCredit !== Transaction::CD_CREDIT) {
            $type = TransactionType::WITHDRAWAL;
        } else {
            $type = TransactionType::DEPOSIT;
            [$source, $destination] = [$destination, $source];
        }

        $description = $transaction->getMainDescription();
        if ($description == "") {
            $description = $transaction->getBookingText();
        }

        if($regex_match !== "" && $regex_replace !== "") {
            $description = preg_replace($regex_match, $regex_replace, $description);
        }

        return array(
            'apply_rules' => true,
            'error_if_duplicate_hash' => true,
            'transactions' => array(
                array(
                    'type' => $type,
                    'date' => $transaction->getValutaDate()->format('Y-m-d'),
                    'amount' => $amount,
                    'description' => $description,
                    'source_name' => $source['name'] ?? null,
                    'source_id' => $source['id'] ?? null,
                    'source_iban' => $source['iban'] ?? null,
                    'destination_name' => $destination['name'] ?? null,
                    'destination_id' => $destination['id'] ?? null,
                    'destination_iban' => $destination['iban'] ?? null,
                    'sepa_ct_id' => $transaction->getEndToEndID() ?? null,
                    'notes' => $transaction->getStructuredDescription()['ABWA'] ?? null,
                )
            )
        );
    }

    public function send_transactions()
    {
        $result = array();
        foreach ($this->transactions as $transaction) {
            $request = new PostTransactionRequest($this->firefly_url, $this->firefly_access_token);

            $request->setBody(
                self::transform_transaction_to_firefly_request_body($transaction, $this->firefly_account_id, $this->regex_match, $this->regex_replace)
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
    private $regex_match;
    private $regex_replace;
}