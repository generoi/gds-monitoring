<?php

namespace GeneroWP\Monitoring;

use GeneroWP\Monitoring\Checks\Scheduled;

/**
 * The hourly run of the scheduled checks, from the main site's cron only:
 * on a multisite every site is visited from there, so a sub-site nobody
 * visits is still checked.
 */
final class Schedule
{
    public const HOOK = 'gds_monitoring_hourly';

    /**
     * @param  list<Scheduled>  $checks
     */
    public function __construct(private array $checks) {}

    public function register(): void
    {
        add_action('init', [$this, 'ensure']);
        add_action(self::HOOK, fn () => $this->run(time()));
    }

    public function ensure(): void
    {
        if (is_main_site() && ! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'hourly', self::HOOK);
        }
    }

    public function run(int $now): void
    {
        foreach ($this->checks as $check) {
            if (! $check->perSite()) {
                $check->run($now);
            }
        }

        $sites = is_multisite()
            ? array_map('intval', get_sites(['fields' => 'ids', 'number' => 0, 'archived' => 0, 'deleted' => 0, 'spam' => 0]))
            : [get_current_blog_id()];

        foreach ($sites as $site) {
            if (is_multisite()) {
                switch_to_blog($site);
            }
            foreach ($this->checks as $check) {
                if ($check->perSite()) {
                    $check->run($now);
                }
            }
            if (is_multisite()) {
                restore_current_blog();
            }
        }
    }
}
