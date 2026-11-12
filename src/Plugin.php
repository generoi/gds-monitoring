<?php

namespace GeneroWP\Monitoring;

use GeneroWP\Monitoring\Checks\Check;
use GeneroWP\Monitoring\Checks\EntryErrorNotes;
use GeneroWP\Monitoring\Checks\FeedsNotRun;
use GeneroWP\Monitoring\Checks\FormRefusals;
use GeneroWP\Monitoring\Checks\FormSilence;
use GeneroWP\Monitoring\Checks\Heartbeat;
use GeneroWP\Monitoring\Checks\MailFailures;
use GeneroWP\Monitoring\Checks\Scheduled;
use GeneroWP\Monitoring\Checks\SpamEntries;

final class Plugin
{
    private static ?Schedule $schedule = null;

    /**
     * @return list<Check|Scheduled>
     */
    public static function checks(): array
    {
        return [
            new SpamEntries,
            new EntryErrorNotes,
            new FormRefusals,
            new MailFailures,
            new FeedsNotRun,
            new FormSilence,
            new Heartbeat,
        ];
    }

    public static function boot(): void
    {
        if (self::$schedule) {
            return;
        }

        $scheduled = [];
        foreach (self::checks() as $check) {
            if ($check instanceof Check) {
                $check->register();
            }
            if ($check instanceof Scheduled) {
                $scheduled[] = $check;
            }
        }

        self::$schedule = new Schedule($scheduled);
        self::$schedule->register();

        add_action('shutdown', [Log::class, 'flush']);
    }

    public static function schedule(): Schedule
    {
        self::boot();

        return self::$schedule;
    }
}
