<?php

namespace GeneroWP\Monitoring\Checks;

/**
 * A recurring job another plugin runs (an import, a sync, an export) tells
 * this each time it completes, with how long it may go without:
 *
 *     do_action('gds/monitoring/completed', 'plm recipes', 26 * HOUR_IN_SECONDS);
 *
 * Hourly, a job that hasn't completed within that time is logged. A job is
 * only watched once it has completed here, so one that never ran on a site
 * is never reported.
 */
final class Jobs implements Check, Scheduled
{
    public const HOOK = 'gds/monitoring/completed';

    /** Per site: [job => ['at' => timestamp, 'max_age' => seconds]]. */
    public const OPTION = 'gds_monitoring_jobs';

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'completed'], 10, 2);
    }

    public function completed(string $job, int $maxAge): void
    {
        $jobs = get_option(self::OPTION, []);
        $jobs = is_array($jobs) ? $jobs : [];
        $jobs[$job] = ['at' => time(), 'max_age' => $maxAge];
        update_option(self::OPTION, $jobs, false);
    }

    public function perSite(): bool
    {
        return true;
    }

    public function run(int $now): void
    {
        $jobs = get_option(self::OPTION, []);
        if (! is_array($jobs)) {
            return;
        }

        foreach ($jobs as $job => $last) {
            $age = $now - (int) ($last['at'] ?? $now);
            if ($age > (int) ($last['max_age'] ?? PHP_INT_MAX)) {
                Reports::write('job', sprintf('overdue %s: last completed %d h ago', $job, intdiv($age, HOUR_IN_SECONDS)), 'job-'.$job, $now);
            }
        }
    }
}
