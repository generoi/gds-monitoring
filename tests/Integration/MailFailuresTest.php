<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Tests\Support\TestCase;
use WP_Error;

class MailFailuresTest extends TestCase
{
    public function test_an_smtp_error_is_logged_with_addresses_masked(): void
    {
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', 'SMTP Error: The following recipients failed: jane@example.com: Mailbox unavailable'));

        $this->assertSame(['gds-monitoring: wp_mail failed blog 1: SMTP Error: The following recipients failed: <email> Mailbox unavailable'], $this->lines);
    }
}
