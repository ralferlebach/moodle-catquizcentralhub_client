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

namespace catquizcentralhub_client\task;

use catquizcentralhub_client\client\response_submitter;
use core\task\scheduled_task;
use moodle_exception;
use catquizcentralhub_client\local\sync_policy;

/**
 * Scheduled task to submit CAT quiz responses to the central hub.
 *
 * @package    catquizcentralhub_client
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduled_submit_responses extends scheduled_task {
    /**
     * Returns the name of the scheduled task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('submitresponsescheduled', 'catquizcentralhub_client');
    }

    /**
     * Submits all pending local responses to the central hub.
     *
     * @return void
     */
    public function execute() {
        // The switch is checked before anything else. Previously the
        // credentials were validated first, so an instance with synchronisation off
        // and no hub configured raised a task failure on every run - the reported
        // faildelay had grown to 86400 seconds. Switched off means there is nothing
        // to do, and nothing to do is a success.
        if (!sync_policy::is_enabled()) {
            mtrace('Central hub synchronisation is disabled - nothing to do.');
            return;
        }

        $labels = sync_policy::get_allowed_scale_labels();
        if (empty($labels)) {
            mtrace('No scales configured for central hub synchronisation.');
            return;
        }

        // Only now is missing configuration a genuine error: synchronisation is on
        // and scales are configured, but the hub cannot be reached.
        $config = get_config('catquizcentralhub_client');
        if (!sync_policy::has_credentials()) {
            throw new moodle_exception('nocentralconfig', 'catquizcentralhub_client');
        }

        foreach ($labels as $label) {
            $submission = new response_submitter(
                $config->central_host,
                $config->central_token,
                trim($label)
            );
            $result = $submission->submit_responses();

            if ($result->success) {
                mtrace(get_string(
                    'submission_success',
                    'catquizcentralhub_client',
                    (object)[
                        'total' => $result->processed,
                        'added' => $result->added,
                        'skipped' => $result->skipped,
                    ]
                ));
            } else {
                mtrace(get_string('submission_error', 'catquizcentralhub_client', $result->error));
            }
        }

        mtrace('All responses submitted successfully.');
    }
}
