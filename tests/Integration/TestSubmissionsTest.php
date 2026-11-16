<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Tests\Support\TestCase;
use GeneroWP\Monitoring\TestSubmissions;

class TestSubmissionsTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        reset_phpmailer_instance();
        add_filter('gds/monitoring/test_email', fn () => 'Forms-Test@example.org');
    }

    /**
     * A form with a notification to the site's staff and one to the visitor,
     * and an active feed.
     */
    private function form_with_notifications(): int
    {
        $id = $this->form(['notifications' => [
            'staff' => ['id' => 'staff', 'name' => 'Staff', 'isActive' => true, 'event' => 'form_submission', 'toType' => 'email', 'to' => 'sales@client.example', 'bcc' => 'boss@client.example', 'subject' => 'New lead', 'message' => '{all_fields}'],
            'visitor' => ['id' => 'visitor', 'name' => 'Visitor', 'isActive' => true, 'event' => 'form_submission', 'toType' => 'field', 'toField' => '2', 'subject' => 'Thanks', 'message' => 'Thanks'],
        ]]);
        $this->assertIsInt(\GFAPI::add_feed($id, ['feedName' => 'CRM'], 'gdstestfeed'));

        return $id;
    }

    /**
     * @return array<string,mixed>
     */
    private function submit(int $formId, string $email): array
    {
        $result = \GFAPI::submit_form($formId, ['input_1' => 'Jane', 'input_2' => $email]);
        $this->assertIsArray($result);
        $failed = array_filter($result['form']['fields'] ?? [], fn ($f) => $f->failed_validation);
        $this->assertTrue($result['is_valid'], 'invalid: '.implode('; ', array_map(fn ($f) => $f->id.' '.$f->validation_message, $failed)));
        $entry = \GFAPI::get_entry($result['entry_id']);
        $this->assertIsArray($entry);

        return $entry;
    }

    /**
     * @return list<array{to:string,subject:string,bcc:list<string>}>
     */
    private function sent(): array
    {
        $out = [];
        $mailer = tests_retrieve_phpmailer_instance();
        for ($i = 0; $sent = $mailer->get_sent($i); $i++) {
            $out[] = ['to' => $sent->to[0][0], 'subject' => $sent->subject, 'bcc' => array_column($sent->bcc, 0)];
        }

        return $out;
    }

    public function test_a_test_submission_mails_only_the_test_address_runs_no_feed_and_is_trashed(): void
    {
        $formId = $this->form_with_notifications();

        $entry = $this->submit($formId, 'forms-test+site@example.org');

        $this->assertSame([
            ['to' => 'forms-test+site@example.org', 'subject' => '[test] New lead', 'bcc' => []],
            ['to' => 'forms-test+site@example.org', 'subject' => '[test] Thanks', 'bcc' => []],
        ], $this->sent());
        $this->assertEmpty(gform_get_meta($entry['id'], 'processed_feeds'));
        $this->assertSame('trash', \GFAPI::get_entry($entry['id'])['status']);
        $notes = array_column((array) \GFAPI::get_notes(['entry_id' => $entry['id']]), 'value');
        $this->assertContains(TestSubmissions::NOTE, $notes);
    }

    public function test_the_address_itself_counts_whatever_its_case(): void
    {
        $formId = $this->form_with_notifications();

        $entry = $this->submit($formId, 'FORMS-TEST@example.org');

        $this->assertSame('forms-test@example.org', $this->sent()[0]['to']);
        $this->assertSame('trash', \GFAPI::get_entry($entry['id'])['status']);
    }

    public function test_any_other_submission_is_left_alone(): void
    {
        $formId = $this->form_with_notifications();

        // Close to the test address, but not it.
        $entry = $this->submit($formId, 'forms-test@example.net');

        $this->assertSame([
            ['to' => 'sales@client.example', 'subject' => 'New lead', 'bcc' => ['boss@client.example']],
            ['to' => 'forms-test@example.net', 'subject' => 'Thanks', 'bcc' => []],
        ], $this->sent());
        $this->assertNotEmpty(gform_get_meta($entry['id'], 'processed_feeds'));
        $this->assertSame('active', \GFAPI::get_entry($entry['id'])['status']);
    }

    public function test_nothing_is_a_test_without_a_test_address(): void
    {
        remove_all_filters('gds/monitoring/test_email');
        $formId = $this->form_with_notifications();

        $entry = $this->submit($formId, 'forms-test@example.org');

        $this->assertSame('sales@client.example', $this->sent()[0]['to']);
        $this->assertSame('active', \GFAPI::get_entry($entry['id'])['status']);
    }
}
