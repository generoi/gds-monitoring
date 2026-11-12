<?php

/**
 * `wp eval-file` from the Playwright tests: a form with the honeypot on and
 * set to keep its catches as spam, the failing feed on it, a page showing it,
 * and no lines recorded yet. Prints the ids as JSON.
 */
$form = GFAPI::add_form([
    'title' => 'E2E '.wp_generate_password(6, false),
    'enableHoneypot' => true,
    'honeypotAction' => 'spam',
    'fields' => [
        ['id' => 1, 'type' => 'text', 'label' => 'Name', 'isRequired' => true],
    ],
]);
$feed = GFAPI::add_feed($form, ['feedName' => 'E2E'], 'gdse2efeed');
$page = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'E2E form',
    'post_content' => "[gravityform id=\"{$form}\" title=\"false\" ajax=\"false\"]",
]);
delete_option('gds_monitoring_e2e_lines');

echo wp_json_encode(['form' => $form, 'feed' => $feed, 'page' => get_permalink($page), 'gf' => GFForms::$version]), "\n";
