<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Log;
use GeneroWP\Monitoring\Tests\Support\TestCase;

class LogTest extends TestCase
{
    public function test_what_can_echo_a_submission_is_masked(): void
    {
        $this->assertSame(
            'Property "<value>" is invalid for <email> phone <number> or <number>.',
            Log::clean('Property "Jane Doe" is invalid for jane@example.com, phone +358 40 123 4567 or 0401234567.', 200)
        );
        $this->assertSame('Bad value "<value>"', Log::clean("Bad value \u{201C}Jane\u{201D}"));
    }

    public function test_codes_sizes_and_plain_text_stay(): void
    {
        $this->assertSame(
            "SMTP Error: 550 5.1.1 Mailbox unavailable. Maximum file size: 20MB. Couldn't send it.",
            Log::clean("SMTP Error: 550 5.1.1 Mailbox unavailable.\nMaximum file size: 20MB. Couldn't send it.", 200)
        );
    }

    private ?string $errorLog = null;

    public function tear_down(): void
    {
        if ($this->errorLog !== null) {
            ini_set('error_log', $this->errorLog);
        }
        parent::tear_down();
    }

    /**
     * The real error_log(), into a file of the test's, without the capture.
     */
    private function logFile(): string
    {
        remove_all_filters('gds/monitoring/write');
        $file = (string) wp_tempnam('gds-monitoring');
        $this->errorLog = (string) ini_get('error_log');
        ini_set('error_log', $file);

        return $file;
    }

    /**
     * wp-cron.php ends the web request before WordPress loads, and a
     * crontab's WP-CLI discards stderr: the line waits for a request whose
     * error log the web server keeps.
     */
    public function test_a_line_written_in_cron_is_logged_by_the_next_request(): void
    {
        $file = $this->logFile();
        add_filter('wp_doing_cron', '__return_true');

        Log::write('heartbeat');

        $this->assertSame(['gds-monitoring: heartbeat'], get_site_option(Log::QUEUE));
        $this->assertSame('', (string) file_get_contents($file));

        Log::flush(); // still in cron: waits
        $this->assertSame('', (string) file_get_contents($file));

        remove_filter('wp_doing_cron', '__return_true');
        Log::flush();

        $this->assertStringContainsString('gds-monitoring: heartbeat', (string) file_get_contents($file));
        $this->assertFalse(get_site_option(Log::QUEUE));
    }

    public function test_the_queue_keeps_the_latest_lines(): void
    {
        $this->logFile();
        add_filter('wp_doing_cron', '__return_true');

        for ($i = 1; $i <= Log::QUEUE_MAX + 5; $i++) {
            Log::write("line {$i}");
        }

        $queue = get_site_option(Log::QUEUE);
        $this->assertCount(Log::QUEUE_MAX, $queue);
        $this->assertSame('gds-monitoring: line 6', $queue[0]);
    }

    public function test_a_line_outside_cron_is_logged_at_once(): void
    {
        $file = $this->logFile();

        Log::write('heartbeat');

        $this->assertStringContainsString('gds-monitoring: heartbeat', (string) file_get_contents($file));
        $this->assertFalse(get_site_option(Log::QUEUE));
    }

    public function test_a_long_message_is_cut(): void
    {
        $this->assertSame(100, mb_strlen(Log::clean(str_repeat('a ', 100))));
    }
}
