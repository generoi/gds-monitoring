<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Tests\Support\TestCase;
use WP_Error;

class MailFailuresTest extends TestCase
{
    public function test_an_smtp_error_is_logged_with_addresses_masked(): void
    {
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', 'SMTP Error: The following recipients failed: jane@example.com: Mailbox unavailable'));

        $this->assertSame(['gds-monitoring: report wp_mail blog 1: failed: SMTP Error: The following recipients failed: <email> Mailbox unavailable'], $this->lines);
    }

    public function test_a_postmark_api_refusal_is_logged(): void
    {
        do_action('postmark_error', [
            'response' => ['code' => 422, 'message' => 'Unprocessable Entity'],
            'body' => '{"ErrorCode":406,"Message":"You tried to send to recipient(s) that have been marked as inactive."}',
        ], []);

        $this->assertSame(['gds-monitoring: report wp_mail blog 1: failed: Postmark API 422: You tried to send to recipient(s) that have been marked as inactive.'], $this->lines);
    }

    public function test_a_postmark_connection_error_is_logged(): void
    {
        do_action('postmark_error', new WP_Error('http_request_failed', 'cURL error 28: Operation timed out'), []);

        $this->assertSame(['gds-monitoring: report wp_mail blog 1: failed: Postmark cURL error 28: Operation timed out'], $this->lines);
    }
}
