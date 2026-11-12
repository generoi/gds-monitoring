<?php

/**
 * End-to-end tests only, mapped into wp-env's site by .wp-env.json: keeps
 * gds-monitoring's lines in an option the Playwright tests read, and adds a
 * feed add-on whose every run fails, as a CRM refusing the entry would.
 */
add_filter('gds/monitoring/write', function (bool $write, string $line): bool {
    $lines = get_option('gds_monitoring_e2e_lines', []);
    $lines[] = $line;
    update_option('gds_monitoring_e2e_lines', $lines, false);

    return $write;
}, 10, 2);

// A page's upload nonce that expires within seconds, as a day-old cached page's.
add_filter('nonce_life', function ($life, $action = '') {
    $short = (int) get_option('gds_monitoring_e2e_upload_nonce_life');

    return $short && str_starts_with((string) $action, 'gform_file_upload_') ? $short : $life;
}, 10, 2);

add_action('gform_loaded', function (): void {
    GFForms::include_feed_addon_framework();

    class GDS_Monitoring_E2E_Failing_Feed extends GFFeedAddOn
    {
        protected $_version = '1.0';

        protected $_min_gravityforms_version = '2.5';

        protected $_slug = 'gdse2efeed';

        protected $_path = 'gds-monitoring-e2e.php';

        protected $_full_path = __FILE__;

        protected $_title = 'E2E failing feed';

        protected $_short_title = 'E2E feed';

        private static ?self $instance = null;

        public static function get_instance(): self
        {
            return self::$instance ??= new self;
        }

        public function process_feed($feed, $entry, $form)
        {
            $this->add_feed_error('the CRM refused it', $feed, $entry, $form);

            return $entry;
        }
    }

    GFAddOn::register(GDS_Monitoring_E2E_Failing_Feed::class);
}, 5);
