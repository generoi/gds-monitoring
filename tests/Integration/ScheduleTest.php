<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Plugin;
use GeneroWP\Monitoring\Schedule;
use GeneroWP\Monitoring\Tests\Support\TestCase;

class ScheduleTest extends TestCase
{
    public function test_the_main_site_schedules_the_hourly_run(): void
    {
        wp_clear_scheduled_hook(Schedule::HOOK);

        Plugin::schedule()->ensure();

        $this->assertSame('hourly', wp_get_schedule(Schedule::HOOK));
    }

    /**
     * Once a day, however often the schedule runs.
     */
    public function test_the_heartbeat_is_logged_once_a_day(): void
    {
        $now = time();
        for ($hour = 0; $hour < 30; $hour++) {
            Plugin::schedule()->run($now + $hour * HOUR_IN_SECONDS);
        }

        $this->assertSame(['gds-monitoring: heartbeat', 'gds-monitoring: heartbeat'], $this->lines);
    }
}
