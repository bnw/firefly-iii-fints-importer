<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

include 'Setup.php';
include 'CollectData.php';
include 'Choose2FADevice.php';
include 'Login.php';
include 'ChooseAccount.php';
include 'GetImportData.php';
include 'RunImport.php';

use App\StepFunction;
use App\FinTsFactory;
use App\ConfigurationFactory;
use App\TanHandler;
use App\TransactionsToFireflySender;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use App\Step;
use GrumpyDictator\FFIIIApiSupport\Request\GetAccountsRequest;

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/public/html');
$twig   = new \Twig\Environment($loader);

$request = Request::createFromGlobals();

$current_step = new Step($request->request->get("step", Step::STEP0_SETUP));

$session = new Session();
$session->start();



switch ((string)$current_step) {
    case Step::STEP0_SETUP:
        StepFunction\Setup();
        break;

    case Step::STEP1_COLLECTING_DATA:
        StepFunction\CollectData();
        break;

    case Step::STEP1p5_CHOOSE_2FA_DEVICE:
        StepFunction\Choose2FADevice();
        break;

    case Step::STEP2_LOGIN:
        StepFunction\Login();
        break;

    case Step::STEP3_CHOOSE_ACCOUNT:
        StepFunction\ChooseAccount();
        break;

    case Step::STEP4_GET_IMPORT_DATA:
        StepFunction\GetImportData();
        break;

    case Step::STEP5_RUN_IMPORT:
        StepFunction\RunImport();
        break;

    default:
        echo "Unknown step $current_step";
        break;
}
