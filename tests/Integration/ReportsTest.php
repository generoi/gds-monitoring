<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Tests\Support\TestCase;

class ReportsTest extends TestCase
{
    public function test_the_first_report_per_source_in_12_hours_is_logged(): void
    {
        do_action('gds/monitoring/report', 'plm', 'Failed to sync recipe: cURL error 28: timed out');
        do_action('gds/monitoring/report', 'plm', 'Failed to sync recipe: cURL error 6');
        do_action('gds/monitoring/report', 'crm', 'Contact refused for jane@example.com');

        $this->assertSame([
            'gds-monitoring: report plm blog 1: Failed to sync recipe: cURL error 28: timed out',
            'gds-monitoring: report crm blog 1: Contact refused for <email>',
        ], $this->lines);
    }
}
