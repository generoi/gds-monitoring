<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;
use GeneroWP\Monitoring\Throttle;

/**
 * Active feeds that never ran for recent entries: turned off on the add-on's
 * side, stuck in Gravity Forms' background queue, broken before they could
 * record an error. Entries 1 to 24 hours old, so a feed processed in the
 * background has had its time; a conditional feed counts only entries that
 * meet its condition, as its add-on judges it.
 */
final class FeedsNotRun implements Scheduled
{
    public const GRACE = HOUR_IN_SECONDS;

    public const WINDOW = DAY_IN_SECONDS;

    public function perSite(): bool
    {
        return true;
    }

    public function run(int $now): void
    {
        if (! class_exists('GFAPI') || ! class_exists('GFAddOn')) {
            return;
        }

        $feeds = \GFAPI::get_feeds(null, null, null, true);
        if (! is_array($feeds)) {
            return; // none: a WP_Error
        }

        foreach ($feeds as $feed) {
            $addon = \GFAddOn::get_addon_by_slug((string) $feed['addon_slug']);
            $form = \GFAPI::get_form((int) $feed['form_id']);
            if (! $addon instanceof \GFFeedAddOn || ! is_array($form) || empty($form['is_active'])) {
                continue;
            }

            [$missed, $due] = $this->missed($feed, $form, $addon, $now);
            if ($missed > 0 && Throttle::allow('feed-'.$feed['id'], 12 * HOUR_IN_SECONDS, $now)) {
                Log::write(sprintf(
                    'gform feed not run blog %d form %d feed %d (%s): %d of %d entries in the last 24 h',
                    get_current_blog_id(),
                    (int) $form['id'],
                    (int) $feed['id'],
                    (string) $feed['addon_slug'],
                    $missed,
                    $due
                ));
            }
        }
    }

    /**
     * How many entries of the window the feed should have run for, and of
     * those how many it didn't.
     *
     * @param  array<string,mixed>  $feed
     * @param  array<string,mixed>  $form
     * @return array{int,int}
     */
    private function missed(array $feed, array $form, \GFFeedAddOn $addon, int $now): array
    {
        // A day's margin on the date filter, which Gravity Forms reads in the
        // site's time zone; the window itself is cut from date_created (UTC).
        $entries = \GFAPI::get_entries(
            (int) $form['id'],
            ['status' => 'active', 'start_date' => gmdate('Y-m-d', $now - self::WINDOW - DAY_IN_SECONDS)],
            ['key' => 'date_created', 'direction' => 'DESC'],
            ['offset' => 0, 'page_size' => 500]
        );
        if (! is_array($entries)) {
            return [0, 0];
        }

        $missed = $due = 0;
        foreach ($entries as $entry) {
            $age = $now - (int) strtotime($entry['date_created'].' UTC');
            if ($age < self::GRACE || $age > self::WINDOW || ! $addon->is_feed_condition_met($feed, $form, $entry)) {
                continue;
            }
            $due++;
            $processed = gform_get_meta((int) $entry['id'], 'processed_feeds');
            $ran = is_array($processed) ? (array) ($processed[$feed['addon_slug']] ?? []) : [];
            if (! in_array((int) $feed['id'], array_map('intval', $ran), true)) {
                $missed++;
            }
        }

        return [$missed, $due];
    }
}
