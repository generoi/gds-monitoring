<?php

namespace GeneroWP\Monitoring\Checks;

/**
 * Submissions ALTCHA (generoi/gravityforms-altcha) refused for a reason that
 * takes a real, signed challenge: it expired before the visitor submitted, or
 * its signature no longer matches (the HMAC key changed). Those point to real
 * people being turned away. A missing or malformed proof is what bots send,
 * so it isn't logged. Nothing is saved for a refused submission, so this is
 * the only trace of it.
 */
final class AltchaRejections implements Check
{
    private const REASONS = ['expired', 'invalid_signature'];

    public function register(): void
    {
        add_action('genero/gravityforms_altcha/log', [$this, 'record'], 10, 3);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function record(string $event, string $outcome, array $context = []): void
    {
        $reason = (string) ($context['reason'] ?? '');
        if ($event !== 'altcha' || $outcome !== 'fail' || ! in_array($reason, self::REASONS, true)) {
            return;
        }

        $line = sprintf('rejected form %d (%s)', (int) ($context['form'] ?? 0), $reason);
        if (isset($context['expired_for'])) {
            $line .= sprintf(': %d s past expiry', (int) $context['expired_for']);
        }

        Reports::write('altcha', $line, false);
    }
}
