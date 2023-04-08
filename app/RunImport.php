<?php
namespace App\StepFunction;

use App\TransactionsToFireflySender;
use App\Step;
use Symfony\Component\HttpFoundation\Session\Session;

function RunImport()
{
    global $session, $twig;

    $num_transactions_to_import_at_once = 5;
    assert($session->has('transactions_to_import'));
    assert($session->has('num_transactions_processed'));
    assert($session->has('import_messages'));
    assert($session->has('firefly_account'));
    $transactions                = unserialize($session->get('transactions_to_import'));
    $num_transactions_processed  = $session->get('num_transactions_processed');
    $import_messages             = unserialize($session->get('import_messages'));
    $transactions_to_process_now = array_slice($transactions, $num_transactions_processed, $num_transactions_to_import_at_once);
    if (empty($transactions_to_process_now)) {
        echo $twig->render(
            'done.twig',
            array(
                'import_messages' => $import_messages,
                'total_num_transactions' => count($transactions)
            )
        );
        $session->invalidate();
    } else {
        $num_transactions_processed += count($transactions_to_process_now);
        $sender                     = new TransactionsToFireflySender(
            $transactions_to_process_now,
            $session->get('firefly_url'),
            $session->get('firefly_access_token'),
            $session->get('firefly_account'),
            $session->get('description_regex_match', ""),
            $session->get('description_regex_replace', "")
        );
        $result                     = $sender->send_transactions();
        if (is_array($result)) {
            $import_messages = array_merge($import_messages, $result);
        }

        $session->set('num_transactions_processed', $num_transactions_processed);
        $session->set('import_messages', serialize($import_messages));

        echo $twig->render(
            'import-progress.twig',
            array(
                'num_transactions_processed' => $num_transactions_processed,
                'total_num_transactions' => count($transactions),
                'next_step' => Step::STEP5_RUN_IMPORT
            )
        );
    }
}