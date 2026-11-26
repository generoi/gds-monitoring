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

        $this->assertSame(["gds-monitoring: report gform blog 1: gravityformsmailchimp error form {$formId} entry {$entryId}: Mailchimp feed: <email> is already a list member."], $this->lines);
    }

    /**
     * The add-on's order for a new subscriber: the member lookup's 404, then
     * the refused PUT, then its note.
     */
    public function test_mailchimp_refusing_the_visitors_address_is_not_logged(): void
    {
        $entryId = $this->entry($this->form(), time());
        $this->mailchimpResponse(404, 'Resource Not Found', 'The requested resource could not be found.');
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

        $this->assertSame(["gds-monitoring: report gform blog 1: gravityformsmailchimp error form {$formId} entry {$entryId}: Mailchimp Feed 1: Unable to add/update subscriber: Invalid Resource"], $this->lines);
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

        $this->assertSame(["gds-monitoring: report gform blog 1: gravityformsmailchimp error form {$formId} entry {$entryId}: Mailchimp Feed 1: Unable to add/update subscriber tags: Resource Not Found"], $this->lines);
    }

    /**
     * A connection that failed clears an earlier refusal too, e.g. one from
     * another Mailchimp plugin in the same request.
     */
    public function test_a_refusal_followed_by_a_failed_connection_does_not_hide_its_error(): void
    {
        $formId = $this->form();
        $entryId = $this->entry($formId, time());
        $this->mailchimpResponse(400, 'Member Exists', 'jane@example.com is already a list member.');
        do_action('http_api_debug', new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out'), 'response', 'WpOrg\Requests\Requests', [], 'https://us1.api.mailchimp.com/3.0/lists/abc/members/def');
        \GFFormsModel::add_note($entryId, 0, 'Mailchimp', 'Mailchimp Feed 1: Unable to check if email address is already used by a member: cURL error 28: Operation timed out', 'gravityformsmailchimp', 'error');

        $this->assertCount(1, $this->lines);
    }

    /**
     * Only Mailchimp's own refusals of the address count, not any detail that
     * happens to contain an address.
     */
    public function test_an_account_error_mentioning_an_address_is_logged(): void
    {
        $formId = $this->form();
        $entryId = $this->entry($formId, time());
        $this->mailchimpResponse(403, 'Forbidden', 'This account has been disabled. Contact apihelp@mailchimp.com.');
        \GFFormsModel::add_note($entryId, 0, 'Mailchimp', 'Mailchimp Feed 1: Unable to add/update subscriber: Forbidden', 'gravityformsmailchimp', 'error');

        $this->assertSame(["gds-monitoring: report gform blog 1: gravityformsmailchimp error form {$formId} entry {$entryId}: Mailchimp Feed 1: Unable to add/update subscriber: Forbidden"], $this->lines);
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

        $this->assertSame(["gds-monitoring: report gform blog 1: notification error form {$formId} entry {$entryId}: WordPress was unable to send the notification email."], $this->lines);
    }

    /**
     * A notification to the visitor's own address, from an optional email
     * field they left empty: Gravity Forms refuses to send it, and there is
     * nobody to tell.
     */
    public function test_a_notification_to_an_empty_visitor_field_is_not_logged(): void
    {
        $formId = $this->form(['notifications' => ['n1' => [
            'id' => 'n1', 'name' => 'Confirmation', 'isActive' => true, 'event' => 'form_submission',
            'to' => '2', 'toType' => 'field', 'subject' => 'Thanks', 'message' => 'Hello',
        ]]]);
        $entryId = $this->entry($formId, time());
        \GFAPI::send_notifications(\GFAPI::get_form($formId), \GFAPI::get_entry($entryId));

        $this->assertSame([], $this->lines);
    }

    /**
     * The same notification when the visitor filled the field in still logs
     * a mail server refusal.
     */
    public function test_a_notification_to_a_filled_visitor_field_that_fails_is_logged(): void
    {
        $formId = $this->form(['notifications' => ['n1' => [
            'id' => 'n1', 'name' => 'Confirmation', 'isActive' => true, 'event' => 'form_submission',
            'to' => '2', 'toType' => 'field', 'subject' => 'Thanks', 'message' => 'Hello',
        ]]]);
        $entryId = $this->entry($formId, time());
        \GFAPI::update_entry_field($entryId, '2','jane@example.com');
        add_filter('pre_wp_mail', '__return_false');
        \GFAPI::send_notifications(\GFAPI::get_form($formId), \GFAPI::get_entry($entryId));
        remove_filter('pre_wp_mail', '__return_false');

        $this->assertSame(["gds-monitoring: report gform blog 1: notification error form {$formId} entry {$entryId}: WordPress was unable to send the notification email."], $this->lines);
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
