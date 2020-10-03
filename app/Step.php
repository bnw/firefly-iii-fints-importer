<?php

namespace App;

class Step extends \MyCLabs\Enum\Enum
{
    public const STEP0_SETUP = 'STEP0_SETUP';
    public const STEP1_COLLECTING_DATA = 'STEP1_COLLECTING_DATA';
    public const STEP1p5_CHOOSE_2FA_DEVICE = 'STEP1p5_CHOOSE_2FA_DEVICE';
    public const STEP2_LOGIN = 'STEP2_LOGIN';
    public const STEP3_CHOOSE_ACCOUNT = 'STEP3_CHOOSE_ACCOUNT';
    public const STEP4_GET_IMPORT_DATA = 'STEP4_GET_IMPORT_DATA';
    public const STEP5_RUN_IMPORT = 'STEP5_RUN_IMPORT';
}