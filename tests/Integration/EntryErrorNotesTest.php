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

    public function test_mailchimp_refusing_the_visitors_address_is_not_logged(): void
    {
        $entryId = $this->entry($this->form(), time());
        $this->mailchimpResponse(400, 'Invalid Resource', 'jane@example.con looks fake or invalid, please enter a real email address.');
        \GFFormsModel::add_note($entryId, 0, 'Mailchimp', 'Mailchimp Feed 1: Unable to add/update subscriber: Invalid Resource', 'gravityformsmailchimp', 'error');

        $this->assertSame([], $this->lines);
    }

    public function test_mailchimp_refusing_the_feeds_merge_fields_is_logged(): void
    {
        $formId = $this->form();
        $entryId = $this->entry($formId, time());
        $this->mailchimpResponse(400, 'Invalid Resource', 'Your merge fields were invalid.');
        \GFFormsModel::add_note($entryId, 0, 'Mailchimp', 'Mailchimp Feed 1: Unable to add/update subscriber: Invalid Resource', 'gravityformsmailchimp', 'error');

        $this->assertSame(["gds-monitoring: gform gravityformsmailchimp error blog 1 form {$formId} entry {$entryId}: Mailchimp Feed 1: Unable to add/update subscriber: Invalid Resource"], $this->lines);
    }

    /**
     * A later request that went through clears the refusal, so it can't hide
     * an error note it isn't the reason for.
     */
    public function test_a_refusal_followed_by_a_successful_request_does_not_hide_a_later_error(): void
    {
        $formId = $this->form();
        $entryId = $this->entry($formId, time());
        $this->mailchimpResponse(400, 'Member Exists', 'jane@example.com is already a list member.');
        $this->mailchimpResponse(200, '', '');
        \GFFormsModel::add_note($entryId, 0, 'Mailchimp', 'Mailchimp Feed 1: Unable to add/update subscriber tags: Resource Not Found', 'gravityformsmailchimp', 'error');

        $this->assertCount(1, $this->lines);
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

    /**
     * What WordPress reports after the add-on's request to Mailchimp's API.
     */
    private function mailchimpResponse(int $code, string $title, string $detail): void
    {
        $body = $code >= 400 ? (string) wp_json_encode(['status' => $code, 'title' => $title, 'detail' => $detail]) : '{}';
        do_action('http_api_debug', ['response' => ['code' => $code, 'message' => ''], 'body' => $body, 'headers' => []], 'response', 'WpOrg\Requests\Requests', [], 'https://us1.api.mailchimp.com/3.0/lists/abc/members/def');
    }
}
