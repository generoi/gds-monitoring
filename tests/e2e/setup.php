<?php

/**
 * `wp eval-file` from the Playwright tests: a form with the honeypot on and
 * set to keep its catches as spam, the failing feed on it, a page showing it,
 * and no lines recorded yet. Prints the ids as JSON. With "expire-uploads",
 * the page's upload nonce lives 2 seconds; with "validate-state", the select
 * is state checked, as the form editor sets on a new field.
 */
if (in_array('expire-uploads', $args, true)) {
    update_option('gds_monitoring_e2e_upload_nonce_life', 2);
} else {
    delete_option('gds_monitoring_e2e_upload_nonce_life');
}

$form = GFAPI::add_form([
    'title' => 'E2E '.wp_generate_password(6, false),
    'enableHoneypot' => true,
    'honeypotAction' => 'spam',
    'fields' => [
        ['id' => 1, 'type' => 'text', 'label' => 'Name', 'isRequired' => true],
        ['id' => 2, 'type' => 'select', 'label' => 'Size', 'validateState' => in_array('validate-state', $args, true), 'choices' => [['text' => 'Small', 'value' => 'small'], ['text' => 'Large', 'value' => 'large']]],
        ['id' => 3, 'type' => 'fileupload', 'label' => 'Files', 'multipleFiles' => true, 'allowedExtensions' => 'txt'],
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
