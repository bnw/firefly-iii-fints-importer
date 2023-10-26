<?php

namespace App;

use App\GetTransactionsByAccountRequest;

class TransactionsHelper
{
    /**
     * TransactionsHelper constructor.
     * @param $firefly_url string
     * @param $firefly_access_token string
     * @param int $firefly_account_id
     * @param string $max_date
     */
    public function __construct(string $firefly_url, 
                                string $firefly_access_token, 
                                int $firefly_account_id,
                                string $max_date = 'now - 1 month')
    {
        $this->firefly_url          = $firefly_url;
        $this->firefly_access_token = $firefly_access_token;
        $this->firefly_account_id   = $firefly_account_id;

        $firefly_transactions_request = new GetTransactionsByAccountRequest($this->firefly_url, $this->firefly_access_token);
        $firefly_transactions_request->setId($this->firefly_account_id);
        $firefly_transactions_request->setFilter((new \DateTime($max_date))->format('Y-m-d'), (new \DateTime('now'))->format('Y-m-d'), 'all');
        $this->firefly_transactions = $firefly_transactions_request->get();
    }

    public function get_last_transaction(bool $disregard_transfer = true)
    {
        $last_transaction = null;
        for ($i = 1; $i <= $this->firefly_transactions->count(); $i++) {
            if ($this->firefly_transactions->valid()) {
                foreach($this->firefly_transactions->current()->transactions as $transaction) {
                    if ($disregard_transfer and $transaction->type == "transfer") {
                        continue;
                    }
                    if (is_null($last_transaction) or $transaction->date > $last_transaction->date) {
                        $last_transaction = $transaction;
                    }
                }
            }
            $this->firefly_transactions->next();
        }
        return $last_transaction;
    }
        
    private $firefly_url;
    private $firefly_access_token;
    private $firefly_account_id;
    private $firefly_transactions;
}
