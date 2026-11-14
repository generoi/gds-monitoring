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
        if ($context !== 'response' || ! is_array($response) || ! preg_match('#^https://[a-z0-9]+\.api\.mailchimp\.com/#', $url)) {
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $this->detail = $code >= 400 && $code < 500 && is_array($body) && is_string($body['detail'] ?? null)
            ? $body['detail']
            : null;
    }

    /**
     * Whether the last request was refused for the address the visitor typed
     * (fake-looking, already a member, deleted, in a compliance state), which
     * Mailchimp names in its detail, unlike a wrong request ("Your merge
     * fields were invalid."). Asked once per failure, so it's forgotten.
     */
    public function refusedVisitorsAddress(): bool
    {
        $detail = $this->detail;
        $this->detail = null;

        return $detail !== null && (bool) preg_match('/\S+@\S+|valid email address/i', $detail);
    }
}
