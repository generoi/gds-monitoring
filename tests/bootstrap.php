<?php

use GeneroWP\Monitoring\Tests\Support\TestFeedAddOn;

/**
 * Integration only: the checks hook into Gravity Forms' own submission,
 * notes, feeds and entries, none of which mean anything against mocks. The
 * real Gravity Forms is a dev dependency, loaded here beside the plugin.
 */
$autoload = dirname(__DIR__).'/vendor/autoload.php';

if (! file_exists($autoload)) {
    exit("Run `composer install` first.\n");
}

require_once $autoload;

$wpPhpunit = getenv('WP_PHPUNIT__DIR') ?: dirname(__DIR__).'/vendor/wp-phpunit/wp-phpunit';

require_once $wpPhpunit.'/includes/functions.php';

tests_add_filter('muplugins_loaded', function (): void {
    require dirname(__DIR__).'/vendor/generoi/gravityforms/gravityforms.php';
    require dirname(__DIR__).'/gds-monitoring.php';
});

// A feed add-on of the tests' own, registered as Gravity Forms expects.
tests_add_filter('gform_loaded', function (): void {
    GFForms::include_feed_addon_framework();
    GFAddOn::register(TestFeedAddOn::class);
}, 5);

require $wpPhpunit.'/includes/bootstrap.php';

// Gravity Forms' tables, as its activation creates them.
GFForms::setup(true);
