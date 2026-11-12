<?php

/*
Plugin Name:  GDS Monitoring
Plugin URI:   https://github.com/generoi/gds-monitoring
Description:  Logs what a person should look at (lost form submissions, failed feeds and emails, silent forms) for monitoring to pick up
Version:      1.1.1
Author:       Genero
Author URI:   https://genero.fi/
License:      MIT
*/

use GeneroWP\Monitoring\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}

Plugin::boot();
