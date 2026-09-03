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
 * Issue #65: the sync switch has to be a server-side kill-switch.
 *
 * @package    catquizcentralhub_client
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace catquizcentralhub_client\local;

use advanced_testcase;

/**
 * Verifies that nothing leaves the instance while synchronisation is switched off.
 *
 * The reported case is the dangerous one: synchronisation is enabled, host, token and
 * scale labels are configured, and synchronisation is then switched off again. The
 * credentials remain - keeping them is not consent to use them. Before this fix the
 * scheduled task never read the switch and ran through to curl->post().
 *
 * The tests below make no network calls. They assert on the guards themselves, which
 * is what has to hold: a test that only checked the task would leave every other
 * caller of the egress layer unprotected, and that is precisely how the gap arose.
 *
 * @package    catquizcentralhub_client
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \catquizcentralhub_client\local\sync_policy
 */
final class sync_policy_test extends advanced_testcase {
    /**
     * Off is the default, and an unset switch is off.
     *
     * A data-transmitting feature that is on until someone turns it off would be the
     * wrong direction for a mistake to point.
     *
     * @return void
     */
    public function test_disabled_by_default(): void {
        $this->resetAfterTest();

        $this->assertFalse(sync_policy::is_enabled());
    }

    /**
     * The switch is read fresh, so turning it off takes effect at once.
     *
     * The issue asks for no manual cache purge to be necessary.
     *
     * @return void
     */
    public function test_switch_takes_effect_immediately(): void {
        $this->resetAfterTest();

        set_config('enable_sync_as_node', 1, 'catquizcentralhub_client');
        $this->assertTrue(sync_policy::is_enabled());

        set_config('enable_sync_as_node', 0, 'catquizcentralhub_client');
        $this->assertFalse(
            sync_policy::is_enabled(),
            'A switch flipped to off must not keep sending until a cache expires.'
        );
    }

    /**
     * Credentials are judged separately from the switch.
     *
     * Missing credentials while synchronisation is off is a no-op, not an error. The
     * reverse order produced the reported task failure whose faildelay had grown to
     * 86400 seconds.
     *
     * @return void
     */
    public function test_credentials_are_separate_from_the_switch(): void {
        $this->resetAfterTest();

        $this->assertFalse(sync_policy::has_credentials());

        set_config('central_host', 'https://hub.example.invalid', 'catquizcentralhub_client');
        $this->assertFalse(sync_policy::has_credentials(), 'A host alone is not enough.');

        set_config('central_token', 'secret', 'catquizcentralhub_client');
        $this->assertTrue(sync_policy::has_credentials());

        // Credentials say nothing about permission to use them.
        $this->assertFalse(sync_policy::is_enabled());
    }

    /**
     * The scale list is an allowlist, not a hint.
     *
     * @return void
     */
    public function test_scale_labels_are_an_allowlist(): void {
        $this->resetAfterTest();

        set_config('node_scale_labels', "K1\nK2\n", 'catquizcentralhub_client');

        $this->assertSame(['K1', 'K2'], sync_policy::get_allowed_scale_labels());
        $this->assertTrue(sync_policy::is_scale_allowed('K1'));
        $this->assertTrue(sync_policy::is_scale_allowed(' K2 '), 'Whitespace must not decide.');
        $this->assertFalse(
            sync_policy::is_scale_allowed('K3'),
            'A scale outside the list must be refused even if it exists locally.'
        );

        $this->expectException(\moodle_exception::class);
        sync_policy::require_scale_allowed('K3');
    }

    /**
     * Both places that actually reach outside are behind the guard.
     *
     * The audit found exactly two, and both must check for themselves: a caller that
     * forgets is how the switch came to be bypassable.
     *
     * @return void
     */
    public function test_both_egress_points_are_guarded(): void {
        global $CFG;

        $this->resetAfterTest();

        $base = $CFG->dirroot . '/local/catquiz/catquizcentralhub/client/classes/';
        foreach (
            [
            'client/response_submitter.php',
            'external/fetch_parameters.php',
            ] as $file
        ) {
            $source = file_get_contents($base . $file);

            $this->assertMatchesRegularExpression(
                '/sync_policy::(is_enabled|require_enabled)/',
                $source,
                "$file sends data and must enforce the switch itself."
            );

            // The guard has to come before the request, not after it.
            $guard = min(array_filter([
                strpos($source, 'sync_policy::is_enabled'),
                strpos($source, 'sync_policy::require_enabled'),
            ], fn ($position) => $position !== false));
            $post = strpos($source, 'curl->post');

            $this->assertNotFalse($post, "$file is expected to contain the request.");
            $this->assertLessThan(
                $post,
                $guard,
                "In $file the check must happen before anything is sent."
            );
        }
    }

    /**
     * The scheduled task ends cleanly instead of failing when sync is off.
     *
     * @return void
     */
    public function test_task_checks_the_switch_before_the_credentials(): void {
        global $CFG;

        $this->resetAfterTest();

        $source = file_get_contents(
            $CFG->dirroot . '/local/catquiz/catquizcentralhub/client/classes/task/'
                . 'scheduled_submit_responses.php'
        );

        $switch = strpos($source, 'sync_policy::is_enabled');
        $credentials = strpos($source, 'nocentralconfig');

        $this->assertNotFalse($switch, 'The task must read the switch at all.');
        $this->assertNotFalse($credentials);
        $this->assertLessThan(
            $credentials,
            $switch,
            'Checking credentials first turns a disabled instance into a failing task.'
        );
    }
}
