<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Tests\Support\TestCase;

/**
 * Fired as generoi/gravityforms-altcha fires its log action, so the check is
 * tested without that plugin installed.
 */
class AltchaRejectionsTest extends TestCase
{
    public function test_an_expired_proof_is_logged_with_how_late_it_was(): void
    {
        do_action('genero/gravityforms_altcha/log', 'altcha', 'fail', ['form' => 43, 'reason' => 'expired', 'expired_for' => 125]);

        $this->assertSame(['gds-monitoring: gform altcha rejected blog 1 form 43 (expired): 125 s past expiry'], $this->lines);
    }

    public function test_a_signature_that_no_longer_matches_is_logged(): void
    {
        do_action('genero/gravityforms_altcha/log', 'altcha', 'fail', ['form' => 43, 'reason' => 'invalid_signature']);

        $this->assertSame(['gds-monitoring: gform altcha rejected blog 1 form 43 (invalid_signature)'], $this->lines);
    }

    /**
     * @return array<string,array{string,string,array<string,mixed>}>
     */
    public static function skipped(): array
    {
        return [
            'missing proof (bots)' => ['altcha', 'fail', ['form' => 43, 'reason' => 'missing']],
            'malformed proof (bots)' => ['altcha', 'fail', ['form' => 43, 'reason' => 'malformed']],
            'replayed proof' => ['altcha', 'fail', ['form' => 43, 'reason' => 'replay']],
            'a pass' => ['altcha', 'pass', ['form' => 43]],
            'another layer' => ['rate_limit', 'blocked', ['form' => 43]],
        ];
    }

    /**
     * @dataProvider skipped
     *
     * @param  array<string,mixed>  $context
     */
    public function test_other_decisions_are_not_logged(string $event, string $outcome, array $context): void
    {
        do_action('genero/gravityforms_altcha/log', $event, $outcome, $context);

        $this->assertSame([], $this->lines);
    }
}
