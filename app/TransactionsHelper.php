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
     */
    public function __construct(string $firefly_url, string $firefly_access_token, 
                                int $firefly_account_id)
    {
        $this->firefly_url          = $firefly_url;
        $this->firefly_access_token = $firefly_access_token;
        $this->firefly_account_id   = $firefly_account_id;

        $firefly_transactions_request = new GetTransactionsByAccountRequest($this->firefly_url, $this->firefly_access_token);
        $firefly_transactions_request->setId($this->firefly_account_id);
        $firefly_transactions_request->setFilter((new \DateTime('now - 1 month'))->format('Y-m-d'), (new \DateTime('now'))->format('Y-m-d'), 'all');
        $this->firefly_transactions = $firefly_transactions_request->get();
    }

    public function get_last_transaction()
    {
        if ($this->firefly_transactions->count() > 0) {
            if ($this->firefly_transactions->valid()) {
                $transaction_group = $this->firefly_transactions->current();
                return current($transaction_group->transactions);
            }
        }
    }
        
    private $firefly_url;
    private $firefly_access_token;
    private $firefly_account_id;
    private $firefly_transactions;
}
