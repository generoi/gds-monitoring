<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Checks\FormSilence;
use GeneroWP\Monitoring\Tests\Support\TestCase;

class FormSilenceTest extends TestCase
{
    /**
     * $count entries every $every seconds, the last one $ago seconds ago.
     */
    private function history(int $formId, int $now, int $count, int $every, int $ago): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->entry($formId, $now - $ago - $i * $every);
        }
    }

    public function test_a_form_quiet_far_longer_than_ever_is_logged(): void
    {
        $now = time();
        $formId = $this->form();
        $this->history($formId, $now, 20, 2 * HOUR_IN_SECONDS, 3 * DAY_IN_SECONDS);

        (new FormSilence)->run($now);

        $this->assertSame(["gds-monitoring: gform silent blog 1 form {$formId}: no entry for 72 h, longest gap in 90 days 2 h"], $this->lines);
    }

    public function test_a_quiet_spell_like_earlier_ones_is_not(): void
    {
        $now = time();
        $formId = $this->form();
        $this->history($formId, $now, 20, 2 * DAY_IN_SECONDS, 2 * DAY_IN_SECONDS);

        (new FormSilence)->run($now);

        $this->assertSame([], $this->lines);
    }

    /**
     * Under a day is never silence, however busy the form is.
     */
    public function test_less_than_a_day_is_not_silence(): void
    {
        $now = time();
        $formId = $this->form();
        $this->history($formId, $now, 30, 10 * MINUTE_IN_SECONDS, 20 * HOUR_IN_SECONDS);

        (new FormSilence)->run($now);

        $this->assertSame([], $this->lines);
    }

    public function test_a_form_with_too_little_history_is_not_judged(): void
    {
        $now = time();
        $formId = $this->form();
        $this->history($formId, $now, 9, HOUR_IN_SECONDS, 10 * DAY_IN_SECONDS);

        (new FormSilence)->run($now);

        $this->assertSame([], $this->lines);
    }

    /**
     * Spam doesn't count as an entry, and an inactive form isn't checked.
     */
    public function test_spam_and_inactive_forms_dont_count(): void
    {
        $now = time();
        $formId = $this->form();
        $this->history($formId, $now, 20, 2 * HOUR_IN_SECONDS, 3 * DAY_IN_SECONDS);
        $this->entry($formId, $now - HOUR_IN_SECONDS, 'spam');
        $inactive = $this->form();
        $this->history($inactive, $now, 20, 2 * HOUR_IN_SECONDS, 3 * DAY_IN_SECONDS);
        \GFAPI::update_form_property($inactive, 'is_active', 0);

        (new FormSilence)->run($now);

        $this->assertCount(1, $this->lines);
        $this->assertStringContainsString("form {$formId}:", $this->lines[0]);
    }

    /**
     * On a network site where Gravity Forms is off, its tables don't exist:
     * no query may touch them, or each one is a database error in the log.
     */
    public function test_a_site_without_gravity_forms_active_is_not_queried(): void
    {
        $now = time();
        $formId = $this->form();
        $this->history($formId, $now, 20, 2 * HOUR_IN_SECONDS, 3 * DAY_IN_SECONDS);
        update_option('active_plugins', []);
        $queries = [];
        add_filter('query', function (string $query) use (&$queries): string {
            if (str_contains($query, 'gf_')) {
                $queries[] = $query;
            }

            return $query;
        });

        (new FormSilence)->run($now);

        $this->assertSame([], $queries);
        $this->assertSame([], $this->lines);
    }

    public function test_a_lasting_silence_is_logged_twice_a_day(): void
    {
        $now = time();
        $formId = $this->form();
        $this->history($formId, $now, 20, 2 * HOUR_IN_SECONDS, 3 * DAY_IN_SECONDS);

        for ($hour = 0; $hour < 24; $hour++) {
            (new FormSilence)->run($now + $hour * HOUR_IN_SECONDS);
        }

        $this->assertCount(2, $this->lines);
    }
}
