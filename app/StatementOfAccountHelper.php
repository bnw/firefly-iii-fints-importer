<?php

namespace App;

use Fhp\Model\StatementOfAccount\Transaction;

class StatementOfAccountHelper
{
    /** @return Transaction[] */
    public static function get_all_transactions(\Fhp\Model\StatementOfAccount\StatementOfAccount $soa){
        $transactions = array();
        foreach($soa->getStatements() as $statement){
            $transactions = array_merge($transactions, $statement->getTransactions());
        }
        return $transactions;
    }
}