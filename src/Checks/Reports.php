<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;
use GeneroWP\Monitoring\Throttle;

/**
 * The one way a problem reaches the log, as `report <source> blog <id>:
 * <message>`. The built-in checks write through it, and so can any plugin, for
 * what only it can see (an import that failed, an API that refused it):
 *
 *     do_action('gds/monitoring/report', 'plm', 'Failed to sync recipe: timed out');
 *
 * A plugin's report is logged once per source in 12 h by default, since one
 * run can report an error per item; a third argument names another throttle
 * key, or false logs every one.
 */
final class Reports implements Check
{
    public const HOOK = 'gds/monitoring/report';

    public const THROTTLE = 12 * HOUR_IN_SECONDS;

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'report'], 10, 3);
    }

    /**
     * From another plugin: its message is cleaned and masked like the rest.
     */
    public function report(string $source, string $message, string|false $throttle = ''): void
    {
        self::write($source, Log::clean($message), $throttle);
    }

    /**
     * From the built-in checks, which clean what came from outside themselves.
     *
     * @param  string|false  $throttle  a key to log once per 12 h by, '' for the source, false for every time
     * @param  int  $now  the scheduled run's time; 0 for now
     */
    public static function write(string $source, string $message, string|false $throttle = '', int $now = 0): void
    {
        if ($throttle !== false && ! Throttle::allow('report-'.($throttle ?: $source), self::THROTTLE, $now ?: time())) {
            return;
        }

        Log::write(sprintf('report %s blog %d: %s', $source, get_current_blog_id(), $message));
    }
}
