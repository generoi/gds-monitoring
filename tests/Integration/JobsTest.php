<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Checks\Jobs;
use GeneroWP\Monitoring\Plugin;
use GeneroWP\Monitoring\Tests\Support\TestCase;

class JobsTest extends TestCase
{
    public function tear_down(): void
    {
        delete_option(Jobs::OPTION);
        parent::tear_down();
    }

    /**
     * @return list<string>
     */
    private function jobLines(): array
    {
        return array_values(array_filter($this->lines, fn (string $line): bool => str_contains($line, 'job overdue')));
    }

    public function test_a_job_past_its_max_age_is_reported_twice_a_day(): void
    {
        $now = time();
        do_action('gds/monitoring/completed', 'plm recipes', 26 * HOUR_IN_SECONDS);
        do_action('gds/monitoring/completed', 'weekly export', 8 * DAY_IN_SECONDS);

        for ($hour = 1; $hour <= 40; $hour++) {
            Plugin::schedule()->run($now + $hour * HOUR_IN_SECONDS);
        }

        $this->assertSame([
            'gds-monitoring: job overdue blog 1 plm recipes: last completed 27 h ago',
            'gds-monitoring: job overdue blog 1 plm recipes: last completed 39 h ago',
        ], $this->jobLines());
    }

    public function test_a_job_that_never_completed_is_not_reported(): void
    {
        Plugin::schedule()->run(time() + 100 * HOUR_IN_SECONDS);

        $this->assertSame([], $this->jobLines());
    }
}
