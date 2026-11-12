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

    public static function write(string $line): void
    {
        $line = self::PREFIX.$line;

        /**
         * Whether to write the line to the error log; tests capture it here.
         */
        if (apply_filters('gds/monitoring/write', true, $line)) {
            error_log($line);
        }
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
