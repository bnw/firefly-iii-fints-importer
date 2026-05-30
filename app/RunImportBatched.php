<?php
namespace App\StepFunction;

use App\ConfigurationFactory;
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

    // bnw quirk (independent of bnw#236): when GetImportData fetches an empty
    // result (no new transactions in the requested window), these session
    // keys are never set, and the original asserts crash with PHP fatal.
    // Default to "nothing to do" so done.twig still renders + auto-save can
    // still write back the freshly-captured persistence.
    if (!$session->has('transactions_to_import'))    $session->set('transactions_to_import',    serialize(array()));
    if (!$session->has('num_transactions_processed')) $session->set('num_transactions_processed', 0);
    if (!$session->has('import_messages'))            $session->set('import_messages',            serialize(array()));
    // tolerate missing firefly_account too — bnw's ChooseAccount can fail to
    // set it when the configured IBAN doesn't match a discovered account; we
    // still want done.twig to render so the captured persistence is saved.
    if (!$session->has('firefly_account'))            $session->set('firefly_account', null);
    $transactions                = unserialize($session->get('transactions_to_import'));
    $num_transactions_processed  = $session->get('num_transactions_processed');
    $import_messages             = unserialize($session->get('import_messages'));
    if ($num_transactions_processed >= count($transactions)) {
        $fintsPersistence = base64_encode($session->get('persistedFints'));
        $autoSaveStatus   = try_save_persistence($session, $fintsPersistence);
        echo $twig->render(
            'done.twig',
            array(
                'import_messages' => $import_messages,
                'total_num_transactions' => count($transactions),
                'fints_persistence' => $fintsPersistence,
                'auto_save_status' => $autoSaveStatus
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

    // Same defensive defaults as WithJS — bnw's GetImportData skips setting
    // these keys when the result is empty, and ChooseAccount can fail to set
    // firefly_account if the configured IBAN doesn't match any discovered
    // account. Don't crash — render done.twig (with auto-save) anyway.
    if (!$session->has('transactions_to_import')) $session->set('transactions_to_import', serialize(array()));
    if (!$session->has('firefly_account'))        $session->set('firefly_account', null);
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
    $fintsPersistence = base64_encode($session->get('persistedFints'));
    $autoSaveStatus   = try_save_persistence($session, $fintsPersistence);
    echo $twig->render(
        'done.twig',
        array(
            'import_messages' => $import_messages,
            'total_num_transactions' => count($transactions),
            'fints_persistence' => $fintsPersistence,
            'auto_save_status' => $autoSaveStatus
        )
    );
    $session->invalidate();
    return Step::DONE;
}

/**
 * If the user loaded a configuration file at the start of this run
 * (Setup.php stored the resolved path in the session), try to write the
 * fresh FinTS persistence blob back into that file. Returns null when no
 * config file is known (fresh interactive run), an array with ok=true on
 * success, or ok=false with the error message on failure.
 *
 * This is the fix for the truncation UX bug: rendering a 17 KB base64 blob
 * inside <pre> and asking the user to copy/paste it into JSON reliably
 * returns a TRUNCATED string from the browser selection, which then breaks
 * subsequent phpFinTS unserialize.
 */
function try_save_persistence($session, $base64String)
{
    if (!$session->has('configurationFileName')) {
        return null;
    }
    // Defense: never overwrite a saved persistence with empty/junk. If the
    // session got wiped before we got here (e.g., a prior crash invalidated
    // it), persistedFints will be empty and the base64-encoded form ends up
    // as the encoding of an empty string. Refuse to write that.
    if (empty($base64String) || strlen($base64String) < 32) {
        return array(
            'ok'       => false,
            'fileName' => basename($session->get('configurationFileName')),
            'error'    => 'refused to write empty/short persistence (session was wiped before save)',
        );
    }
    $fileName = $session->get('configurationFileName');
    try {
        ConfigurationFactory::save_persistence_to_file($fileName, $base64String);
        return array('ok' => true, 'fileName' => basename($fileName));
    } catch (\Throwable $e) {
        return array(
            'ok'       => false,
            'fileName' => basename($fileName),
            'error'    => $e->getMessage(),
        );
    }
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
