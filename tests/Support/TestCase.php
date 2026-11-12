<?php

namespace GeneroWP\Monitoring\Tests\Support;

use GeneroWP\Monitoring\Throttle;
use WP_UnitTestCase;

/**
 * Captures the plugin's log lines instead of writing them, and builds forms
 * and entries through Gravity Forms' own API.
 */
abstract class TestCase extends WP_UnitTestCase
{
    /** @var list<string> */
    protected array $lines = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->lines = [];
        delete_option(Throttle::OPTION);
        // Gravity Forms active on the site, as the hourly checks require. The
        // test add-on lives outside the plugins folder, so it counts as
        // loaded everywhere.
        update_option('active_plugins', [plugin_basename(\GFCommon::get_base_path().'/gravityforms.php')]);
        add_filter('gds/monitoring/write', function (bool $write, string $line): bool {
            $this->lines[] = $line;

            return false;
        }, 10, 2);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    protected function form(array $extra = []): int
    {
        $id = \GFAPI::add_form($extra + [
            'title' => 'Form '.wp_generate_password(6, false),
            'fields' => [
                ['id' => 1, 'type' => 'text', 'label' => 'Name'],
                ['id' => 2, 'type' => 'email', 'label' => 'Email'],
            ],
        ]);
        $this->assertIsInt($id);

        return $id;
    }

    protected function entry(int $formId, int $created, string $status = 'active'): int
    {
        $id = \GFAPI::add_entry([
            'form_id' => $formId,
            'date_created' => gmdate('Y-m-d H:i:s', $created),
            'status' => $status,
            '1' => 'Jane',
        ]);
        $this->assertIsInt($id);

        return $id;
    }
}
