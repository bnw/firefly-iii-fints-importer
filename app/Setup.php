<?php
namespace App\StepFunction;

use App\Step;

function Setup()
{
    global $twig, $automate_without_js, $request;

    $requested_config_file = '';
    $base_dir = __DIR__ . '/../configurations/';

    if (isset($_GET['config'])) {
        $requested_config_file = basename($_GET['config']);
        if (!file_exists($base_dir . $requested_config_file)) {
            echo $twig->render(
                'error.twig',
                array(
                    'error_header' => 'Could not find the configuration',
                    'error_message' => 'The configuration \'' . $requested_config_file . '\' could\'t be found in the directory \'./configurations/\''
                )
            );
            return;
        }

        if ($automate_without_js)
        {
            $request->request->set('data_collect_mode', $requested_config_file);
            return Step::STEP1_COLLECTING_DATA;
        }
    }

    $configuration_files = [];
    if (file_exists($base_dir))
    {
        $configuration_files = array_diff(scandir($base_dir), array('.', '..'));
    }

    echo $twig->render(
        'setup.twig',
        array(
            'files' => $configuration_files,
            'requested_config_file' => $requested_config_file,
            'next_step' => Step::STEP1_COLLECTING_DATA
    ));

    return Step::DONE;
}
