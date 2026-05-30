<?php


namespace App;

class Configuration {
    public $bank_username;
    public $bank_password;
    public $bank_url;
    public $bank_code;
    public $bank_2fa;
    public $bank_2fa_device;
    public $bank_fints_persistence;
    public $firefly_url;
    public $firefly_access_token;
    public $skip_transaction_review;
    public $bank_account_iban;
    public $firefly_account_id;
    public $choose_account_from;
    public $choose_account_to;
    public $description_regex_match;
    public $description_regex_replace;
    public $force_mt940;
}

class ConfigurationFactory
{
    static function load_from_file($fileName)
    {
        $jsonFileContent = file_get_contents($fileName);
        $contentArray = json_decode($jsonFileContent, true);

        $configuration = new Configuration();
        $configuration->bank_username           = $contentArray["bank_username"];
        $configuration->bank_password           = $contentArray["bank_password"];
        $configuration->bank_url                = $contentArray["bank_url"];
        $configuration->bank_code               = $contentArray["bank_code"];
        $configuration->bank_2fa                = $contentArray["bank_2fa"];
        $configuration->bank_2fa_device         = @$contentArray["bank_2fa_device"];
        if (isset($contentArray["bank_fints_persistence"]) && $contentArray["bank_fints_persistence"] != '') {
            $configuration->bank_fints_persistence = base64_decode($contentArray["bank_fints_persistence"]);
        }
        $configuration->firefly_url             = $contentArray["firefly_url"];
        $configuration->firefly_access_token    = $contentArray["firefly_access_token"];
        $configuration->skip_transaction_review = filter_var($contentArray["skip_transaction_review"], FILTER_VALIDATE_BOOLEAN);
        if (isset($contentArray["choose_account_automation"])) {
            $configuration->bank_account_iban       = $contentArray["choose_account_automation"]["bank_account_iban"];
            $configuration->firefly_account_id      = $contentArray["choose_account_automation"]["firefly_account_id"];
            $configuration->choose_account_from     = $contentArray["choose_account_automation"]["from"];
            $configuration->choose_account_to       = $contentArray["choose_account_automation"]["to"];
        } else {
            $configuration->bank_account_iban = NULL;
            $configuration->firefly_account_id = NULL;
            $configuration->choose_account_from = NULL;
            $configuration->choose_account_to = NULL;
        }
        $configuration->description_regex_match   = $contentArray["description_regex_match"];
        $configuration->description_regex_replace = $contentArray["description_regex_replace"];
        $configuration->force_mt940               = filter_var($contentArray["force_mt940"] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $configuration;
    }

    /**
     * Update only the `bank_fints_persistence` key inside an existing
     * configuration JSON file, preserving every other top-level key and
     * every nested object (e.g. `choose_account_automation`).
     *
     * The file is written atomically: contents go to `<fileName>.tmp` first
     * and are then `rename()`d into place. The temporary file is removed if
     * anything goes wrong.
     *
     * Throws `\RuntimeException` if the existing file is missing or contains
     * invalid JSON — we deliberately refuse to overwrite a malformed file,
     * because doing so would silently destroy whatever the user has there.
     *
     * Passing an empty `$base64String` is a deliberate clear and writes an
     * empty string into `bank_fints_persistence` (it is NOT skipped).
     *
     * @param string $fileName     Absolute or relative path to an existing JSON config.
     * @param string $base64String The new base64-encoded phpFinTS persistence blob.
     * @throws \RuntimeException
     */
    static function save_persistence_to_file($fileName, $base64String)
    {
        if (!file_exists($fileName)) {
            throw new \RuntimeException(
                "Cannot save FinTS persistence: configuration file does not exist: $fileName"
            );
        }

        $existing = file_get_contents($fileName);
        if ($existing === false) {
            throw new \RuntimeException(
                "Cannot save FinTS persistence: failed to read configuration file: $fileName"
            );
        }

        $contentArray = json_decode($existing, true);
        if (!is_array($contentArray)) {
            throw new \RuntimeException(
                "Cannot save FinTS persistence: existing configuration file is not valid JSON: $fileName"
            );
        }

        $contentArray["bank_fints_persistence"] = $base64String;

        $encoded = json_encode($contentArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException(
                "Cannot save FinTS persistence: failed to encode JSON for: $fileName"
            );
        }

        $tmp = $fileName . '.tmp';
        if (file_put_contents($tmp, $encoded) === false) {
            throw new \RuntimeException(
                "Cannot save FinTS persistence: failed to write temporary file: $tmp"
            );
        }

        // Preserve the existing file's permissions across the atomic rename.
        // Config files often hold plaintext bank credentials + Firefly tokens,
        // and operators may chmod them to 0600. file_put_contents on the .tmp
        // uses the process umask (typically 0644); without this chmod the
        // rename would silently relax those permissions.
        $origPerms = @fileperms($fileName);
        if ($origPerms !== false) {
            @chmod($tmp, $origPerms & 0777);
        }

        if (!rename($tmp, $fileName)) {
            // Best-effort cleanup; don't mask the original error.
            @unlink($tmp);
            throw new \RuntimeException(
                "Cannot save FinTS persistence: failed to rename $tmp to $fileName"
            );
        }
    }
}
