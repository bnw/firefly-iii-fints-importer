<?php


namespace App;

class EmailConfig {
    public $enabled;
    public $host;
    public $port;
    public $smtp_secure;
    public $username;
    public $password;
    public $from;
    public $to;
    public $subject;
}

class Configuration {
    public $bank_username;
    public $bank_password;
    public $bank_url;
    public $bank_code;
    public $bank_2fa;
    public $bank_2fa_device;
    public $firefly_url;
    public $firefly_access_token;
    public $skip_transaction_review;
    public $bank_account_iban;
    public $firefly_account_id;
    public $choose_account_from;
    public $choose_account_to;
    public $description_regex_match;
    public $description_regex_replace;
    public $email_config;
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

        $email_config = new EmailConfig();
        $email_config->enabled = false;
        if (isset($contentArray["tan_required_email_notification"])) {
            $email_config->host        = $contentArray["tan_required_email_notification"]["host"];
            $email_config->port        = $contentArray["tan_required_email_notification"]["port"];
            $email_config->smtp_secure = $contentArray["tan_required_email_notification"]["smtp_secure"];
            $email_config->username    = $contentArray["tan_required_email_notification"]["username"];
            $email_config->password    = $contentArray["tan_required_email_notification"]["password"];
            $email_config->from        = $contentArray["tan_required_email_notification"]["from"];
            $email_config->to          = $contentArray["tan_required_email_notification"]["to"];
            $email_config->subject     = $contentArray["tan_required_email_notification"]["subject"];
            $email_config->enabled = !empty($email_config->host) && !empty($email_config->port) &&
                                     !empty($email_config->smtp_secure) && !empty($email_config->username) &&
                                     !empty($email_config->password) && !empty($email_config->from) &&
                                     !empty($email_config->to) && !empty($email_config->subject);
        }
        $configuration->email_config = $email_config;

        return $configuration;
    }
}
