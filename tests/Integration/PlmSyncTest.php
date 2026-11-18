<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Checks\PlmSync;
use GeneroWP\Monitoring\Plugin;
use GeneroWP\Monitoring\Tests\Support\TestCase;

class PlmSyncTest extends TestCase
{
    public function tear_down(): void
    {
        delete_option(PlmSync::OPTION);
        parent::tear_down();
    }

    public function test_an_error_is_logged_once_per_half_day(): void
    {
        do_action('plm/log', 'Failed to sync recipe: cURL error 28: timed out', ['ID' => 'abc'], 'error');
        do_action('plm/log', 'Failed to sync recipe: cURL error 28: timed out', ['ID' => 'def'], 'error');

        $this->assertSame(['gds-monitoring: plm error blog 1: Failed to sync recipe: cURL error 28: timed out'], $this->lines);
    }

    public function test_debug_and_info_messages_are_ignored(): void
    {
        do_action('plm/log', 'Trashing: Old recipe', [], 'debug');
        do_action('plm/log', 'Synced', [], 'success');

        $this->assertSame([], $this->lines);
    }

    public function test_a_type_without_a_completed_sync_for_over_a_day_is_reported(): void
    {
        $now = time();
        update_option(PlmSync::OPTION, ['products' => $now - 2 * HOUR_IN_SECONDS, 'recipes' => $now - 30 * HOUR_IN_SECONDS]);

        Plugin::schedule()->run($now);

        $this->assertContains('gds-monitoring: plm stale blog 1 recipes: last complete sync 30 h ago', $this->lines);
        $this->assertNotContains('gds-monitoring: plm stale blog 1 products: last complete sync 2 h ago', $this->lines);
    }

    public function test_a_site_that_never_completed_a_sync_is_not_reported(): void
    {
        Plugin::schedule()->run(time());

        $this->assertSame([], array_values(array_filter($this->lines, fn (string $line): bool => str_contains($line, 'plm'))));
    }
}
