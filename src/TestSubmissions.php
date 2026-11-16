<?php

namespace GeneroWP\Monitoring;

/**
 * Submissions made to check that a form works, on the live site: an entry
 * whose email field holds the test address (GDS_MONITORING_TEST_EMAIL, or the
 * gds/monitoring/test_email filter), or that address with a +tag, goes the
 * whole real way (the page, its scripts, the spam checks, saving, mail), but
 * every notification goes to the address typed instead of its recipients, no
 * add-on feed runs (Mailchimp, a CRM, a payment) and the entry is trashed once
 * handled, with a note saying why. Exports and the site's own lists then never
 * show it, and FormSilence, which counts active entries only, never takes a
 * test for a form coming back to life.
 */
final class TestSubmissions
{
    public const NOTE = 'gds-monitoring test submission: its notifications went to the test address, no feed ran, and it was trashed.';

    public function register(): void
    {
        add_filter('gform_notification', [$this, 'notification'], PHP_INT_MAX, 3);
        add_filter('gform_addon_pre_process_feeds', [$this, 'feeds'], PHP_INT_MAX, 3);
        add_action('gform_after_submission', [$this, 'trash'], PHP_INT_MAX, 2);
    }

    /**
     * The configured test address, lowercased; '' when there is none.
     */
    public static function address(): string
    {
        $address = defined('GDS_MONITORING_TEST_EMAIL') ? (string) constant('GDS_MONITORING_TEST_EMAIL') : '';

        return strtolower(trim((string) apply_filters('gds/monitoring/test_email', $address)));
    }

    /**
     * The test address the entry was submitted with (as typed, +tag
     * included), or null for any other entry.
     *
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $form
     */
    public static function of(array $entry, array $form): ?string
    {
        $address = self::address();
        if (! str_contains($address, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $address, 2);
        foreach ($form['fields'] ?? [] as $field) {
            $type = is_object($field) ? $field->type : ($field['type'] ?? '');
            $id = is_object($field) ? $field->id : ($field['id'] ?? '');
            if ($type !== 'email') {
                continue;
            }
            $value = strtolower(trim((string) ($entry[(string) $id] ?? '')));
            if ($value === $address || str_starts_with($value, $local.'+') && str_ends_with($value, '@'.$domain)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A test entry's notification goes to the test address alone, its
     * subject marked, whoever it was for (the site's staff, the visitor).
     *
     * @param  array<string,mixed>  $notification
     * @param  array<string,mixed>  $form
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    public function notification(array $notification, array $form, array $entry): array
    {
        $to = self::of($entry, $form);
        if ($to === null) {
            return $notification;
        }
        $notification['toType'] = 'email';
        $notification['to'] = $to;
        $notification['routing'] = null;
        $notification['cc'] = '';
        $notification['bcc'] = '';
        $notification['subject'] = '[test] '.($notification['subject'] ?? '');

        return $notification;
    }

    /**
     * No add-on feed runs for a test entry: nothing reaches the site's
     * outside services.
     *
     * @param  mixed  $feeds
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $form
     * @return mixed
     */
    public function feeds($feeds, array $entry, array $form)
    {
        return self::of($entry, $form) !== null ? [] : $feeds;
    }

    /**
     * Once Gravity Forms is done with a test entry, it goes to the trash
     * with a note. One caught as spam stays spam (not active either), so the
     * test shows what the spam checks made of it.
     *
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $form
     */
    public function trash(array $entry, array $form): void
    {
        if (self::of($entry, $form) === null || empty($entry['id'])) {
            return;
        }
        \GFAPI::add_note((int) $entry['id'], 0, 'gds-monitoring', self::NOTE);
        if (($entry['status'] ?? '') !== 'spam') {
            \GFAPI::update_entry_property((int) $entry['id'], 'status', 'trash');
        }
    }
}
