<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;

/**
 * Gravity Forms entries marked as spam, with the filter and its reason, so a
 * real person caught by a spam filter (a stale page failing state validation)
 * is seen and can be marked not spam. Reasons only bots give are skipped:
 * hundreds a month on some sites.
 */
final class SpamEntries implements Check
{
    public function register(): void
    {
        add_action('gform_entry_created', [$this, 'entryCreated'], 10, 2);
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $form
     */
    public function entryCreated(array $entry, array $form): void
    {
        if (($entry['status'] ?? '') !== 'spam' || ! class_exists('GFFormDisplay')) {
            return;
        }

        $formId = (int) $form['id'];
        $spam = \GFFormDisplay::$submission[$formId]['spam_filter'] ?? [];
        // CleanTalk records no spam filter, only its own verdict.
        if (! $spam && ! empty($GLOBALS['ct_gform_is_spam'])) {
            $spam = ['filter' => 'CleanTalk', 'reason' => wp_strip_all_tags((string) ($GLOBALS['ct_gform_response'] ?? ''))];
        }

        $reason = Log::clean((string) ($spam['reason'] ?? ''), PHP_INT_MAX);
        if ($this->isBot($reason)) {
            return;
        }

        Log::write(sprintf(
            'gform spam blog %d form %d entry %d (%s): %s',
            get_current_blog_id(),
            $formId,
            (int) $entry['id'],
            (string) ($spam['filter'] ?? 'unknown'),
            mb_substr($reason ?: 'no reason recorded', 0, 100)
        ));
    }

    /**
     * The reasons only bots give, as the plugins word them on this site.
     *
     * @return list<string>
     */
    public function botReasons(): array
    {
        return [
            __('The honeypot input is not empty.', 'gravityforms'),
            __('The version_hash was not included in the submission.', 'gravityforms'),
            __('The gform_submission_speeds input is empty or invalid.', 'gravityforms'),
            __('%1$d of %2$d submissions met the speed check threshold (%3$d ms). Min required: %4$d. All speeds (ms): %5$s.', 'gravityforms'),
            __('Suspicious activity detected: multiple submissions from the same IP address and User-Agent, all at the same submission speed.', 'gravityforms'),
            __('Score of %1$s is less than or equal to the threshold of %2$s.', 'gravityformsrecaptcha'),
        ];
    }

    private function isBot(string $reason): bool
    {
        foreach ($this->botReasons() as $text) {
            // A placeholder (%d, %1$s) matches anything.
            $pattern = preg_replace('/%(\d+\\\\\$)?[ds]/', '.*', preg_quote($text, '/'));
            if (preg_match('/^'.$pattern.'$/', $reason)) {
                return true;
            }
        }

        return false;
    }
}
