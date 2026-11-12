<?php

namespace GeneroWP\Monitoring\Checks;

use GeneroWP\Monitoring\Log;

/**
 * A visitor refused because the page they had was too old, which leaves no
 * entry to see:
 *
 * - a multi-file upload refused for an expired nonce ("Your session has
 *   expired"), the file sent before the form is;
 * - a submission with fields failing Gravity Forms' state check, as from a
 *   page cached before the form was edited or past the state token's life.
 */
final class StaleForms implements Check
{
    public function register(): void
    {
        add_filter('gform_validation', [$this, 'validated'], 999);
        add_action('shutdown', [$this, 'shutdown']);
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

    public function shutdown(): void
    {
        $this->uploadFinished(http_response_code(), $_SERVER['REQUEST_METHOD'] ?? '', $_GET, $_REQUEST); // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * Gravity Forms' upload endpoint answers 403 only for a bad nonce. A
     * request without one is not a visitor's page, so it's left out.
     *
     * @param  array<string,mixed>  $get
     * @param  array<string,mixed>  $request
     */
    public function uploadFinished(int|bool $status, string $method, array $get, array $request): void
    {
        if ($status !== 403 || $method !== 'POST' || ! class_exists('GFCommon')
            || ($get['gf_page'] ?? '') !== \GFCommon::get_upload_page_slug()) {
            return;
        }

        $formId = absint($request['form_id'] ?? 0);
        $fieldId = absint($request['field_id'] ?? 0);
        if (empty($request["_gform_file_upload_nonce_{$formId}_{$fieldId}"]) && empty($request["_gform_file_upload_nonce_{$formId}"])) {
            return;
        }

        Log::write(sprintf('gform upload nonce expired blog %d form %d field %d', get_current_blog_id(), $formId, $fieldId));
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
