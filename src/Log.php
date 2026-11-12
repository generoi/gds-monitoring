<?php

namespace GeneroWP\Monitoring;

/**
 * One line in the PHP error log per thing a person should look at, where
 * foreman's log watcher picks it up by its prefix. Ids, counts and the
 * plugins' own messages only, never what a visitor typed.
 */
final class Log
{
    public const PREFIX = 'gds-monitoring: ';

    /** Network-wide: lines written where the error log doesn't reach. */
    public const QUEUE = 'gds_monitoring_queue';

    public const QUEUE_MAX = 200;

    public static function write(string $line): void
    {
        $line = self::PREFIX.$line;

        /**
         * Whether to write the line to the error log; tests capture it here.
         */
        if (! apply_filters('gds/monitoring/write', true, $line)) {
            return;
        }

        if (self::deferred()) {
            $queue = get_site_option(self::QUEUE, []);
            $queue = is_array($queue) ? $queue : [];
            $queue[] = $line;
            update_site_option(self::QUEUE, array_slice($queue, -self::QUEUE_MAX));

            return;
        }

        error_log($line);
    }

    /**
     * Writes the queued lines, at the end of an ordinary request: its error
     * log is the web server's, where monitoring reads.
     */
    public static function flush(): void
    {
        if (self::deferred()) {
            return;
        }

        $queue = get_site_option(self::QUEUE, []);
        if (! is_array($queue) || ! $queue) {
            return;
        }

        delete_site_option(self::QUEUE);
        foreach ($queue as $line) {
            error_log((string) $line);
        }
    }

    /**
     * Cron and WP-CLI: wp-cron.php ends the web request before WordPress
     * loads, so the web server no longer takes the error log, and a crontab's
     * WP-CLI usually discards it.
     */
    private static function deferred(): bool
    {
        return wp_doing_cron() || (defined('WP_CLI') && WP_CLI);
    }

    /**
     * A message from a plugin or mail server, on one line, cut short, with
     * what can echo a submission masked: email addresses, double-quoted
     * values (a CRM quoting a field's value) and numbers of 7 or more digits
     * (phone numbers). Short codes such as SMTP's "550 5.1.1" stay.
     */
    public static function clean(string $message, int $max = 100): string
    {
        $message = (string) preg_replace(['/\S+@\S+/', '/"[^"]*"|\x{201C}[^\x{201D}]*\x{201D}/u'], ['<email>', '"<value>"'], $message);
        $message = (string) preg_replace_callback(
            '/\+?\d[\d\s().\/-]{4,}\d/',
            fn (array $m): string => preg_match_all('/\d/', $m[0]) >= 7 ? '<number>' : $m[0],
            $message
        );
        $message = (string) preg_replace('/\s+/', ' ', $message);

        return mb_substr(trim($message), 0, $max);
    }
}
