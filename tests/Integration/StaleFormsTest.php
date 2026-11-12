<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Checks\StaleForms;
use GeneroWP\Monitoring\Tests\Support\TestCase;

class StaleFormsTest extends TestCase
{
    /**
     * A field failing the state check needs a real page's state input, which
     * GFAPI::submit_form() skips: that case is in tests/e2e.
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

    public function test_a_refused_upload_with_a_nonce_is_logged(): void
    {
        $slug = \GFCommon::get_upload_page_slug();
        (new StaleForms)->uploadFinished(403, 'POST', ['gf_page' => $slug], ['form_id' => '4', 'field_id' => '7', '_gform_file_upload_nonce_4_7' => 'abc123']);

        $this->assertSame(['gds-monitoring: gform upload nonce expired blog 1 form 4 field 7'], $this->lines);
    }

    public function test_other_upload_requests_are_not_logged(): void
    {
        $slug = \GFCommon::get_upload_page_slug();
        $check = new StaleForms;
        $nonce = ['form_id' => '4', 'field_id' => '7', '_gform_file_upload_nonce_4_7' => 'abc123'];

        $check->uploadFinished(200, 'POST', ['gf_page' => $slug], $nonce); // uploaded
        $check->uploadFinished(403, 'POST', ['gf_page' => $slug], ['form_id' => '4', 'field_id' => '7']); // no nonce: not a page
        $check->uploadFinished(403, 'POST', ['gf_page' => 'other'], $nonce); // another page
        $check->uploadFinished(403, 'GET', ['gf_page' => $slug], $nonce);

        $this->assertSame([], $this->lines);
    }
}
