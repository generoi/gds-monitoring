<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;
use WP_Error;

/**
 * Every email WordPress couldn't hand to the mail server (an SMTP error), from
 * any plugin. Only the mailer's message, which can name a recipient.
 */
final class MailFailures implements Check
{
    public function register(): void
    {
        add_action('wp_mail_failed', [$this, 'failed']);
    }

    public function failed(WP_Error $error): void
    {
        Log::write(sprintf('wp_mail failed blog %d: %s', get_current_blog_id(), Log::clean($error->get_error_message())));
    }
}
