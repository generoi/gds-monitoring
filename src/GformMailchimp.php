<?php

namespace GeneroWP\Monitoring;

/**
 * Why Mailchimp refused the Gravity Forms add-on's last request. The
 * add-on's error note only keeps Mailchimp's title ("Invalid Resource",
 * "Member Exists"), which a broken merge field gives too; the detail in
 * Mailchimp's response tells them apart, so it's read here as the response
 * comes back.
 */
final class GformMailchimp
{
    /**
     * Mailchimp's details when it refuses the address itself. Anything else
     * ("Your merge fields were invalid.", an account problem) is logged.
     */
    private const ADDRESS_REFUSALS = [
        'looks fake or invalid',
        'is already a list member',
        'permanently deleted',
        'compliance state',
        'valid email address',
    ];

    /** Mailchimp's detail on the request that just failed. */
    private ?string $detail = null;

    public function register(): void
    {
        add_action('http_api_debug', [$this, 'httpResponse'], 10, 5);
    }

    /**
     * @param  array<string, mixed>|\WP_Error  $response
     * @param  array<string, mixed>  $args
     */
    public function httpResponse($response, string $context, string $class, array $args, string $url): void
    {
        if ($context !== 'response' || ! preg_match('#^https://[a-z0-9]+\.api\.mailchimp\.com/#', $url)) {
            return;
        }

        // Any response replaces the last one, a failed connection included,
        // so an earlier refusal can't stand in for it.
        $this->detail = null;
        if (! is_array($response)) {
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $this->detail = $code >= 400 && $code < 500 && is_array($body) && is_string($body['detail'] ?? null)
            ? $body['detail']
            : null;
    }

    /**
     * Whether the last request was refused for the address the visitor typed.
     * Asked once per failure, so it's forgotten.
     */
    public function refusedVisitorsAddress(): bool
    {
        $detail = $this->detail;
        $this->detail = null;
        if ($detail === null) {
            return false;
        }

        foreach (self::ADDRESS_REFUSALS as $phrase) {
            if (stripos($detail, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }
}
