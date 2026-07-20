<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log deprecation warnings that would otherwise only flash on screen
set_error_handler(function ($severity, $message, $file, $line) {
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        error_log("DEPRECATION: $message in $file on line $line");
    }
    // Return false to let PHP's default error handler run as well
    return false;
});

require_once __DIR__ . '/../vendor/autoload.php';

include 'Setup.php';
include 'CollectData.php';
include 'Choose2FADevice.php';
include 'Login.php';
include 'ChooseAccount.php';
include 'GetImportData.php';
include 'RunImportBatched.php';

use App\StepFunction;
use App\FinTsFactory;
use App\ConfigurationFactory;
use App\TanHandler;
use App\TransactionsToFireflySender;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use App\Step;
use App\AutomateStatus;
use GrumpyDictator\FFIIIApiSupport\Request\GetAccountsRequest;

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/public/html');
$twig   = new \Twig\Environment($loader);
$automate_without_js = false;

$request = Request::createFromGlobals();

$current_step = new Step($request->request->get("step", Step::STEP0_SETUP));

$session = new Session();
$session->start();

if (isset($_GET['automate'])) {
    $automate_without_js = $_GET['automate'] == "true";
}

// In automate mode, buffer all step output so we can set an HTTP status code
// after the run (the step functions echo Twig output immediately, which would
// otherwise commit a 200 before we know the outcome). See App\AutomateStatus.
if ($automate_without_js) {
    ob_start();

    // Safety net: if the run dies with a fatal error or uncaught exception, the
    // status-emitting block at the bottom never runs and PHP would flush the
    // default 200. This shutdown handler runs before the output buffer is
    // flushed, so it can still turn such a run into a 500.
    register_shutdown_function(function () {
        $error = error_get_last();
        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR);
        if ($error !== null && in_array($error['type'], $fatal_types, true) && !headers_sent()) {
            http_response_code(500);
        }
    });
}


do
{
    switch ((string)$current_step) {
        case Step::STEP0_SETUP:
            $current_step = StepFunction\Setup();
            break;

        case Step::STEP1_COLLECTING_DATA:
            $current_step = StepFunction\CollectData();
            break;

        case Step::STEP1p5_CHOOSE_2FA_DEVICE:
            $current_step = StepFunction\Choose2FADevice();
            break;

        case Step::STEP2_LOGIN:
            $current_step = StepFunction\Login();
            break;

        case Step::STEP3_CHOOSE_ACCOUNT:
            $current_step = StepFunction\ChooseAccount();
            break;

        case Step::STEP4_GET_IMPORT_DATA:
            $current_step = StepFunction\GetImportData();
            break;

        case Step::STEP5_RUN_IMPORT_BATCHED:
            $current_step = StepFunction\RunImportBatched();
            break;

        default:
            $current_step = Step::DONE;
            break;
    }
} while ($current_step != Step::DONE);

// Emit the machine-readable status for automate mode. Without format=json the
// HTML body is byte-identical to before — only the HTTP status code is added,
// which fixes existing "curl -f"-style crons rather than breaking them. JSON is
// strictly opt-in.
if ($automate_without_js) {
    $body = ob_get_clean();
    http_response_code(AutomateStatus::httpCode());
    if (isset($_GET['format']) && $_GET['format'] === 'json') {
        header('Content-Type: application/json');
        echo json_encode(AutomateStatus::payload());
    } else {
        echo $body;
    }
}
