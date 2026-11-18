<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;
use GeneroWP\Monitoring\Throttle;

/**
 * A problem another plugin reports, for the things only it can see (an import
 * that failed, an API that refused it):
 *
 *     do_action('gds/monitoring/report', 'plm', 'Failed to sync recipe: timed out');
 *
 * The first report per source in 12 h is logged; one run can report an error
 * per item, and the first says enough.
 */
final class Reports implements Check
{
    public const HOOK = 'gds/monitoring/report';

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'report'], 10, 2);
    }

    public function report(string $source, string $message): void
    {
        if (Throttle::allow('report-'.$source, 12 * HOUR_IN_SECONDS, time())) {
            Log::write(sprintf('report %s blog %d: %s', $source, get_current_blog_id(), Log::clean($message)));
        }
    }
}
