<?php

namespace App;

use GrumpyDictator\FFIIIApiSupport\Request\GetTransactionsRequest;

/**
 * Class GetSearchTransactionsRequest.
 */
class GetTransactionsByAccountRequest extends GetTransactionsRequest
{
    /**
     * @param int $id
     */
    public function setId(int $id): void
    {
        $this->id = $id;
        $this->setUri(sprintf('accounts/%d/transactions', $id));
    }
    
    private $id;
}
