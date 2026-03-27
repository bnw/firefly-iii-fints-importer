<?php
namespace App\StepFunction;

use App\FinTsFactory;
use App\Logger;
use App\Step;
use App\TanHandler;
use Fhp\Model\StatementOfAccount\Transaction;
use Fhp\Protocol\UnexpectedResponseException;

// ExtTransactions to show if a transaction is excluded in frontend
class ExtTransaction extends Transaction {
    protected string $excluded;

    public function __construct(Transaction $base) {
        // Kopiere alle Eigenschaften vom Original ins neue Objekt
        foreach (get_object_vars($base) as $key => $value) {
            $this->$key = $value;
        }
    }

    public function isExcluded(): bool
    {
        if ($this->excluded) {
            return true;
        } else {
            return false;
        }
    }

    public function getExcluded(): string
    {
        return $this->excluded;
    }

    public function setExcluded(string $excluded): static
    {
        $this->excluded = $excluded;
        return $this;
    }
}

function GetImportData()
{
    global $request, $session, $twig, $fin_ts, $accounts, $automate_without_js;

    $fin_ts = FinTsFactory::create_from_session($session);

    $accounts = unserialize($session->get('accounts'));
    $current_step = new Step($request->request->get("step", Step::STEP0_SETUP));

    // Determine which format to use
    // Use format detected during login, with exception-based fallback as backup
    $use_mt940_fallback = $session->get('use_mt940_format', false);
    if ($use_mt940_fallback) {
        $statement_format = 'mt940';
    } else {
        $statement_format = $session->get('statement_format', 'camt');
    }
    Logger::info("Using statement format: {$statement_format}");

    $exclude_regex_matchers = $session->get('exclude_regex_matchers', []);
    $exclude_ibans = $session->get('exclude_ibans', []);

    try {
        $soa_handler = new TanHandler(
            function () use ($statement_format) {
                global $fin_ts, $request, $accounts, $session;
                assert($request->request->has('bank_account'));
                assert($request->request->has('firefly_account'));
                assert($request->request->has('date_from'));
                assert($request->request->has('date_to'));
                $bank_account = $accounts[intval($request->request->get('bank_account'))];
                $from = new \DateTime($request->request->get('date_from'));
                $to = new \DateTime($request->request->get('date_to'));
                $session->set('firefly_account', $request->request->get('firefly_account'));

                if ($statement_format === 'mt940') {
                    $get_statement = \Fhp\Action\GetStatementOfAccount::create($bank_account, $from, $to);
                } else {
                    $get_statement = \Fhp\Action\GetStatementOfAccountXML::create($bank_account, $from, $to);
                }

                $fin_ts->execute($get_statement);
                return $get_statement;
            },
            'soa-' . $statement_format,
            $session,
            $twig,
            $fin_ts,
            $current_step,
            $request
        );

        if ($soa_handler->needs_tan()) {
            $soa_handler->pose_and_render_tan_challenge();
        } else {
            $next_step = Step::STEP5_RUN_IMPORT_BATCHED;
            $transactions = [];
            $finished_action = $soa_handler->get_finished_action();

            if ($statement_format === 'mt940') {
                Logger::info("Parsing MT940 format");
                /** @var \Fhp\Model\StatementOfAccount\StatementOfAccount $soa */
                $soa = $finished_action->getStatement();
                $transactions = \App\StatementOfAccountHelper::get_all_transactions($soa);
            } else {
                Logger::info("Parsing CAMT XML format");
                $camt_xml_array = $finished_action->getBookedXML();
                Logger::trace("CAMT XML raw data received from bank:" . print_r($camt_xml_array, true));

                Logger::trace("CAMT XML array count: " . count($camt_xml_array));
                if (!empty($camt_xml_array) && isset($camt_xml_array[0])) {
                    Logger::trace("First CAMT XML length: " . strlen($camt_xml_array[0]));
                }

                foreach ($camt_xml_array as $camt_xml) {
                    $camt_transactions = \App\StatementOfAccountHelper::parse_camt_xml($camt_xml);
                    $transactions = array_merge($transactions, $camt_transactions);
                }
            }

            // Handle empty results gracefully
            if (empty($transactions)) {
                $date_from = $request->request->get('date_from', 'unknown');
                $date_to = $request->request->get('date_to', 'unknown');
                Logger::info("No transactions found for date range: {$date_from} to {$date_to}");
            }

            $ext_transactions = [];
            foreach ($transactions as $key => $transaction) {
                $ext_transaction = new ExtTransaction($transaction);
                $ext_transaction->setExcluded(False);

                if ($ext_transaction->isExcluded() == false) {
                    $acc_number = $transaction->getAccountNumber();
                    foreach ($exclude_ibans as $exclude_iban) {
                        if ($exclude_iban === $acc_number) {
                            Logger::trace("Exclude IBAN found: {$exclude_iban}. This transaction will not be sent");
                            unset($transactions[$key]);
                            $ext_transaction->setExcluded("IBAN: " . $exclude_iban);
                            break;
                        }
                    }
                }

                if ($ext_transaction->isExcluded() == false) {
                    $desc = $transaction->getMainDescription();
                    foreach ($exclude_regex_matchers as $exclude_regex_matcher) {
                        if (preg_match($exclude_regex_matcher, $desc)) {
                            Logger::trace("Exclude match found: {$exclude_regex_matcher} <-> {$desc}. This transaction will not be sent");
                            unset($transactions[$key]);
                            $ext_transaction->setExcluded("Pattern: " . $exclude_regex_matcher);
                            break;
                        }
                    }
                }
                $ext_transactions[] = $ext_transaction;
            }

            // Clear fallback flag on success
            $session->remove('use_mt940_format');

            $session->set('transactions_to_import', serialize($transactions));
            $session->set('num_transactions_processed', 0);
            $session->set('import_messages', serialize(array()));

            $fin_ts->close();

            if ($automate_without_js) {
                $session->set('persistedFints', $fin_ts->persist());
                return $next_step;
            }

            echo $twig->render(
                'show-transactions.twig',
                array(
                    'transactions' => $ext_transactions,
                    'next_step' => $next_step,
                    'skip_transaction_review' => $session->get('skip_transaction_review')
                )
            );
        }
    } catch (UnexpectedResponseException $e) {
        // Check if this is a "format not supported" error
        $message = $e->getMessage();
        Logger::debug("Caught UnexpectedResponseException: " . $message);

        if (strpos($message, 'HICAZS') !== false && !$use_mt940_fallback) {
            // CAMT not supported, try MT940
            Logger::info("CAMT XML (HICAZS) not supported by bank, falling back to MT940");
            $session->set('use_mt940_format', true);
            $session->set('persistedFints', $fin_ts->persist());
            return Step::STEP4_GET_IMPORT_DATA;
        } elseif (strpos($message, 'HIKAZS') !== false && $use_mt940_fallback) {
            // MT940 also not supported - show error
            Logger::error("Neither CAMT nor MT940 format is supported by this bank");
            $session->remove('use_mt940_format');
            echo $twig->render(
                'error.twig',
                array(
                    'error_header' => 'Statement Format Not Supported',
                    'error_message' => "Your bank does not support any statement format implemented in this application.\n\n" .
                                      "Tried formats:\n" .
                                      "- CAMT XML (HICAZS): Not supported\n" .
                                      "- MT940 (HIKAZS): Not supported\n\n" .
                                      "Please contact the developer if you believe this is an error."
                )
            );
            $fin_ts->close();
            $session->set('persistedFints', $fin_ts->persist());
            return Step::DONE;
        } else {
            // Different error, re-throw
            throw $e;
        }
    }

    $session->set('persistedFints', $fin_ts->persist());
    return Step::DONE;
}
