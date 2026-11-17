<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;
use GeneroWP\Monitoring\Site;
use GeneroWP\Monitoring\Throttle;

/**
 * Active forms quiet for longer than they have been in 90 days: a form that
 * broke for any reason (a script error, an unpublished page, a captcha
 * failing everyone, a stale cache) shows only as no entries. A form needs 10
 * entries in the 90 days to be judged, and trips when the time since its last
 * entry is 1.5 times its longest gap between entries, and at least a day.
 *
 * Only placed forms are judged: one in a published post's content (block or
 * shortcode), in a synced pattern a published post uses, or in a block
 * widget, or viewed in the last 30 days (a form placed in theme code leaves
 * no trace in content). A campaign form taken off its page stays quiet for
 * good, and that's not a form breaking.
 */
final class FormSilence implements Scheduled
{
    public const HISTORY = 90 * DAY_IN_SECONDS;

    public const MIN_ENTRIES = 10;

    public const FACTOR = 1.5;

    /** The transient holding the placed forms. */
    public const PLACED = 'gds_monitoring_placed_forms';

    public function perSite(): bool
    {
        return true;
    }

    public function run(int $now): void
    {
        if (! class_exists('GFAPI') || ! class_exists('GFFormsModel') || ! Site::gravityForms()) {
            return;
        }

        $placed = null;
        foreach (\GFAPI::get_forms(true, false) as $form) {
            $silence = $this->silence((int) $form['id'], $now);
            if ($silence) {
                $placed ??= $this->placed($now);
                if (! isset($placed[(int) $form['id']])) {
                    continue;
                }
            }
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

    /**
     * The forms a visitor can reach, keyed by id.
     *
     * @return array<int,true>
     */
    public function placed(int $now): array
    {
        global $wpdb;

        // Content has no index to search by: the scan runs only when a form
        // trips, and its answer lasts as long as the line's throttle.
        $cached = get_transient(self::PLACED);
        if (is_array($cached)) {
            return $cached;
        }

        $placed = [];
        $patterns = []; // a synced pattern's id => the forms in it
        $rows = $wpdb->get_results(
            "SELECT ID, post_type, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type <> 'revision' AND post_content LIKE '%gravityform%'"
        );
        foreach ($rows as $row) {
            if ($row->post_type === 'wp_block') {
                $patterns[(int) $row->ID] = self::forms($row->post_content);
            } else {
                $placed += array_fill_keys(self::forms($row->post_content), true);
            }
        }

        // A pattern's forms are placed when a published post uses it, or a
        // pattern that one uses, a few levels deep: one scan a level.
        for ($depth = 0; $patterns && $depth < 3; $depth++) {
            $ids = implode('|', array_keys($patterns));
            $users = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_type, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type <> 'revision' AND post_content REGEXP %s",
                '"ref":('.$ids.')[^0-9]'
            ));
            $next = [];
            foreach ($users as $user) {
                preg_match_all('/"ref":('.$ids.')\b/', $user->post_content, $refs);
                $forms = array_merge(...array_map(fn ($id) => $patterns[(int) $id], $refs[1]));
                if ($user->post_type === 'wp_block') {
                    $next[(int) $user->ID] = array_merge($next[(int) $user->ID] ?? [], $forms);
                } else {
                    $placed += array_fill_keys($forms, true);
                }
            }
            $patterns = $next;
        }

        foreach ((array) get_option('widget_block', []) as $widget) {
            if (is_array($widget) && isset($widget['content']) && is_string($widget['content'])) {
                $placed += array_fill_keys(self::forms($widget['content']), true);
            }
        }

        $viewed = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT form_id FROM '.\GFFormsModel::get_form_view_table_name().' WHERE date_created > %s',
            gmdate('Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS)
        ));

        $placed += array_fill_keys(array_map('intval', $viewed), true);
        set_transient(self::PLACED, $placed, 12 * HOUR_IN_SECONDS);

        return $placed;
    }

    /**
     * The ids of the forms a post's content embeds, by block or shortcode.
     *
     * @return list<int>
     */
    public static function forms(string $content): array
    {
        preg_match_all('/wp:gravityforms\/form\s+\{[^}]*"formId":"?(\d+)/', $content, $blocks);
        preg_match_all('/\[gravityforms?\s[^\]]*\bid=["\']?(\d+)/', $content, $shortcodes);

        return array_values(array_unique(array_map('intval', [...$blocks[1], ...$shortcodes[1]])));
    }
}
