<?php
namespace App\StepFunction;

use App\Step;

function Setup()
{
    global $twig;

    $configuration_files = array();
    $dirs = array('/app/configurations', 'data/configurations');
    foreach($dirs as $dir){
        if (file_exists($dir))
            $configuration_files = array_merge($configuration_files,
                preg_filter('/^/', $dir.DIRECTORY_SEPARATOR, array_diff(scandir($dir), array('.', '..') )));
    }

    echo $twig->render(
        'setup.twig',
        array(
            'files' => $configuration_files,
            'next_step' => Step::STEP1_COLLECTING_DATA
        ));
}
