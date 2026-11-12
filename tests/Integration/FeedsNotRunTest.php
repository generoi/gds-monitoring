<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Checks\FeedsNotRun;
use GeneroWP\Monitoring\Tests\Support\TestCase;

class FeedsNotRunTest extends TestCase
{
    /**
     * A feed active for the last two days, unless $new.
     *
     * @param  array<string,mixed>  $meta
     */
    private function feed(int $formId, array $meta = [], bool $new = false): int
    {
        $id = \GFAPI::add_feed($formId, $meta + ['feedName' => 'Test'], 'gdstestfeed');
        $this->assertIsInt($id);
        if (! $new) {
            update_option(FeedsNotRun::SEEN, [$id => time() - 2 * DAY_IN_SECONDS] + (array) get_option(FeedsNotRun::SEEN, []));
        }

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

    /**
     * Gravity Forms keeps no date for a feed: entries from before it was
     * first seen active weren't its to run.
     */
    public function test_a_new_feed_is_owed_only_entries_after_it_was_first_seen(): void
    {
        $now = time();
        $formId = $this->form();
        $this->feed($formId, [], true);
        $this->entry($formId, $now - 3 * HOUR_IN_SECONDS);

        (new FeedsNotRun)->run($now);
        $this->entry($formId, $now + HOUR_IN_SECONDS);
        (new FeedsNotRun)->run($now + 3 * HOUR_IN_SECONDS);

        $this->assertCount(1, $this->lines);
        $this->assertStringEndsWith('1 of 1 entries in the last 24 h', $this->lines[0]);
    }

    public function test_a_site_whose_last_feed_is_switched_off_forgets_it(): void
    {
        $feedId = $this->feed($this->form());
        \GFAPI::update_feed_property($feedId, 'is_active', 0);

        (new FeedsNotRun)->run(time());

        $this->assertFalse(get_option(FeedsNotRun::SEEN));
    }

    /**
     * Gravity Forms runs an entry's feeds when it's marked not spam; one
     * that then didn't run is owed like any other.
     */
    public function test_an_entry_restored_from_spam_is_owed_its_feeds(): void
    {
        $now = time();
        $formId = $this->form();
        $feedId = $this->feed($formId);
        $restored = $this->entry($formId, $now - 3 * HOUR_IN_SECONDS, 'spam');
        \GFFormsModel::change_entry_status($restored, 'active');

        $processed = gform_get_meta($restored, 'processed_feeds');
        $this->assertSame([$feedId], array_map('intval', (array) ($processed['gdstestfeed'] ?? [])));

        (new FeedsNotRun)->run($now);
        $this->assertSame([], $this->lines);

        gform_delete_meta($restored, 'processed_feeds');
        (new FeedsNotRun)->run($now);
        $this->assertCount(1, $this->lines);
    }

    /**
     * A feed left on where its add-on is off on this site never runs; that's
     * not the feed failing.
     */
    public function test_a_feed_whose_add_on_is_off_on_the_site_is_ignored(): void
    {
        $now = time();
        $formId = $this->form();
        $this->feed($formId);
        $this->entry($formId, $now - 3 * HOUR_IN_SECONDS);
        // As an add-on installed in the plugins folder but not activated here.
        $addon = \GFAddOn::get_addon_by_slug('gdstestfeed');
        $path = new \ReflectionProperty($addon, '_path');
        $was = $path->getValue($addon);
        $path->setValue($addon, 'gds-monitoring/gds-monitoring.php');
        update_option('active_plugins', [plugin_basename(\GFCommon::get_base_path().'/gravityforms.php')]);

        (new FeedsNotRun)->run($now);
        $path->setValue($addon, $was);

        $this->assertSame([], $this->lines);
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
