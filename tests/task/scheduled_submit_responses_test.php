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
 * Tests for the scheduled_submit_responses task.
 *
 * @package    catquizcentralhub_client
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace catquizcentralhub_client\task;

use advanced_testcase;
use moodle_exception;

/**
 * Tests for scheduled_submit_responses task.
 *
 * @package    catquizcentralhub_client
 * @covers     \catquizcentralhub_client\task\scheduled_submit_responses
 */
final class scheduled_submit_responses_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // Issue #65: the switch is a server-side gate now. These tests ran with it
        // off and still expected the service to work - which is exactly the state
        // the issue calls out as written into the tests. Enabling it here makes the
        // positive path explicit instead of implicit.
        set_config('enable_sync_as_node', 1, 'catquizcentralhub_client');
    }

    public function test_get_name_returns_non_empty_string(): void {
        $task = new scheduled_submit_responses();
        $this->assertIsString($task->get_name());
        $this->assertNotEmpty($task->get_name());
    }

    public function test_execute_throws_moodle_exception_when_no_central_host(): void {
        // Issue #65: missing credentials is only an error once synchronisation is on
        // AND scales are configured. Without scales there is nothing to send, which is
        // a no-op - that reordering is what stopped the endless task failures.
        set_config('node_scale_labels', "K1\n", 'catquizcentralhub_client');
        $this->expectException(moodle_exception::class);
        $task = new scheduled_submit_responses();
        $task->execute();
    }

    public function test_execute_throws_when_token_missing_but_host_set(): void {
        // Issue #65: missing credentials is only an error once synchronisation is on
        // AND scales are configured. Without scales there is nothing to send, which is
        // a no-op - that reordering is what stopped the endless task failures.
        set_config('node_scale_labels', "K1\n", 'catquizcentralhub_client');
        set_config('central_host', 'https://hub.example.com', 'catquizcentralhub_client');
        $this->expectException(moodle_exception::class);
        $task = new scheduled_submit_responses();
        $task->execute();
    }

    public function test_execute_does_not_throw_when_no_scale_labels_configured(): void {
        set_config('central_host', 'https://hub.example.com', 'catquizcentralhub_client');
        set_config('central_token', 'testtoken123', 'catquizcentralhub_client');
        // No node_scale_labels set → empty → nothing to process.
        $task = new scheduled_submit_responses();
        ob_start();
        $task->execute();
        ob_end_clean();
        // No exception = test passes.
        $this->assertTrue(true);
    }
}
