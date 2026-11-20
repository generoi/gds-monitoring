<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;
use GeneroWP\Monitoring\ScriptMarker;

/**
 * A visitor refused by Gravity Forms, which leaves no entry to see:
 *
 * - a submission with fields failing the state check, as from a page cached
 *   before the form was edited or past the state token's life, unless no
 *   script ran on the page (a bot posting its HTML, see ScriptMarker);
 * - a multi-file upload refused for an expired nonce ("Your session has
 *   expired"), the file sent before the form is;
 * - a multi-file upload the server failed (too large for post_max_size, no
 *   temp folder, a file that couldn't be written).
 */
final class FormRefusals implements Check
{
    /**
     * Upload errors that are the visitor's or a bot's: a file type or size
     * the field refuses, a malformed request, a form that needs a login.
     */
    private const NOT_LOGGED = [104, 400, 401];

    private string $reply = '';

    public function register(): void
    {
        add_filter('gform_validation', [$this, 'validated'], 999);
        // Before Gravity Forms answers the upload on wp (10).
        add_action('wp', [$this, 'startUpload'], 0);
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    public function validated(array $result): array
    {
        $form = $result['form'] ?? [];
        $failed = [];
        foreach ($form['fields'] ?? [] as $field) {
            if (is_object($field) && $this->failedState($field)) {
                $failed[] = (int) $field->id;
            }
        }

        if ($failed && $this->scriptRan((int) $form['id'])) {
            Reports::write('gform', sprintf(
                'state invalid form %d fields %s',
                (int) $form['id'],
                implode(',', $failed)
            ), false);
        }

        return $result;
    }

    /**
     * Gravity Forms' upload reply carries its error code and message; the
     * HTTP status doesn't (WordPress drops statuses it has no name for).
     */
    public function startUpload(): void
    {
        $page = (string) ($_GET['gf_page'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
        // gf_page first: looking the slug up creates it on a site without one.
        if ($page !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && class_exists('GFCommon')
            && $page === \GFCommon::get_upload_page_slug()) {
            ob_start([$this, 'buffered']);
        }
    }

    /**
     * The upload reply as PHP flushes it, whatever buffers came after ours;
     * passed on unchanged.
     */
    public function buffered(string $output, int $phase): string
    {
        // Output thrown away (ob_clean) was never the reply.
        $this->reply = ($phase & PHP_OUTPUT_HANDLER_CLEAN ? '' : $this->reply.$output);
        if ($phase & PHP_OUTPUT_HANDLER_FINAL) {
            $this->uploadAnswered($this->reply, $_REQUEST); // phpcs:ignore WordPress.Security.NonceVerification
        }

        return $output;
    }

    /**
     * @param  array<string,mixed>  $request
     */
    public function uploadAnswered(string $body, array $request): void
    {
        $reply = json_decode($body, true);
        if (! is_array($reply) || ($reply['status'] ?? '') !== 'error') {
            return;
        }

        $code = $reply['error']['code'] ?? '';
        $formId = absint($request['form_id'] ?? 0);
        $fieldId = absint($request['field_id'] ?? 0);

        if ($code === 403) {
            // Only a bad nonce gets 403; without one it's not a visitor's page.
            if (! empty($request["_gform_file_upload_nonce_{$formId}_{$fieldId}"]) || ! empty($request["_gform_file_upload_nonce_{$formId}"])) {
                Reports::write('gform', sprintf('upload nonce expired form %d field %d', $formId, $fieldId), false);
            }

            return;
        }
        if (in_array($code, self::NOT_LOGGED, true)) {
            return;
        }

        // Some messages end with the visitor's file name.
        $names = array_filter([(string) ($request['original_filename'] ?? ''), (string) ($request['name'] ?? '')]);
        $message = trim(str_replace($names, '', (string) ($reply['error']['message'] ?? '')));

        Reports::write('gform', sprintf('upload failed form %d field %d (%s): %s', $formId, $fieldId, (string) $code, Log::clean($message)), false);
    }

    /**
     * Neither Gravity Forms' version_hash nor our marker: no script ran, and a
     * value the state check refuses was changed by a bot posting the page's
     * HTML. A person whose Gravity Forms script failed still sends the marker,
     * so a stale page or a broken field is logged.
     *
     * version_hash counts only when the page didn't print our script (from
     * before it, or a token refused whole, as from a page open since an update
     * changed how tokens are signed): bots copy its value from the page's
     * HTML. Called once the state check has read the token, so it counts no
     * failure of its own.
     */
    private function scriptRan(int $formId): bool
    {
        if (ScriptMarker::ran()) {
            return true;
        }

        return ! empty($_POST['version_hash']) && ! ScriptMarker::printed($formId); // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * Gravity Forms 2.x and 3.x both mark the field.
     */
    private function failedState(object $field): bool
    {
        return ! empty($field->failed_validation) && method_exists($field, 'get_context_property')
            && (bool) $field->get_context_property('failed_state_validation');
    }
}
