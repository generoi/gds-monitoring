<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;
use GeneroWP\Monitoring\Throttle;

/**
 * One line a day, so monitoring can tell a quiet site from one where cron,
 * PHP or this plugin stopped.
 */
final class Heartbeat implements Scheduled
{
    public function perSite(): bool
    {
        return false;
    }

    public function run(int $now): void
    {
        if (Throttle::allow('heartbeat', DAY_IN_SECONDS, $now)) {
            Log::write('heartbeat');
        }
    }
}
