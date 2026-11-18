<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;
use GeneroWP\Monitoring\Throttle;

/**
 * The beCPG import of wp-plm: an error or warning it logs (an entity beCPG
 * didn't return, an image that failed to download, a run with failures), and
 * hourly, a type whose full sync hasn't completed for longer than its limit.
 * A broken import otherwise shows only as recipes or products that stop
 * changing, which nobody notices.
 *
 * Only on a site where wp-plm recorded a completed sync, so a site without
 * the importer, or one not scheduled to run it, is never reported.
 */
final class PlmSync implements Check, Scheduled
{
    /** Set by wp-plm: ['products' => timestamp, 'recipes' => timestamp]. */
    public const OPTION = 'plm_last_completed';

    /** A daily import that missed one run. */
    public const STALE_AFTER = 26 * HOUR_IN_SECONDS;

    public function register(): void
    {
        add_action('plm/log', [$this, 'logged'], 10, 3);
    }

    /**
     * @param  array<mixed>  $context
     */
    public function logged(string $message, array $context = [], string $level = 'log'): void
    {
        if ($level !== 'error' && $level !== 'warning') {
            return;
        }

        // One run can log an error per entity; the first one in a while says
        // enough, and the run's own summary warning gives the count.
        if (! Throttle::allow('plm-'.$level, 12 * HOUR_IN_SECONDS, time())) {
            return;
        }

        Log::write(sprintf('plm %s blog %d: %s', $level, get_current_blog_id(), Log::clean($message)));
    }

    public function perSite(): bool
    {
        return true;
    }

    public function run(int $now): void
    {
        $last = get_option(self::OPTION, []);
        if (! is_array($last)) {
            return;
        }

        foreach ($last as $type => $completed) {
            /**
             * How long a type may go without a completed full sync.
             */
            $limit = (int) apply_filters('gds/monitoring/plm_stale_after', self::STALE_AFTER, (string) $type);
            $age = $now - (int) $completed;

            if ($age > $limit && Throttle::allow('plm-stale-'.$type, 12 * HOUR_IN_SECONDS, $now)) {
                Log::write(sprintf('plm stale blog %d %s: last complete sync %d h ago', get_current_blog_id(), $type, intdiv($age, HOUR_IN_SECONDS)));
            }
        }
    }
}
