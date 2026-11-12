<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;

/**
 * Every error note Gravity Forms or an add-on writes on an entry: a feed of
 * any add-on that failed (Mailchimp, a CRM, a webhook), a notification email
 * that couldn't be sent, a failed save.
 */
final class EntryErrorNotes implements Check
{
    public function register(): void
    {
        add_action('gform_post_note_added', [$this, 'noteAdded'], 10, 7);
    }

    public function noteAdded(int $noteId, int $entryId, int $userId, string $userName, string $note, string $noteType, ?string $subType = null): void
    {
        if ($subType !== 'error') {
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
