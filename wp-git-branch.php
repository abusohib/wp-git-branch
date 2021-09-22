<?php
/**
 * @package   	      Wordpress git
 * @contributors      Abu Sohib (Approve Me)
 * @wordpress-plugin
 * Plugin Name:       Wordpress git branch.  
 * Plugin URI:        https://www.approveme.com
 * Description:       This plugin help you to display git branch information in plugins page for wordpress.
 * Version:           1.0
 * Author:            Abu Sohib
 * Author URI:        https://www.approveme.com
 */
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

add_filter("plugin_row_meta","wgb_plugins_meta",10,4);

function wgb_plugins_meta($plugin_meta, $plugin_file, $plugin_data, $status){

    $fileInfo = explode("/",$plugin_file);

    $gitFile =  WP_PLUGIN_DIR . '/' . $fileInfo[0] . "/.git/HEAD";

    if(!file_exists($gitFile))
    {
        return $plugin_meta;
    }

    $gitStr = file_get_contents($gitFile);

    $gitBranchName = rtrim(preg_replace("/(.*?\/){2}/", '', $gitStr));

    $plugin_meta[] = '<span style="color:red;font-weight:bold;"> Git Branch : </span> ' . $gitBranchName ;

    $plugin_meta[] = '<a target="_blank" class="thickbox open-plugin-details-modal" href="' . esc_url(plugins_url('change-git-branch.php?pluginName='.$plugin_data['Name'] . '&folderName='. $fileInfo[0] , __FILE__)) . '"> Change Branch </a>';
    return $plugin_meta;
}
