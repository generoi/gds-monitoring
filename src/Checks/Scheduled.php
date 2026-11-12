<?php

namespace GeneroWP\Monitoring\Checks;

/**
 * A check the hourly schedule runs: once for the network, or on every site
 * (perSite) with that site switched to.
 */
interface Scheduled
{
    public function perSite(): bool;

    public function run(int $now): void;
}
