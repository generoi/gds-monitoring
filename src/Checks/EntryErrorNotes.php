<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\GformMailchimp;
use GeneroWP\Monitoring\Log;

/**
 * Every error note Gravity Forms or an add-on writes on an entry: a feed of
 * any add-on that failed (Mailchimp, a CRM, a webhook), a notification email
 * that couldn't be sent, a failed save. Except Mailchimp refusing the
 * visitor's own address, which nobody can act on.
 */
final class EntryErrorNotes implements Check
{
    public function __construct(private GformMailchimp $mailchimp = new GformMailchimp) {}

    public function register(): void
    {
        $this->mailchimp->register();
        add_action('gform_post_note_added', [$this, 'noteAdded'], 10, 7);
    }

    public function noteAdded(int $noteId, int $entryId, int $userId, string $userName, string $note, string $noteType, ?string $subType = null): void
    {
        if ($subType !== 'error') {
            return;
        }

        if ($noteType === 'gravityformsmailchimp' && $this->mailchimp->refusedVisitorsAddress()) {
            return;
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
}
