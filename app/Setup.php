<?php
namespace App\StepFunction;

use App\Step;

function Setup()
{
    global $twig, $automate_without_js, $request;

    $host_config_file = '';

    if (isset($_GET['config'])) {
        $filename = basename($_GET['config']);
        $host_config_file = 'data/configurations/' . $filename;
        $docker_config_file = '/app/configurations/' . $filename;
        if (!file_exists($host_config_file) && !file_exists($docker_config_file)) {
            echo $twig->render(
                'error.twig',
                array(
                    'error_header' => 'Could not find the configuration',
                    'error_message' => 'The configuration \'' . $filename . '\' could\'t be found in the host directory \'data/configurations/\' or the docker directory \'/app/configurations/\''
                )
            );
            return;
        }

        if ($automate_without_js)
        {
            if (file_exists($host_config_file)) {
                $request->request->set('data_collect_mode', $host_config_file);
            } else {
                $request->request->set('data_collect_mode', $docker_config_file);
            }
            return Step::STEP1_COLLECTING_DATA;
        }
    }


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
            'host_config_file' => $host_config_file,
            'next_step' => Step::STEP1_COLLECTING_DATA
    ));
    
    return Step::DONE;
}
