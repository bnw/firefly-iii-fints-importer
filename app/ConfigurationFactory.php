<?php

namespace App;
use JsonSchema\Validator;

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
    public $bank_account_iban = null;
    public $firefly_account_id = null;
    public $choose_account_from = null;
    public $choose_account_to = null;
    public $description_regex_match = null;
    public $description_regex_replace = null;
    public $force_mt940 = false;
}

class ConfigurationFactory
{
    static function load_from_file($fileName)
    {
        Logger::info("Loading configuration from file: $fileName");

        if (!file_exists($fileName)) {
            throw new \Exception("Configuration file not found: $fileName");
        }

        $jsonFileContent = file_get_contents($fileName);
        $data = json_decode($jsonFileContent);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON: " . json_last_error_msg());
        }

        // json validator
        $validator = new Validator();
        $schemaPath = 'file://' . realpath(__DIR__ . '/resources/schema.json');

        $validator->validate($data, (object)['$ref' => $schemaPath]);

        if (!$validator->isValid()) {
            $errorMsg = "JSON does not validate. Errors:\n";
            foreach ($validator->getErrors() as $error) {
                $errorMsg .= sprintf("[%s] %s\n", $error['property'], $error['message']);
            }
            throw new \Exception($errorMsg);
        }

        $contentArray = json_decode($jsonFileContent, true);

        $configuration = new Configuration();
        $configuration->bank_url                = $contentArray["bank_url"];
        $configuration->bank_code               = $contentArray["bank_code"];
        $configuration->bank_username           = $contentArray["bank_username"];
        $configuration->bank_password           = $contentArray["bank_password"];
        $configuration->bank_2fa                = $contentArray["bank_2fa"] ?? null;
        $configuration->bank_2fa_device         = $contentArray["bank_2fa_device"] ?? null;
        
        if (isset($contentArray["bank_fints_persistence"]) && $contentArray["bank_fints_persistence"] != '') {
            $configuration->bank_fints_persistence = base64_decode($contentArray["bank_fints_persistence"]);
        }
        $configuration->firefly_url             = $contentArray["firefly_url"];
        $configuration->firefly_access_token    = $contentArray["firefly_access_token"];

        $configuration->skip_transaction_review = filter_var($contentArray["skip_transaction_review"], FILTER_VALIDATE_BOOLEAN);
        if (isset($contentArray["choose_account_automation"])) {
            $automation = $contentArray["choose_account_automation"];
            $configuration->bank_account_iban  = $automation["bank_account_iban"] ?? null;
            $configuration->firefly_account_id = $automation["firefly_account_id"] ?? null;
            $configuration->choose_account_from = $automation["from"] ?? null;
            $configuration->choose_account_to   = $automation["to"] ?? null;
        }
        
        $configuration->description_regex_match   = $contentArray["description_regex_match"] ?? null;
        $configuration->description_regex_replace = $contentArray["description_regex_replace"] ?? null;
        
        $configuration->force_mt940               = filter_var($contentArray["force_mt940"] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $configuration;
    }
}