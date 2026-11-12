<?php

namespace GeneroWP\Monitoring;

/**
 * At most one line per key and interval, so a condition that lasts days logs
 * twice a day rather than every hour. Kept per site.
 */
final class Throttle
{
    public const OPTION = 'gds_monitoring_throttle';

    public static function allow(string $key, int $every, int $now): bool
    {
        $last = get_option(self::OPTION, []);
        $last = is_array($last) ? $last : [];

        if (isset($last[$key]) && $now - (int) $last[$key] < $every) {
            return false;
        }

        $last[$key] = $now;
        update_option(self::OPTION, $last, false);

        return true;
    }
}
