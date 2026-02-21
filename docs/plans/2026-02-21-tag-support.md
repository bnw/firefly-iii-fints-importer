# Import Tag Support Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add opt-in automatic tagging so all transactions in an import session share one identifiable tag, without breaking Firefly III's duplicate-detection.

**Architecture:** Tags are applied *after* all transactions are created (never in the POST body) to avoid the duplicate-hash issue. `send_transactions()` returns group IDs from successful responses; `RunImportBatched` accumulates them in the session; a new `PostImportTagger` class creates the tag and attaches it to each group via PUT once all batches finish.

**Tech Stack:** PHP 8.4, `firefly-iii/api-support-classes` (already a dependency — provides `PostTagRequest`, `PutTransactionRequest`), PHPUnit 9.5.

---

### Task 1: Add `add_import_tag` to Configuration

**Files:**
- Modify: `app/ConfigurationFactory.php`
- Modify: `data/configurations/example.json`
- Test: `tests/TransactionsToFireflySenderTest.php`

**Step 1: Write the two failing tests**

Add to `TransactionsToFireflySenderTest`:

```php
public function test_add_import_tag_defaults_to_false(): void
{
    $config_file_name = "test_add_import_tag_default.json";
    file_put_contents($config_file_name, json_encode([
        "description_regex_match" => "",
        "description_regex_replace" => ""
    ]));
    $configuration = @ConfigurationFactory::load_from_file($config_file_name);
    $this->assertFalse($configuration->add_import_tag);
    unlink($config_file_name);
}

public function test_add_import_tag_can_be_enabled(): void
{
    $config_file_name = "test_add_import_tag_enabled.json";
    file_put_contents($config_file_name, json_encode([
        "description_regex_match" => "",
        "description_regex_replace" => "",
        "add_import_tag" => true
    ]));
    $configuration = @ConfigurationFactory::load_from_file($config_file_name);
    $this->assertTrue($configuration->add_import_tag);
    unlink($config_file_name);
}
```

**Step 2: Run the tests to confirm they fail**

```bash
./vendor/bin/phpunit tests/TransactionsToFireflySenderTest.php --filter "add_import_tag" -v
```

Expected: FAIL — `Undefined property: App\Configuration::$add_import_tag`

**Step 3: Add `$add_import_tag` to `Configuration` class**

In `app/ConfigurationFactory.php`, add to the `Configuration` class after `$force_mt940`:

```php
public $add_import_tag;
```

In `ConfigurationFactory::load_from_file()`, add after the `force_mt940` line:

```php
$configuration->add_import_tag = filter_var($contentArray["add_import_tag"] ?? false, FILTER_VALIDATE_BOOLEAN);
```

**Step 4: Run the tests to confirm they pass**

```bash
./vendor/bin/phpunit tests/TransactionsToFireflySenderTest.php --filter "add_import_tag" -v
```

Expected: PASS (both tests green)

**Step 5: Run the full test suite to confirm nothing is broken**

```bash
./vendor/bin/phpunit tests/ -v
```

Expected: all existing tests still pass

**Step 6: Update `example.json`**

Add `"add_import_tag": false` to `data/configurations/example.json`. Place it after the `"force_mt940"` line:

```json
"force_mt940": false,
"add_import_tag": false,
```

**Step 7: Commit**

```bash
git add app/ConfigurationFactory.php data/configurations/example.json tests/TransactionsToFireflySenderTest.php
git commit -m "feat: add add_import_tag config option"
```

---

### Task 2: Pass `add_import_tag` through the session

**Files:**
- Modify: `app/CollectData.php`

The `CollectData.php` file stores each config field into the session. We need to add `add_import_tag` to that list.

**Step 1: Add session storage to `CollectData.php`**

In `CollectData()`, after the line `$session->set('force_mt940', $configuration->force_mt940);`, add:

```php
$session->set('add_import_tag', $configuration->add_import_tag);
```

**Step 2: Run the full test suite**

```bash
./vendor/bin/phpunit tests/ -v
```

Expected: all tests pass (this change is not directly unit tested — covered by integration)

**Step 3: Commit**

```bash
git add app/CollectData.php
git commit -m "feat: store add_import_tag in session"
```

---

### Task 3: Update `send_transactions()` to return group IDs

**Files:**
- Modify: `app/TransactionsToFireflySender.php`

**Background:** The `send_transactions()` method currently returns an array of error entries. We change it to return `['errors' => [...], 'group_ids' => [...]]` where `group_ids` maps `group_id (int) => [journal_id (int) => existing_tags (string[])]`. This lets callers collect the IDs needed to apply tags after import.

The `PostTransactionResponse::getTransactionGroup()` returns a `TransactionGroup` with:
- `->id` (int): the group ID used for the PUT request
- `->transactions` (array of `Transaction` objects), each with:
  - `->id` (int): the `transaction_journal_id`
  - `->tags` (array): any tags already applied by Firefly rules

