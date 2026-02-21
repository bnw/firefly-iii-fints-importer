# Design: Import Tag Support

**Date:** 2026-02-21
**Issue:** https://github.com/bnw/firefly-iii-fints-importer/issues/20

## Summary

Add opt-in automatic tagging to mark all transactions imported in a single session with a shared tag. This mirrors the behavior of the Firefly III data-importer and enables users to identify, filter, and revert imports as a cohesive group.

## Key Constraint

Tags must **not** be included in the initial `PostTransactionRequest` body. Firefly III includes tags in its duplicate-detection hash; adding them during creation would break deduplication for re-imports. Tags are applied via a separate `PutTransactionRequest` after all transactions have been created.

## Chosen Approach

**Accumulate group IDs in session, tag on completion.**

After all transaction batches finish, create the tag once via `PostTagRequest` and apply it to each successfully imported transaction group via `PutTransactionRequest`. Group IDs are accumulated in the session across batch iterations.

## Configuration

New field `add_import_tag` (boolean, default `false`) added to:

- `Configuration` class (`app/ConfigurationFactory.php`)
- `ConfigurationFactory::load_from_file()` — reads from JSON, defaults to `false` if absent
- `example.json` — documented with `"add_import_tag": false`
- `CollectData.php` — stored in session as `add_import_tag`

## Tag Name Format

Auto-generated, not user-configurable:

```
FinTS Import YYYY-MM-DD @ HH:MM
```

Example: `FinTS Import 2026-02-21 @ 14:30`

Generated once at the start of STEP5, stored in session as `import_tag_name`.

## Files Changed

### `app/ConfigurationFactory.php`
- Add `public $add_import_tag;` to `Configuration` class
- Load `add_import_tag` in `ConfigurationFactory::load_from_file()` with `false` default

### `app/CollectData.php`
- Store `add_import_tag` from configuration into session

### `app/TransactionsToFireflySender.php`
- `send_transactions()` return type changes from `array[]` (errors only) to:
  ```php
  ['errors' => array[], 'group_ids' => int[]]
  ```
- On successful `PostTransactionResponse`, extract `$response->getTransactionGroup()->id` and add to `group_ids`

### `app/PostImportTagger.php` (new file)
- Class `PostImportTagger` with:
  - Constructor: `__construct(string $firefly_url, string $firefly_access_token, string $tag_name)`
  - Method: `apply(array $group_ids): void`
    1. If `$group_ids` is empty, return early
    2. Create tag via `PostTagRequest` (idempotent — Firefly III reuses existing tags by name)
    3. For each group ID, send `PutTransactionRequest->put()` with body:
       ```php
       ['transactions' => [['tags' => [$this->tag_name]]]]
       ```
    4. Log warnings on failure but do not throw — transactions are already saved

### `app/RunImportBatched.php`
- `RunImport()`: after calling `$sender->send_transactions()`, if `add_import_tag` is set in session, append returned `group_ids` to `imported_group_ids` session key
- Tag name generated once via `date('Y-m-d @ H:i')` and stored in session as `import_tag_name` before first batch
- `RunImportWithJS()`: when `$num_transactions_processed >= count($transactions)`, if `add_import_tag` is true, instantiate `PostImportTagger` and call `apply()` with session `imported_group_ids`
- `RunImportWithoutJS()`: after the while loop, if `add_import_tag` is true, same

### `data/configurations/example.json`
- Add `"add_import_tag": false`

### `tests/TransactionsToFireflySenderTest.php`
- Update tests to use new return type of `send_transactions()`

## Error Handling

- `PostTagRequest` failure: log warning, skip tag application, do not fail import
- `PutTransactionRequest` failure per group: log warning, continue with remaining groups
- 0 transactions imported: skip tagging entirely
- Re-import on same day: Firefly III deduplicates tag names, no duplicate tags created

## Out of Scope

- Custom tag name templates
- UI/form input for tag name
- Removing or renaming the tag after import
