<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;

/**
 * Every error note Gravity Forms or an add-on writes on an entry: a feed of
 * any add-on that failed (Mailchimp, a CRM, a webhook), a notification email
 * that couldn't be sent, a failed save.
 *
 * Except Mailchimp refusing the visitor's own address (fake-looking, already
 * a member, deleted, in a compliance state): its note only says "Invalid
 * Resource" or "Member Exists", which a broken merge field also says, so the
 * reason is read from Mailchimp's response, whose detail names the address.
 */
final class EntryErrorNotes implements Check
{
    /** Mailchimp's detail on the request that just failed. */
    private ?string $mailchimpDetail = null;

    public function register(): void
    {
        add_action('http_api_debug', [$this, 'httpResponse'], 10, 5);
        add_action('gform_post_note_added', [$this, 'noteAdded'], 10, 7);
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
        $this->mailchimpDetail = $code >= 400 && $code < 500 && is_array($body) && is_string($body['detail'] ?? null)
            ? $body['detail']
            : null;
    }

    public function noteAdded(int $noteId, int $entryId, int $userId, string $userName, string $note, string $noteType, ?string $subType = null): void
    {
        if ($subType !== 'error') {
            return;
        }

        if ($noteType === 'gravityformsmailchimp') {
            $detail = $this->mailchimpDetail;
            $this->mailchimpDetail = null;
            if ($detail !== null && $this->visitorsAddress($detail)) {
                return;
            }
        }

        $entry = class_exists('GFAPI') ? \GFAPI::get_entry($entryId) : null;

        Log::write(sprintf(
            'gform %s error blog %d form %d entry %d: %s',
            $noteType,
            get_current_blog_id(),
            is_array($entry) ? (int) $entry['form_id'] : 0,
            $entryId,
            Log::clean($note)
        ));
    }

    /**
     * Mailchimp names the address when it refuses it ("<email> looks fake or
     * invalid…", "<email> is already a list member…"), and not when the
     * request is wrong ("Your merge fields were invalid.").
     */
    private function visitorsAddress(string $detail): bool
    {
        return (bool) preg_match('/\S+@\S+|valid email address/i', $detail);
    }
}
