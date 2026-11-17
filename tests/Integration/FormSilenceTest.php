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

    /**
     * A post of $type holding $content; a page with the form's block when
     * no content is given.
     */
    private function place(int $formId, string $status = 'publish', string $type = 'page', ?string $content = null): int
    {
        return self::factory()->post->create([
            'post_type' => $type,
            'post_status' => $status,
            'post_content' => $content ?? '<!-- wp:gravityforms/form {"formId":"'.$formId.'","title":false} /-->',
        ]);
    }

    /**
     * A form tripping the silence rule.
     */
    private function quietForm(int $now): int
    {
        $formId = $this->form();
        $this->history($formId, $now, 20, 2 * HOUR_IN_SECONDS, 3 * DAY_IN_SECONDS);

        return $formId;
    }

    public function test_a_form_quiet_far_longer_than_ever_is_logged(): void
    {
        $now = time();
        $formId = $this->form();
        $this->place($formId);
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
        $this->place($formId);
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
        $this->place($formId);
        $this->history($formId, $now, 20, 2 * HOUR_IN_SECONDS, 3 * DAY_IN_SECONDS);

        for ($hour = 0; $hour < 24; $hour++) {
            (new FormSilence)->run($now + $hour * HOUR_IN_SECONDS);
        }

        $this->assertCount(2, $this->lines);
    }

    /**
     * A form on no published page, such as a finished campaign's taken off
     * its page or left on a draft, is quiet for good: not judged.
     */
    public function test_a_form_on_no_published_page_is_not_judged(): void
    {
        $now = time();
        $this->quietForm($now);
        $draft = $this->quietForm($now);
        $this->place($draft, 'draft');

        (new FormSilence)->run($now);

        $this->assertSame([], $this->lines);
    }

    public function test_a_shortcode_places_a_form(): void
    {
        $now = time();
        $formId = $this->quietForm($now);
        $this->place($formId, content: '[gravityform id="'.$formId.'" title="false"]');

        (new FormSilence)->run($now);

        $this->assertCount(1, $this->lines);
    }

    /**
     * A form in a synced pattern is placed by a published page using the
     * pattern, even through another pattern; an unused pattern places nothing.
     */
    public function test_a_synced_pattern_places_a_form_when_a_page_uses_it(): void
    {
        $now = time();
        $used = $this->quietForm($now);
        $pattern = $this->place($used, type: 'wp_block');
        $outer = $this->place($used, type: 'wp_block', content: '<!-- wp:block {"ref":'.$pattern.'} /-->');
        $this->place($used, content: '<!-- wp:block {"ref":'.$outer.'} /-->');
        $unused = $this->quietForm($now);
        $lonely = $this->place($unused, type: 'wp_block');
        $this->place($unused, content: '<!-- wp:block {"ref":'.$lonely.'0} /-->'); // another pattern's id

        (new FormSilence)->run($now);

        $this->assertCount(1, $this->lines);
        $this->assertStringContainsString("form {$used}:", $this->lines[0]);
    }

    public function test_a_block_widget_places_a_form(): void
    {
        $now = time();
        $formId = $this->quietForm($now);
        update_option('widget_block', [2 => ['content' => '<!-- wp:gravityforms/form {"formId":'.$formId.'} /-->']]);

        (new FormSilence)->run($now);

        $this->assertCount(1, $this->lines);
    }

    /**
     * A form placed in theme code leaves nothing in content, but its views.
     */
    public function test_a_recently_viewed_form_is_placed(): void
    {
        $now = time();
        $formId = $this->quietForm($now);
        \GFFormsModel::insert_form_view($formId);

        (new FormSilence)->run($now);

        $this->assertCount(1, $this->lines);
    }
}
