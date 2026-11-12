import { execFileSync } from 'node:child_process';

/**
 * Runs WP-CLI in wp-env's site and returns the last line of its output, which
 * is where our scripts print their JSON (wp-env prints its own lines first).
 */
export function wp(...args: string[]): string {
    const out = execFileSync('npx', ['wp-env', 'run', 'cli', 'wp', ...args], { encoding: 'utf8' });
    const lines = out.trim().split('\n').filter((line) => line.trim() !== '');
    return lines[lines.length - 1] ?? '';
}

export type Setup = { form: number; feed: number; page: string; gf: string };

/** A fresh form, its failing feed and the page showing it. */
export function setup(): Setup {
    return JSON.parse(wp('eval-file', 'wp-content/plugins/gds-monitoring/tests/e2e/setup.php'));
}

/** The lines gds-monitoring wrote since setup(). */
export function lines(): string[] {
    return JSON.parse(wp('eval', 'echo wp_json_encode(array_values(get_option("gds_monitoring_e2e_lines", [])));'));
}

/** The status of the form's latest entry. */
export function latestStatus(form: number): string {
    return wp('eval', `global $wpdb; echo $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}gf_entry WHERE form_id = %d ORDER BY id DESC LIMIT 1", ${form}));`);
}
