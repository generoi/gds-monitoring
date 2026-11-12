<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;
use GeneroWP\Monitoring\Throttle;

/**
 * Active forms quiet for longer than they have been in 90 days: a form that
 * broke for any reason (a script error, an unpublished page, a captcha
 * failing everyone, a stale cache) shows only as no entries. A form needs 10
 * entries in the 90 days to be judged, and trips when the time since its last
 * entry is 1.5 times its longest gap between entries, and at least a day.
 */
final class FormSilence implements Scheduled
{
    public const HISTORY = 90 * DAY_IN_SECONDS;

    public const MIN_ENTRIES = 10;

    public const FACTOR = 1.5;

    public function perSite(): bool
    {
        return true;
    }

    public function run(int $now): void
    {
        if (! class_exists('GFAPI') || ! class_exists('GFFormsModel')) {
            return;
        }

        foreach (\GFAPI::get_forms(true, false) as $form) {
            $silence = $this->silence((int) $form['id'], $now);
            if ($silence && Throttle::allow('silent-'.$form['id'], 12 * HOUR_IN_SECONDS, $now)) {
                Log::write(sprintf(
                    'gform silent blog %d form %d: no entry for %d h, longest gap in 90 days %d h',
                    get_current_blog_id(),
                    (int) $form['id'],
                    intdiv($silence[0], HOUR_IN_SECONDS),
                    intdiv($silence[1], HOUR_IN_SECONDS)
                ));
            }
        }
    }

    /**
     * The time since the form's last entry and its longest gap before that,
     * when the one is out of line with the other.
     *
     * @return array{int,int}|null
     */
    public function silence(int $formId, int $now): ?array
    {
        global $wpdb;

        // Only the dates: the entries themselves would load every field.
        $table = \GFFormsModel::get_entry_table_name();
        $dates = $wpdb->get_col($wpdb->prepare(
            "SELECT date_created FROM {$table} WHERE form_id = %d AND status = 'active' AND date_created > %s AND date_created <= %s ORDER BY date_created",
            $formId,
            gmdate('Y-m-d H:i:s', $now - self::HISTORY),
            gmdate('Y-m-d H:i:s', $now)
        ));
        if (count($dates) < self::MIN_ENTRIES) {
            return null;
        }

        $times = array_map(fn ($d) => (int) strtotime($d.' UTC'), $dates);
        $longest = 0;
        for ($i = 1; $i < count($times); $i++) {
            $longest = max($longest, $times[$i] - $times[$i - 1]);
        }
        $quiet = $now - end($times);

        return $quiet >= DAY_IN_SECONDS && $quiet > self::FACTOR * $longest ? [$quiet, $longest] : null;
    }
}
