<?php

namespace GeneroWP\Monitoring;

/**
 * Whether a plugin is active on the current site. The hourly run happens on
 * the main site, with its plugins loaded, and switches to each site of a
 * network: a site where Gravity Forms is off has no tables to query, and a
 * feed whose add-on is off there never runs.
 */
final class Site
{
    /**
     * @param  string  $basename  The plugin's file relative to the plugins
     *                            folder ("gravityforms/gravityforms.php").
     */
    public static function active(string $basename): bool
    {
        // Not a plugin WordPress activates (a mu-plugin, bundled code): it
        // loads on every site.
        if (! is_file(WP_PLUGIN_DIR.'/'.$basename)) {
            return true;
        }

        return in_array($basename, (array) get_option('active_plugins', []), true)
            || (is_multisite() && isset(((array) get_site_option('active_sitewide_plugins', []))[$basename]));
    }

    public static function gravityForms(): bool
    {
        return class_exists('GFCommon') && self::active(plugin_basename(\GFCommon::get_base_path().'/gravityforms.php'));
    }
}
