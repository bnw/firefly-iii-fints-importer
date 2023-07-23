<?php
namespace App\StepFunction;

use App\TransactionsToFireflySender;
use App\Step;
use Symfony\Component\HttpFoundation\Session\Session;

$num_transactions_to_import_at_once = 5;


function RunImport($transactions)
{
    global $session, $num_transactions_to_import_at_once;

    $sender = new TransactionsToFireflySender(
        $transactions,
        $session->get('firefly_url'),
        $session->get('firefly_access_token'),
        $session->get('firefly_account'),
        $session->get('description_regex_match', ""),
        $session->get('description_regex_replace', "")
    );
    $result = $sender->send_transactions();
    if (is_array($result)) {
        return $result;
    }
    return array();
}

function RunImportStep($transactions, $start_index)
{
    global $session, $num_transactions_to_import_at_once;
    
    $transactions_to_process_now = array_slice($transactions, $start_index, $num_transactions_to_import_at_once);
    $result = RunImport($transactions_to_process_now);
    return array($result, count($transactions_to_process_now));
}

function RunImportWithJS()
{
    global $session, $twig, $num_transactions_to_import_at_once;

    assert($session->has('transactions_to_import'));
    assert($session->has('num_transactions_processed'));
    assert($session->has('import_messages'));
    assert($session->has('firefly_account'));
    $transactions                = unserialize($session->get('transactions_to_import'));
    $num_transactions_processed  = $session->get('num_transactions_processed');
    $import_messages             = unserialize($session->get('import_messages'));
    if ($num_transactions_processed >= count($transactions)) {
        echo $twig->render(
            'done.twig',
            array(
                'import_messages' => $import_messages,
                'total_num_transactions' => count($transactions)
            )
        );
        $session->invalidate();
    } else {
        list($result,$transaction_processed_step_count) = RunImportStep($transactions, $num_transactions_processed);
        $num_transactions_processed += $transaction_processed_step_count;
        $import_messages = array_merge($import_messages, $result);

        $session->set('num_transactions_processed', $num_transactions_processed);
        $session->set('import_messages', serialize($import_messages));

        echo $twig->render(
            'import-progress-batched.twig',
            array(
                'num_transactions_processed' => $num_transactions_processed,
                'total_num_transactions' => count($transactions),
                'next_step' => Step::STEP5_RUN_IMPORT_BATCHED
            )
        );
    }
    return Step::DONE;
}

function RunImportWithoutJS()
{
    global $session, $twig;

    assert($session->has('transactions_to_import'));
    assert($session->has('firefly_account'));
    $transactions = unserialize($session->get('transactions_to_import'));

    if (empty($transactions)) {
        $import_messages = ['No transactions to import.'];
    } else {
        $import_messages = [];
        $num_transactions_processed = 0;
        
        while ($num_transactions_processed < count($transactions))
        {
            list($result,$transaction_processed_step_count) = RunImportStep($transactions, $num_transactions_processed);
            $num_transactions_processed += $transaction_processed_step_count;
            $import_messages = array_merge($import_messages, $result);
        }
    }
    echo $twig->render(
        'done.twig',
        array(
            'import_messages' => $import_messages,
            'total_num_transactions' => count($transactions)
        )
    );
    $session->invalidate();
    return Step::DONE;
}

function RunImportBatched()
{
    global $automate_without_js;

    if ($automate_without_js) {
        return RunImportWithoutJS();
    } else {
        return RunImportWithJS();
    }
}
