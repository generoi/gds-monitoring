<?php

namespace GeneroWP\Monitoring\Checks;

/**
 * A check that hooks into WordPress or a plugin and logs as things happen.
 */
interface Check
{
    public function register(): void;
}
