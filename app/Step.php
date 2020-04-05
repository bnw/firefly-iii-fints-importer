<?php

namespace App;

class Step extends \MyCLabs\Enum\Enum
{
    public const STEP1_COLLECTING_DATA = 'STEP1_COLLECTING_DATA';
    public const STEP2_LOGIN = 'STEP2_LOGIN';
    public const STEP3_CHOOSE_ACCOUNT = 'STEP3_CHOOSE_ACCOUNT';
    public const STEP4_GET_IMPORT_DATA = 'STEP4_GET_IMPORT_DATA';
}