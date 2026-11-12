<?php

namespace GeneroWP\Monitoring\Tests\Support;

/**
 * The smallest feed add-on: Gravity Forms records its runs like any other's.
 */
class TestFeedAddOn extends \GFFeedAddOn
{
    protected $_version = '1.0';

    protected $_min_gravityforms_version = '2.5';

    protected $_slug = 'gdstestfeed';

    protected $_path = 'gds-monitoring/tests/Support/TestFeedAddOn.php';

    protected $_full_path = __FILE__;

    protected $_title = 'Test feed';

    protected $_short_title = 'Test feed';

    private static ?self $instance = null;

    public static function get_instance(): self
    {
        return self::$instance ??= new self;
    }

    /**
     * @param  array<string,mixed>  $feed
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $form
     * @return array<string,mixed>
     */
    public function process_feed($feed, $entry, $form)
    {
        return $entry;
    }
}
