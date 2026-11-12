<?php

namespace GeneroWP\Monitoring\Tests\Integration;

use GeneroWP\Monitoring\Tests\Support\TestCase;

class SpamEntriesTest extends TestCase
{
    /**
     * Submits through Gravity Forms with a spam filter that flags the
     * submission for reason, as the honeypot or reCAPTCHA does.
     */
    private function submitAsSpam(string $filter, string $reason): int
    {
        $formId = $this->form();
        $spam = function (bool $isSpam, array $form) use ($filter, $reason): bool {
            if ($filter !== '') {
                \GFCommon::set_spam_filter((int) $form['id'], $filter, $reason);
            }

            return true;
        };
        add_filter('gform_entry_is_spam', $spam, 1000, 2);
        $result = \GFAPI::submit_form($formId, ['input_1' => 'Jane']);
        remove_filter('gform_entry_is_spam', $spam, 1000);
        unset(\GFFormDisplay::$submission[$formId]);

        $this->assertIsArray($result);
        $this->assertTrue($result['is_valid'], print_r($result, true));
        $this->assertSame('spam', \GFAPI::get_entry((int) $result['entry_id'])['status']);

        return $formId;
    }

    public function test_a_real_person_caught_on_a_stale_page_is_logged(): void
    {
        $formId = $this->submitAsSpam('Honeypot Spam Filter', 'The form failed state validation before it was successfully submitted. This typically indicates bot activity or manual tampering.');

        $this->assertCount(1, $this->lines);
        $this->assertMatchesRegularExpression(
            "/^gds-monitoring: gform spam blog 1 form {$formId} entry \d+ \(Honeypot Spam Filter\): The form failed state validation before it was successfully submitted\. This typically indicates bot $/",
            $this->lines[0]
        );
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function bots(): array
    {
        return [
            'honeypot filled' => ['Honeypot Spam Filter', 'The honeypot input is not empty.'],
            'no version_hash' => ['Honeypot Spam Filter', 'The version_hash was not included in the submission.'],
            'speed check' => ['Honeypot Spam Filter', '1 of 3 submissions met the speed check threshold (500 ms). Min required: 2. All speeds (ms): 120, 90, 800.'],
            'reCAPTCHA low score' => ['reCAPTCHA', 'Score of 0.1 is less than or equal to the threshold of 0.5.'],
        ];
    }

    /**
     * @dataProvider bots
     */
    public function test_reasons_only_bots_give_are_skipped(string $filter, string $reason): void
    {
        $this->submitAsSpam($filter, $reason);

        $this->assertSame([], $this->lines);
    }

    public function test_spam_without_a_reason_says_so(): void
    {
        $this->submitAsSpam('', '');

        $this->assertStringEndsWith('(unknown): no reason recorded', $this->lines[0] ?? '');
    }

    public function test_cleantalk_gives_its_verdict_as_the_reason(): void
    {
        $GLOBALS['ct_gform_is_spam'] = true;
        $GLOBALS['ct_gform_response'] = '*** Forbidden. Sender blacklisted. <b>Anti-Spam by CleanTalk</b>. ***';
        $this->submitAsSpam('', '');
        unset($GLOBALS['ct_gform_is_spam'], $GLOBALS['ct_gform_response']);

        $this->assertStringEndsWith('(CleanTalk): *** Forbidden. Sender blacklisted. Anti-Spam by CleanTalk. ***', $this->lines[0] ?? '');
    }

    public function test_an_entry_that_is_not_spam_is_not_logged(): void
    {
        $result = \GFAPI::submit_form($this->form(), ['input_1' => 'Jane']);

        $this->assertIsArray($result);
        $this->assertSame([], $this->lines);
    }
}
