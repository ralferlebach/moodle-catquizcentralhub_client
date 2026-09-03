<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Server-side policy for node synchronisation (issue #65).
 *
 * @package    catquizcentralhub_client
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace catquizcentralhub_client\local;

use moodle_exception;

/**
 * Decides whether this instance may send anything to a central hub.
 *
 * Issue #65: the setting enable_sync_as_node governed two things - which further
 * settings were visible, and which buttons the template rendered. The scheduled task,
 * the external functions and the HTTP layer never read it. Switching synchronisation
 * off therefore hid the controls while leaving every execution path intact: with
 * credentials still stored, the send path ran through to curl->post().
 *
 * A switch that turns off data transmission has to fail closed. This class is the
 * single place that answers whether it is on, and which scales are in scope. No task
 * and no endpoint may decide that for itself - divergent copies of such a rule are
 * how the gap arose in the first place.
 *
 * @package    catquizcentralhub_client
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_policy {
    /**
     * Whether this instance may synchronise as a node.
     *
     * Reads the configuration directly on every call. Caching it would mean a switch
     * flipped to off keeps sending until something invalidates the cache, and the
     * issue asks for no manual purge to be necessary.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return !empty(get_config('catquizcentralhub_client', 'enable_sync_as_node'));
    }

    /**
     * Throws unless node synchronisation is enabled.
     *
     * @throws moodle_exception
     * @return void
     */
    public static function require_enabled(): void {
        if (!self::is_enabled()) {
            throw new moodle_exception('syncdisabled', 'catquizcentralhub_client');
        }
    }

    /**
     * Returns the scale labels this instance may synchronise.
     *
     * The setting describes itself as "only these scales are transmitted", so it is
     * an allowlist and is treated as one here rather than as a hint.
     *
     * @return string[]
     */
    public static function get_allowed_scale_labels(): array {
        $labels = (string) get_config('catquizcentralhub_client', 'node_scale_labels');

        return array_values(array_filter(array_map('trim', explode("\n", $labels))));
    }

    /**
     * Whether a scale label is covered by the allowlist.
     *
     * @param string $label
     * @return bool
     */
    public static function is_scale_allowed(string $label): bool {
        return in_array(trim($label), self::get_allowed_scale_labels(), true);
    }

    /**
     * Throws unless the scale is covered by the allowlist.
     *
     * @param string $label
     * @throws moodle_exception
     * @return void
     */
    public static function require_scale_allowed(string $label): void {
        if (!self::is_scale_allowed($label)) {
            throw new moodle_exception('scalenotallowed', 'catquizcentralhub_client', '', $label);
        }
    }

    /**
     * Whether host and token are configured.
     *
     * Kept apart from is_enabled() on purpose: missing credentials while
     * synchronisation is switched off is not an error but a no-op, and reporting it
     * as a task failure is exactly what produced the growing faildelay.
     *
     * @return bool
     */
    public static function has_credentials(): bool {
        $config = get_config('catquizcentralhub_client');

        return !empty($config->central_host) && !empty($config->central_token);
    }
}
