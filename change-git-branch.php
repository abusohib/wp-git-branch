<?php

$pluginName = isset($_REQUEST['pluginName']) ? $_REQUEST['pluginName'] : false ; 

$folderName = isset($_REQUEST['folderName']) ? $_REQUEST['folderName'] : false;

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : false;

echo '<h1> ' . $pluginName . ' </h1>';

$gitFile =   '../' . $folderName . "/.git/HEAD";

if (!file_exists($gitFile)) {
    echo "file not exists";
    exit;
}

error_reporting(E_ALL);
chdir("../" . $folderName);

if($action =="checkout")
{
    $branchName = isset($_REQUEST['branchName']) ? $_REQUEST['branchName'] : false;
    shell_exec('git checkout ' . $branchName);
    $urlResult = explode("wp-content",$_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ;
    header("Location: http://" . $urlResult[0] . "wp-admin/plugins.php");
    exit;
}
   
    // current directory
    $all_branches = explode(PHP_EOL, shell_exec('git branch'));
    foreach($all_branches as $key => $branch)
    {
        $branchName = trim(preg_replace('/[\*]+/', '', $branch));
        echo '<a href="http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] .'&action=checkout&branchName='. $branchName .'">'.  $branch  . '</a><br><br>';
    }

    // this is testing commit for git testing
    