**Step 1: Rewrite `send_transactions()`**

Replace the existing `send_transactions()` method body with:

```php
public function send_transactions(): array
{
    $errors = [];
    $group_ids = [];

    foreach ($this->transactions as $transaction) {
        $request = new PostTransactionRequest($this->firefly_url, $this->firefly_access_token);

        $request->setBody(
            self::transform_transaction_to_firefly_request_body(
                $transaction,
                $this->firefly_account_id,
                $this->firefly_accounts,
                $this->regex_match,
                $this->regex_replace
            )
        );

        $response = $request->post();
        if ($response instanceof ValidationErrorResponse) {
            $errs   = $response->errors->all();
            $errs[] = "Firefly III request: " . json_encode($request->getBody());
            $errs[] = "Transaction data: " . print_r($transaction, true);
            $errors[] = array('transaction' => $transaction, 'messages' => $errs);
        } elseif ($response instanceof PostTransactionResponse) {
            $group = $response->getTransactionGroup();
            if ($group !== null) {
                $journals = [];
                foreach ($group->transactions as $journal) {
                    $journals[$journal->id] = $journal->tags;
                }
                $group_ids[$group->id] = $journals;
            }
        } else {
            throw new \Exception('Import went wrong');
        }
    }

    return ['errors' => $errors, 'group_ids' => $group_ids];
}
```

**Step 2: Run the full test suite**

```bash
./vendor/bin/phpunit tests/ -v
```

Expected: all existing tests still pass (existing tests only test the static `transform_transaction_to_firefly_request_body` method, which is unchanged)

**Step 3: Commit**

```bash
git add app/TransactionsToFireflySender.php
git commit -m "feat: return group IDs from send_transactions"
```

---

### Task 4: Create `PostImportTagger` class

**Files:**
- Create: `app/PostImportTagger.php`

**Background:** This class handles the two-step post-import tagging: first creates the tag via `PostTagRequest`, then applies it to each transaction group via `PutTransactionRequest`. Tag creation is idempotent — Firefly III reuses existing tags by name, so re-running an import on the same day does not create duplicate tags. Failures are logged as warnings but do not fail the import.

**Step 1: Create `app/PostImportTagger.php`**

```php
<?php

namespace App;

use GrumpyDictator\FFIIIApiSupport\Request\PostTagRequest;
use GrumpyDictator\FFIIIApiSupport\Request\PutTransactionRequest;
use GrumpyDictator\FFIIIApiSupport\Response\ValidationErrorResponse;

class PostImportTagger
{
    private string $firefly_url;
    private string $firefly_access_token;
    private string $tag_name;

    public function __construct(string $firefly_url, string $firefly_access_token, string $tag_name)
    {
        $this->firefly_url          = $firefly_url;
        $this->firefly_access_token = $firefly_access_token;
        $this->tag_name             = $tag_name;
    }

    /**
     * Creates the import tag and applies it to all successfully imported transaction groups.
     *
     * @param array $group_ids Maps group_id (int) => [ journal_id (int) => existing_tags (string[]) ]
     */
    public function apply(array $group_ids): void
    {
        if (empty($group_ids)) {
            return;
        }

        $this->createTag();

        foreach ($group_ids as $group_id => $journals) {
            $this->applyTagToGroup((int) $group_id, $journals);
        }
    }

    private function createTag(): void
    {
        $request = new PostTagRequest($this->firefly_url, $this->firefly_access_token);
        $request->setBody(['tag' => $this->tag_name, 'date' => date('Y-m-d')]);

        try {
            $response = $request->post();
            if ($response instanceof ValidationErrorResponse) {
                Logger::warn('Could not create import tag: ' . json_encode($response->errors->all()));
            }
        } catch (\Exception $e) {
            Logger::warn('Could not create import tag: ' . $e->getMessage());
        }
    }

    private function applyTagToGroup(int $group_id, array $journals): void
    {
        $transactions = [];
        foreach ($journals as $journal_id => $existing_tags) {
            $transactions[] = [
                'transaction_journal_id' => $journal_id,
                'tags'                   => array_merge($existing_tags, [$this->tag_name]),
            ];
        }

        $request = new PutTransactionRequest($this->firefly_url, $this->firefly_access_token, $group_id);
        $request->setBody([
            'apply_rules'    => false,
            'fire_webhooks'  => false,
            'transactions'   => $transactions,
        ]);

        try {
            $request->put();
        } catch (\Exception $e) {
            Logger::warn("Could not apply tag to transaction group {$group_id}: " . $e->getMessage());
        }
    }
}
```

**Step 2: Run the full test suite**

```bash
./vendor/bin/phpunit tests/ -v
```

Expected: all tests pass (new class, no existing tests reference it)

**Step 3: Commit**

