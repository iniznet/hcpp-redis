<?php
/**
 * Plugin Name: Per-User Redis
 * Plugin URI: https://github.com/iniznet/hcpp-redis
 * Description: An experimental plugin to provide per-user Redis instances.
 * Author: iniznet
 * License AGPL-3.0
 */

// Ensure the global HCPP object is available.
global $hcpp;

// 1. Register the main logic file that contains our RedisManager class.
//    This must be done so the class is available to the framework.
require_once( dirname(__FILE__) . '/redis.php' );

// 2. Register the installation script. This will be run once when the plugin
//    is first detected.
$hcpp->register_install_script( dirname(__FILE__) . '/install' );

// 3. Register the uninstallation script. This will be run when the plugin
//    is deleted from the Hestia UI or its folder is removed.
$hcpp->register_uninstall_script( dirname(__FILE__) . '/uninstall' );