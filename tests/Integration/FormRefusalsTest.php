<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Checks\FormRefusals;
use GeneroWP\Monitoring\Tests\Support\TestCase;

class FormRefusalsTest extends TestCase
{
    /**
     * A field failing the state check needs a real page's state input, which
     * GFAPI::submit_form() skips: that case, and a real expired upload, are
     * in tests/e2e.
     */
    public function test_other_validation_errors_are_not_logged(): void
    {
        $formId = $this->form(['fields' => [
            ['id' => 1, 'type' => 'select', 'label' => 'Size', 'isRequired' => true, 'choices' => [['text' => 'Small', 'value' => 'small']]],
            ['id' => 2, 'type' => 'email', 'label' => 'Email'],
        ]]);

        $result = \GFAPI::submit_form($formId, ['input_2' => 'not an email']);

        $this->assertFalse($result['is_valid']);
        $this->assertSame([], $this->lines);
    }

    /**
     * @param  int|string  $code
     */
    private function reply($code, string $message): string
    {
        return (string) wp_json_encode(['status' => 'error', 'error' => ['code' => $code, 'message' => $message]]);
    }

    public function test_a_refused_upload_with_a_nonce_is_logged(): void
    {
        (new FormRefusals)->uploadAnswered(
            $this->reply(403, 'Your session has expired. Please refresh the page and try again.'),
            ['form_id' => '4', 'field_id' => '7', '_gform_file_upload_nonce_4_7' => 'abc123']
        );

        $this->assertSame(['gds-monitoring: gform upload nonce expired blog 1 form 4 field 7'], $this->lines);
    }

    public function test_a_server_failure_is_logged_without_the_file_name(): void
    {
        $check = new FormRefusals;
        $request = ['form_id' => '4', 'field_id' => '7', 'original_filename' => 'jane-doe-cv.pdf', 'name' => 'o_1abc.pdf'];

        $check->uploadAnswered($this->reply(500, 'Failed to upload file.'), $request);
        $check->uploadAnswered($this->reply(105, 'Upload unsuccessful jane-doe-cv.pdf'), $request);

        $this->assertSame([
            'gds-monitoring: gform upload failed blog 1 form 4 field 7 (500): Failed to upload file.',
            'gds-monitoring: gform upload failed blog 1 form 4 field 7 (105): Upload unsuccessful',
        ], $this->lines);
    }

    public function test_the_visitors_and_bots_upload_errors_are_not_logged(): void
    {
        $check = new FormRefusals;
        $request = ['form_id' => '4', 'field_id' => '7'];

        $check->uploadAnswered((string) wp_json_encode(['status' => 'ok', 'data' => []]), $request);
        $check->uploadAnswered($this->reply(104, 'The uploaded file type is not allowed.'), $request);
        $check->uploadAnswered($this->reply(400, 'Invalid request.'), $request);
        $check->uploadAnswered($this->reply(401, 'You must be logged in to upload files to this form.'), $request);
        $check->uploadAnswered($this->reply(403, 'Your session has expired.'), $request); // no nonce: not a page
        $check->uploadAnswered('not json', $request);

        $this->assertSame([], $this->lines);
    }
}
