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
}

class ConfigurationFactory
{


static function load_from_file($fileName)
    {
        $jsonFileContent = file_get_contents($fileName);
        $contentArray = json_decode($jsonFileContent, true);

        $configuration = new Configuration();
        $configuration->bank_username        = $contentArray["bank_username"];
        $configuration->bank_password        = $contentArray["bank_password"];
        $configuration->bank_url             = $contentArray["bank_url"];
        $configuration->bank_code            = $contentArray["bank_code"];
        $configuration->bank_2fa             = $contentArray["bank_2fa"];
        $configuration->firefly_url          = $contentArray["firefly_url"];
        $configuration->firefly_access_token = $contentArray["firefly_access_token"];

        return $configuration;
    }

}
