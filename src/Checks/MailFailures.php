<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;
use WP_Error;

/**
 * Every email WordPress couldn't hand to the mail server (an SMTP error), from
 * any plugin. Only the mailer's message, which can name a recipient.
 *
 * Postmark's plugin replaces wp_mail() and never fires wp_mail_failed: on a
 * failed send it fires its own postmark_error, with the API response or the
 * HTTP error, so that's listened to as well.
 */
final class MailFailures implements Check
{
    public function register(): void
    {
        add_action('wp_mail_failed', [$this, 'failed']);
        add_action('postmark_error', [$this, 'postmark']);
    }

    public function failed(WP_Error $error): void
    {
        Reports::write('wp_mail', 'failed: '.Log::clean($error->get_error_message()), false);
    }

    /**
     * @param  array<string,mixed>|WP_Error  $response  Postmark's wp_remote_post() result
     */
    public function postmark($response): void
    {
        if (is_wp_error($response)) {
            $message = $response->get_error_message();
        } else {
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            $message = sprintf(
                'API %s: %s',
                wp_remote_retrieve_response_code($response),
                is_array($body) && isset($body['Message']) ? $body['Message'] : 'no message'
            );
        }

        Reports::write('wp_mail', 'failed: Postmark '.Log::clean($message), false);
    }
}