```bash
git add app/PostImportTagger.php
git commit -m "feat: add PostImportTagger class"
```

---

### Task 5: Wire tagging into `RunImportBatched`

**Files:**
- Modify: `app/RunImportBatched.php`

This is the final wiring task. Three changes:

1. **`RunImportBatched()`** — generate the tag name once per import session and store it in the session.
2. **`RunImport()`** — extract `group_ids` from the new `send_transactions()` return value and accumulate them in the session. Return only the `errors` array (preserving the caller contract).
3. **`RunImportWithJS()` and `RunImportWithoutJS()`** — after all batches complete, instantiate `PostImportTagger` and call `apply()`.

**Step 1: Update `RunImportBatched()`**

Replace the existing function body with:

```php
function RunImportBatched()
{
    global $session, $automate_without_js;

    // Generate tag name once at the start of this import run
    if ($session->get('add_import_tag', false) && !$session->has('import_tag_name')) {
        $session->set('import_tag_name', 'FinTS Import ' . date('Y-m-d @ H:i'));
    }

    if ($automate_without_js) {
        return RunImportWithoutJS();
    } else {
        return RunImportWithJS();
    }
}
```

**Step 2: Update `RunImport()`**

Replace the existing function body with:

```php
function RunImport($transactions): array
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

    if ($session->get('add_import_tag', false) && !empty($result['group_ids'])) {
        $existing = $session->has('imported_group_ids')
            ? unserialize($session->get('imported_group_ids'))
            : [];
        $session->set('imported_group_ids', serialize(array_merge($existing, $result['group_ids'])));
    }

    return $result['errors'];
}
```

**Step 3: Add a helper function for the tagging step**

Add this new function after `RunImport`:

```php
function ApplyImportTag(): void
{
    global $session;

    if (!$session->get('add_import_tag', false)) {
        return;
    }

    $group_ids = $session->has('imported_group_ids')
        ? unserialize($session->get('imported_group_ids'))
        : [];

    $tagger = new \App\PostImportTagger(
        $session->get('firefly_url'),
        $session->get('firefly_access_token'),
        $session->get('import_tag_name', 'FinTS Import ' . date('Y-m-d @ H:i'))
    );
    $tagger->apply($group_ids);
}
```

**Step 4: Update `RunImportWithJS()`**

In the completion branch (`if ($num_transactions_processed >= count($transactions))`), add the `ApplyImportTag()` call just before rendering the done template:

```php
if ($num_transactions_processed >= count($transactions)) {
    ApplyImportTag();
    echo $twig->render(
        'done.twig',
        array(
            'import_messages' => $import_messages,
            'total_num_transactions' => count($transactions),
            'fints_persistence' => base64_encode($session->get('persistedFints'))
        )
    );
    $session->invalidate();
} else {
    // ... existing batch progress code unchanged
}
```

**Step 5: Update `RunImportWithoutJS()`**

After the while loop (before rendering done.twig), add:

```php
ApplyImportTag();
echo $twig->render(
    'done.twig',
    array(
        'import_messages' => $import_messages,
        'total_num_transactions' => count($transactions)
    )
);
$session->invalidate();
```

(Remove the existing standalone `echo $twig->render(...)` and `$session->invalidate()` lines that were there before, replacing them with the block above.)

**Step 6: Run the full test suite**

```bash
./vendor/bin/phpunit tests/ -v
```

Expected: all tests pass

**Step 7: Commit**

```bash
git add app/RunImportBatched.php
git commit -m "feat: wire post-import tag application into RunImportBatched"
```

---

## Testing the Full Feature

This feature requires a live Firefly III instance to fully test. Manual test checklist:

1. Add `"add_import_tag": false` to your config — run an import — confirm no tag appears in Firefly
2. Change to `"add_import_tag": true` — run an import with a few transactions — confirm:
   - A tag `FinTS Import YYYY-MM-DD @ HH:MM` appears in Firefly III's tag list
   - Each imported transaction has that tag
   - Rule-applied tags on those transactions are preserved (not wiped)
3. Re-run the same import — confirm Firefly correctly deduplicates the transactions (no new transactions created) and the tag is reused (not duplicated)
4. Test headless mode (`automate=true`) — confirm tagging works the same way

---

## Summary of Changed Files

| File | Change |
|------|--------|
| `app/ConfigurationFactory.php` | Add `$add_import_tag` property + load from JSON |
| `app/CollectData.php` | Store `add_import_tag` in session |
| `app/TransactionsToFireflySender.php` | Return `group_ids` alongside `errors` |
| `app/PostImportTagger.php` | New class — creates tag and applies via PUT |
| `app/RunImportBatched.php` | Accumulate group IDs, call tagger on completion |
| `data/configurations/example.json` | Add `add_import_tag: false` |
| `tests/TransactionsToFireflySenderTest.php` | Add 2 config tests |
