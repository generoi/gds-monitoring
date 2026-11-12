<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Tests\Support\TestCase;

class EntryErrorNotesTest extends TestCase
{
    public function test_a_failed_feed_note_is_logged_with_addresses_masked(): void
    {
        $formId = $this->form();
        $entryId = $this->entry($formId, time());
        \GFFormsModel::add_note($entryId, 0, 'Mailchimp', "Mailchimp feed: jane@example.com is\nalready a list member.", 'gravityformsmailchimp', 'error');

        $this->assertSame(["gds-monitoring: gform gravityformsmailchimp error blog 1 form {$formId} entry {$entryId}: Mailchimp feed: <email> is already a list member."], $this->lines);
    }

    public function test_other_notes_are_not_logged(): void
    {
        $entryId = $this->entry($this->form(), time());
        \GFFormsModel::add_note($entryId, 0, 'Admin (ID: abc)', 'WordPress successfully passed the notification email to the sending server.', 'notification', 'success');
        \GFFormsModel::add_note($entryId, 1, 'admin', 'Called back.', 'user');

        $this->assertSame([], $this->lines);
    }

    /**
     * Gravity Forms' own note when the mail server refuses a notification.
     */
    public function test_a_notification_email_that_could_not_be_sent_is_logged(): void
    {
        $formId = $this->form(['notifications' => ['n1' => [
            'id' => 'n1', 'name' => 'Admin', 'isActive' => true, 'event' => 'form_submission',
            'to' => 'owner@example.com', 'toType' => 'email', 'subject' => 'New entry', 'message' => 'Hello',
        ]]]);
        $entryId = $this->entry($formId, time());
        add_filter('pre_wp_mail', '__return_false');
        \GFAPI::send_notifications(\GFAPI::get_form($formId), \GFAPI::get_entry($entryId));
        remove_filter('pre_wp_mail', '__return_false');

        $this->assertSame(["gds-monitoring: gform notification error blog 1 form {$formId} entry {$entryId}: WordPress was unable to send the notification email."], $this->lines);
    }
}
