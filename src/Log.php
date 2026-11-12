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
     * email addresses masked: it can echo what was submitted.
     */
    public static function clean(string $message, int $max = 100): string
    {
        $message = (string) preg_replace(['/\S+@\S+/', '/\s+/'], ['<email>', ' '], $message);

        return mb_substr(trim($message), 0, $max);
    }
}
