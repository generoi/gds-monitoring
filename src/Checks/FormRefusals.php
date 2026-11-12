<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;

/**
 * A visitor refused by Gravity Forms, which leaves no entry to see:
 *
 * - a submission with fields failing the state check, as from a page cached
 *   before the form was edited or past the state token's life;
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

    private int $buffer = 0;

    public function register(): void
    {
        add_filter('gform_validation', [$this, 'validated'], 999);
        // Before Gravity Forms answers the upload on wp (10), read before
        // WordPress flushes the buffers on shutdown (1).
        add_action('wp', [$this, 'startUpload'], 0);
        add_action('shutdown', [$this, 'shutdown'], 0);
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

        if ($failed) {
            Log::write(sprintf(
                'gform state invalid blog %d form %d fields %s',
                get_current_blog_id(),
                (int) $form['id'],
                implode(',', $failed)
            ));
        }

        return $result;
    }

    /**
     * Gravity Forms' upload reply carries its error code and message; the
     * HTTP status doesn't (WordPress drops statuses it has no name for).
     */
    public function startUpload(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && class_exists('GFCommon')
            && ($_GET['gf_page'] ?? '') === \GFCommon::get_upload_page_slug()) { // phpcs:ignore WordPress.Security.NonceVerification
            ob_start();
            $this->buffer = ob_get_level();
        }
    }

    public function shutdown(): void
    {
        if ($this->buffer && ob_get_level() === $this->buffer) {
            $this->uploadAnswered((string) ob_get_contents(), $_REQUEST); // phpcs:ignore WordPress.Security.NonceVerification
        }
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
                Log::write(sprintf('gform upload nonce expired blog %d form %d field %d', get_current_blog_id(), $formId, $fieldId));
            }

            return;
        }
        if (in_array($code, self::NOT_LOGGED, true)) {
            return;
        }

        // Some messages end with the visitor's file name.
        $names = array_filter([(string) ($request['original_filename'] ?? ''), (string) ($request['name'] ?? '')]);
        $message = trim(str_replace($names, '', (string) ($reply['error']['message'] ?? '')));

        Log::write(sprintf('gform upload failed blog %d form %d field %d (%s): %s', get_current_blog_id(), $formId, $fieldId, (string) $code, Log::clean($message)));
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
