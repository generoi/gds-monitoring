<?php

namespace GeneroWP\Monitoring;

/**
 * Tells a person whose browser didn't run Gravity Forms' script from a bot
 * posting the page's HTML. Both send no version_hash, the input that script
 * adds as a form is sent. A small inline script of our own, apart from Gravity
 * Forms' files, adds gds_monitoring_js as any form is sent: a person whose
 * Gravity Forms script failed to load or threw still sends it; a bot posting
 * the HTML doesn't. Like Gravity Forms, it adds nothing in a browser driven by
 * automation (Selenium, PhantomJS and the like), so those stay bots.
 *
 * The form's state token (Gravity Forms 3) also records that the page printed
 * our script, so a submission can be held to the page it came from. Not so
 * version_hash: its value is in the page's HTML for any bot to copy.
 */
final class ScriptMarker
{
    public const INPUT = 'gds_monitoring_js';

    private bool $printed = false;

    public function register(): void
    {
        add_filter('gform_get_form_filter', [$this, 'form'], 10, 1);
        add_action('gform_state_pre_encode', [$this, 'state'], 10, 3);
    }

    /**
     * Whether the submission being handled came from a browser that ran our
     * script.
     */
    public static function ran(): bool
    {
        return ! empty($_POST[self::INPUT]); // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * Whether the submission's state token, signed by Gravity Forms, says its
     * page printed our script. False for a token Gravity Forms refuses whole,
     * and for a page from before this.
     */
    public static function printed(int $formId): bool
    {
        // Gravity Forms 2.x has no State_Handler and never calls state().
        if (! class_exists('GFFormDisplay') || ! class_exists('Gravity_Forms\Gravity_Forms\Form_Display\State\State_Handler')) {
            return false;
        }
        $state = \GFFormDisplay::get_state_handler();

        // A key the token doesn't hold passes any value, '' included.
        return $state->is_valid_state_input($formId) && ! $state->is_valid_additional_value($formId, self::INPUT, '');
    }

    /**
     * Every form's markup passes through form(), which prints the script once
     * per page, so every token gets the key.
     *
     * @param  array<int,int|string>  $keys
     */
    public function state(object $state, array $keys, int $formId): void
    {
        if (method_exists($state, 'add_hashes')) {
            $state->add_hashes($formId, self::INPUT, wp_hash('1'));
        }
    }

    public function form(string $html): string
    {
        if ($this->printed) {
            return $html;
        }
        $this->printed = true;

        return $html.wp_get_inline_script_tag($this->script());
    }

    /**
     * Added on submit, not on load, so it is there whenever the form was
     * rendered; the listener on the document catches every form on the page.
     * Also on a click in the form: a working Gravity Forms 3 script sends the
     * form from its button's click with form.submit(), which fires no submit
     * event.
     * Gravity Forms' own automation check, as its theme script words it.
     */
    private function script(): string
    {
        $input = self::INPUT;

        return <<<JS
            (function () {
                if (window.gdsMonitoringJs) return;
                window.gdsMonitoringJs = true;
                var d = window.document, e = d.documentElement;
                var automated = window._phantom || window.callPhantom || window.__phantomas || window.Buffer || window.emit || window.spawn
                    || window.webdriver || window._selenium || window._Selenium_IDE_Recorder || window.callSelenium || window.__nightmare
                    || window.domAutomation || window.domAutomationController || d.__webdriver_evaluate || d.__selenium_evaluate
                    || d.__webdriver_script_function || d.__webdriver_script_func || d.__webdriver_script_fn || d.__fxdriver_evaluate
                    || d.__driver_unwrapped || d.__webdriver_unwrapped || d.__driver_evaluate || d.__selenium_unwrapped || d.__fxdriver_unwrapped
                    || e.getAttribute('selenium') || e.getAttribute('webdriver') || e.getAttribute('driver');
                if (automated) return;
                var mark = function (event) {
                    var form = event.target && event.target.closest ? event.target.closest('form') : null;
                    if (!form || !/^gform_\d+$/.test(form.id) || form.querySelector('input[name="{$input}"]')) return;
                    var input = d.createElement('input');
                    input.type = 'hidden';
                    input.name = '{$input}';
                    input.value = '1';
                    form.appendChild(input);
                };
                d.addEventListener('submit', mark, true);
                d.addEventListener('click', mark, true);
            })();
            JS;
    }
}
