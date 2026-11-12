<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Checks\FeedsNotRun;
use GeneroWP\Monitoring\Tests\Support\TestCase;

class FeedsNotRunTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $meta
     */
    private function feed(int $formId, array $meta = []): int
    {
        $id = \GFAPI::add_feed($formId, $meta + ['feedName' => 'Test'], 'gdstestfeed');
        $this->assertIsInt($id);

        return $id;
    }

    private function ran(int $entryId, int $feedId): void
    {
        gform_update_meta($entryId, 'processed_feeds', ['gdstestfeed' => [$feedId]]);
    }

    public function test_entries_the_feed_never_ran_for_are_counted(): void
    {
        $now = time();
        $formId = $this->form();
        $feedId = $this->feed($formId);
        $this->ran($this->entry($formId, $now - 3 * HOUR_IN_SECONDS), $feedId);
        $this->entry($formId, $now - 5 * HOUR_IN_SECONDS); // missed
        $this->entry($formId, $now - 10 * MINUTE_IN_SECONDS); // still has its grace
        $this->entry($formId, $now - 30 * HOUR_IN_SECONDS); // before the window

        (new FeedsNotRun)->run($now);

        $this->assertSame(["gds-monitoring: gform feed not run blog 1 form {$formId} feed {$feedId} (gdstestfeed): 1 of 2 entries in the last 24 h"], $this->lines);
    }

    public function test_a_feed_that_ran_for_every_entry_is_quiet(): void
    {
        $now = time();
        $formId = $this->form();
        $feedId = $this->feed($formId);
        $this->ran($this->entry($formId, $now - 3 * HOUR_IN_SECONDS), $feedId);

        (new FeedsNotRun)->run($now);

        $this->assertSame([], $this->lines);
    }

    /**
     * A conditional feed is owed only the entries meeting its condition, as
     * the add-on judges it.
     */
    public function test_a_conditional_feed_counts_only_entries_meeting_its_condition(): void
    {
        $now = time();
        $formId = $this->form();
        $this->feed($formId, [
            'feed_condition_conditional_logic' => true,
            'feed_condition_conditional_logic_object' => ['conditionalLogic' => [
                'actionType' => 'show', 'logicType' => 'all',
                'rules' => [['fieldId' => '1', 'operator' => 'is', 'value' => 'John']],
            ]],
        ]);
        $this->entry($formId, $now - 3 * HOUR_IN_SECONDS); // Jane: not for this feed

        (new FeedsNotRun)->run($now);

        $this->assertSame([], $this->lines);
    }

    public function test_a_lasting_miss_is_logged_twice_a_day(): void
    {
        $now = time();
        $formId = $this->form();
        $this->feed($formId);
        $this->entry($formId, $now - 3 * HOUR_IN_SECONDS);

        (new FeedsNotRun)->run($now);
        (new FeedsNotRun)->run($now + HOUR_IN_SECONDS);
        (new FeedsNotRun)->run($now + 12 * HOUR_IN_SECONDS);

        $this->assertCount(2, $this->lines);
    }

    public function test_inactive_feeds_and_forms_are_ignored(): void
    {
        $now = time();
        $formId = $this->form();
        $feedId = $this->feed($formId);
        \GFAPI::update_feed_property($feedId, 'is_active', 0);
        $other = $this->form();
        $this->feed($other);
        \GFAPI::update_form_property($other, 'is_active', 0);
        $this->entry($formId, $now - 3 * HOUR_IN_SECONDS);
        $this->entry($other, $now - 3 * HOUR_IN_SECONDS);

        (new FeedsNotRun)->run($now);

        $this->assertSame([], $this->lines);
    }
}
