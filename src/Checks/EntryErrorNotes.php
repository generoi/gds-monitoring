<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\GformMailchimp;
use GeneroWP\Monitoring\Log;

/**
 * Every error note Gravity Forms or an add-on writes on an entry: a feed of
 * any add-on that failed (Mailchimp, a CRM, a webhook), a notification email
 * that couldn't be sent, a failed save. Except what nobody can act on:
 * Mailchimp refusing the visitor's own address, and a notification to a form
 * field the visitor left empty (an optional email field).
 */
final class EntryErrorNotes implements Check
{
    /** Whether the notification being sent goes to a form field left empty. */
    private bool $toEmptyField = false;

    public function __construct(private GformMailchimp $mailchimp = new GformMailchimp) {}

    public function register(): void
    {
        $this->mailchimp->register();
        add_filter('gform_notification', [$this, 'notification'], PHP_INT_MAX, 3);
        add_action('gform_post_note_added', [$this, 'noteAdded'], 10, 7);
    }

    /**
     * Gravity Forms runs this for each notification just before sending it,
     * so its error note, if any, is about this one. The field is read the
     * way Gravity Forms reads it: toField, else to.
     *
     * @param  mixed  $notification
     * @param  mixed  $form
     * @param  mixed  $entry
     * @return mixed
     */
    public function notification($notification, $form, $entry)
    {
        $this->toEmptyField = false;
        if (is_array($notification) && ($notification['toType'] ?? '') === 'field' && is_array($entry)) {
            $field = (string) (($notification['toField'] ?? '') ?: ($notification['to'] ?? ''));
            $this->toEmptyField = $field !== '' && trim((string) ($entry[$field] ?? '')) === '';
        }

        return $notification;
    }

    public function noteAdded(int $noteId, int $entryId, int $userId, string $userName, string $note, string $noteType, ?string $subType = null): void
    {
        if ($subType !== 'error') {
            return;
        }

        if ($noteType === 'gravityformsmailchimp' && $this->mailchimp->refusedVisitorsAddress()) {
            return;
        }

        if ($noteType === 'notification' && $this->toEmptyField) {
            return;
        }

        $entry = class_exists('GFAPI') ? \GFAPI::get_entry($entryId) : null;

        Reports::write('gform', sprintf(
            '%s error form %d entry %d: %s',
            $noteType,
            is_array($entry) ? (int) $entry['form_id'] : 0,
            $entryId,
            Log::clean($note)
        ), false);
    }
}
