<?php


namespace App;

class Configuration {
    public $bank_username;
    public $bank_password;
    public $bank_url;
    public $bank_code;
    public $bank_2fa;
    public $firefly_url;
    public $firefly_access_token;
    public $skip_transaction_review;
    public $bank_account_iban;
    public $firefly_account_id;
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
        $configuration->firefly_url             = $contentArray["firefly_url"];
        $configuration->firefly_access_token    = $contentArray["firefly_access_token"];
        $configuration->skip_transaction_review = filter_var($contentArray["skip_transaction_review"], FILTER_VALIDATE_BOOLEAN);
        if (isset($contentArray["bank_account_iban"]) && isset($contentArray["firefly_account_id"])) {
            $configuration->bank_account_iban       = $contentArray["bank_account_iban"];
            $configuration->firefly_account_id      = $contentArray["firefly_account_id"];
        } else {
            $configuration->bank_account_iban = NULL;
            $configuration->firefly_account_id = NULL;
        }

        return $configuration;
    }
}
