<?php

namespace App;

/**
 * Collects a machine-readable status for headless ("automate") runs.
 *
 * The automate mode (GET /?automate=true&config=<name>.json) currently answers
 * *everything* with HTTP 200 and HTML: success, a missing configuration, a TAN
 * prompt, a PHP fatal error. There is no way for an automation to tell them
 * apart without scraping the HTML, and that scraping silently misclassifies runs
 * that stop at an interactive step (e.g. a TAN challenge or the device picker),
 * because those pages contain no error string.
 *
 * This class lets the step functions record what happened. index.php then turns
 * it into an HTTP status code (always) and, when the caller passes format=json,
 * a small JSON body (opt-in). The default state — never set — is treated as a
 * failure ("stalled"), so a run that ends on an interactive page reports a 500
 * by construction instead of a misleading 200.
 *
 * PHP's shared-nothing model means this static state lives for exactly one
 * request, so there is no cross-request leakage.
 */
class AutomateStatus
{
    const OK = 'ok';

    /** @var string|null null means "never set" => the run stalled at an interactive step. */
    private static $status = null;
    private static $header = null;
    private static $message = null;
    private static $transactions = null;

    /**
     * Record a failure. The first failure wins, so a fatal error occurring after
     * a partial success cannot be masked by a later, more benign state.
     */
    public static function fail(string $status, string $header, string $message = ''): void
    {
        if (self::$status === null) {
            self::$status  = $status;
            self::$header  = $header;
            self::$message = $message;
        }
    }

    /** Record a successful import with the number of transactions sent to Firefly. */
    public static function success(int $transactions): void
    {
        if (self::$status === null) {
            self::$status       = self::OK;
            self::$transactions = $transactions;
        }
    }

    /** The status string, defaulting to "stalled" when nothing was ever recorded. */
    public static function statusName(): string
    {
        return self::$status === null ? 'stalled' : self::$status;
    }

    /** Map the status onto an HTTP status code. Anything unknown/unset is a 500. */
    public static function httpCode(): int
    {
        switch (self::$status) {
            case self::OK:
                return 200;
            case 'tan_required':
            case 'tan_device_ambiguous':
                return 409; // needs a human before it can complete
            case 'config_not_found':
                return 404;
            case 'verification_failed':
                return 422; // given IBAN / Firefly account id did not resolve
            default:
                return 500; // includes null (stalled) and importer_error
        }
    }

    /** The JSON payload returned when format=json is requested. */
    public static function payload(): array
    {
        return array(
            'status'        => self::statusName(),
            'transactions'  => self::$transactions,
            'error_header'  => self::$header,
            'error_message' => self::$message,
        );
    }
}
