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

    public function test_a_long_message_is_cut(): void
    {
        $this->assertSame(100, mb_strlen(Log::clean(str_repeat('a ', 100))));
    }
}
