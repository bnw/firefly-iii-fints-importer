<?php

namespace App;

use App\Logger;
use Fhp\Model\StatementOfAccount\Transaction;
use Genkgo\Camt\Config;
use Genkgo\Camt\Reader;
use Money\Currencies\ISOCurrencies;

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

    /**
     * Parse CAMT XML and convert to Transaction objects
     * @param string $xml CAMT XML string from GetStatementOfAccountXML
     * @return Transaction[]
     */
    public static function parse_camt_xml(string $xml): array {
        // Validate XML input
        if (empty(trim($xml))) {
            Logger::info("CAMT XML parsing skipped: Empty XML provided");
            return [];
        }

        try {
            // Initialize CAMT reader with default configuration
            $reader = new Reader(Config::getDefault());
            $message = $reader->readString($xml);

            $transactions = [];

            // Iterate through records (statements) and entries (transactions)
            foreach ($message->getRecords() as $record) {
                foreach ($record->getEntries() as $entry) {
                    // Create a new Transaction object
                    $transaction = new Transaction();
                    $transaction->setName('');

                    // Set credit/debit indicator
                    $cdIndicator = $entry->getCreditDebitIndicator();
                    if ($cdIndicator === 'CRDT') {
                        $transaction->setCreditDebit(Transaction::CD_CREDIT);
                    } else {
                        $transaction->setCreditDebit(Transaction::CD_DEBIT);
                    }

                    // Set valuta date (convert DateTimeImmutable to DateTime)
                    $valutaDate = $entry->getValueDate();
                    if ($valutaDate) {
                        $transaction->setValutaDate(\DateTime::createFromImmutable($valutaDate));
                    }

                    // Set booking date as fallback
                    $bookingDate = $entry->getBookingDate();
                    if ($bookingDate && !$valutaDate) {
                        $transaction->setValutaDate(\DateTime::createFromImmutable($bookingDate));
                    }

                    // Set amount (convert Money object to absolute float, direction is in credit_debit field)
                    $amount = $entry->getAmount();
                    // Get currency subunit (decimal places) from ISO currencies
                    $currencies = new ISOCurrencies();
                    $currency = $amount->getCurrency();
                    $fractionDigits = $currencies->subunitFor($currency);
                    $transaction->setAmount(abs((float)($amount->getAmount() / (10 ** $fractionDigits))));

                    // Store currency code for later use (will be added to structuredDescription)
                    $currencyCode = $currency->getCode();

                    // Get transaction details (first detail if multiple exist)
                    $detail = $entry->getTransactionDetail();

                    if ($detail) {
                        // Find the correct counterparty based on credit/debit direction
                        // For credits (incoming): counterparty is Debtor (who sent money)
                        // For debits (outgoing): counterparty is Creditor (who received money)
                        $relatedParty = null;
                        $relatedParties = $detail->getRelatedParties();

                        if (count($relatedParties) > 1) {
                            // Multiple parties - select based on transaction direction
                            foreach ($relatedParties as $party) {
                                $partyType = $party->getRelatedPartyType();
                                if ($cdIndicator === 'CRDT' && $partyType instanceof \Genkgo\Camt\DTO\Debtor) {
                                    // For credits, use the Debtor (sender)
                                    $relatedParty = $party;
                                    break;
                                } elseif ($cdIndicator === 'DBIT' && $partyType instanceof \Genkgo\Camt\DTO\Creditor) {
                                    // For debits, use the Creditor (recipient)
                                    $relatedParty = $party;
                                    break;
                                }
                            }
                        }

                        // Fallback to first party if no specific match found
                        if (!$relatedParty && !empty($relatedParties)) {
                            $relatedParty = $relatedParties[0];
                        }

                        // Set counterparty account number (IBAN)
                        if ($relatedParty && $relatedParty->getAccount()) {
                            $transaction->setAccountNumber($relatedParty->getAccount()->getIdentification());
                        } else {
                            $transaction->setAccountNumber("");
                        }

                        // Set counterparty name
                        if ($relatedParty && $relatedParty->getRelatedPartyType()) {
                            $transaction->setName($relatedParty->getRelatedPartyType()->getName() ?? '');
                        }

                        // Handle single-party transactions where bank only provides one party (yourself)
                        // In these cases, keep the IBAN as-is - transfer detection will handle it
                        // by excluding the source account from matching (preventing source=destination error)
                        if (count($relatedParties) === 1) {
                            $singleParty = $relatedParties[0];
                            $singlePartyType = $singleParty->getRelatedPartyType();

                            if ($cdIndicator === 'DBIT' && $singlePartyType instanceof \Genkgo\Camt\DTO\Debtor) {
                                Logger::trace("DBIT transaction with only Debtor (self) - will be treated as withdrawal");
                            } elseif ($cdIndicator === 'CRDT' && $singlePartyType instanceof \Genkgo\Camt\DTO\Creditor) {
                                Logger::trace("CRDT transaction with only Creditor (self) - will be treated as deposit");
                            }
                        }

                        // Set remittance information and end-to-end ID via structured description
                        $structuredDesc = [];

                        $remittanceInfo = $detail->getRemittanceInformation();
                        if ($remittanceInfo) {
                            $message = $remittanceInfo->getMessage();
                            if ($message) {
                                $structuredDesc['SVWZ'] = $message;
                            }
                        }

                        // Set end-to-end ID (SEPA reference) - stored in EREF key
                        $reference = $detail->getReference();
                        if ($reference) {
                            $endToEndId = $reference->getEndToEndId();
                            if ($endToEndId) {
                                $structuredDesc['EREF'] = $endToEndId;
                            }
                        }

                        // Set ABWA field (used for notes in Firefly III) - use counterparty name as fallback
                        if ($relatedParty && $relatedParty->getRelatedPartyType()) {
                            $partyName = $relatedParty->getRelatedPartyType()->getName();
                            if ($partyName) {
                                $structuredDesc['ABWA'] = $partyName;
                            }
                        }

                        // Set structured description (used by getMainDescription and getEndToEndID)
                        if (!empty($structuredDesc)) {
                            $transaction->setStructuredDescription($structuredDesc);
                        }
                    }

                    // Add currency code to structured description (always set, even without detail)
                    $existingDesc = $transaction->getStructuredDescription() ?? [];
                    $existingDesc['CURR'] = $currencyCode;
                    $transaction->setStructuredDescription($existingDesc);

                    // Set booking text from entry-level additional info
                    $additionalInfo = $entry->getAdditionalInfo();
                    if ($additionalInfo) {
                        $transaction->setBookingText($additionalInfo);
                    }

                    // Set description1 as additional fallback (use counterparty name or additional info)
                    $description1 = '';
                    if ($detail && $detail->getRemittanceInformation()) {
                        // Try to get unstructured remittance info blocks as fallback
                        $unstructuredBlocks = $detail->getRemittanceInformation()->getUnstructuredBlocks();
                        if (!empty($unstructuredBlocks)) {
                            $description1 = $unstructuredBlocks[0]->getMessage();
                        }
                    }
                    if (empty($description1) && $additionalInfo) {
                        $description1 = $additionalInfo;
                    }
                    if (!empty($description1)) {
                        $transaction->setDescription1($description1);
                    }

                    $transactions[] = $transaction;
                }
            }

            return $transactions;

        } catch (\Exception | \Error $e) {
            // Log the error and return empty array to prevent fatal errors
            Logger::error("CAMT XML parsing failed: " . $e->getMessage());
            return [];
        }
    }
}