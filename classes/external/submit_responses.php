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
 * External API for submitting responses to the central hub.
 *
 * @package    catquizcentralhub_client
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace catquizcentralhub_client\external;

use catquizcentralhub_client\client\response_submitter;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;
use Throwable;
use catquizcentralhub_client\local\sync_policy;

/**
 * External API class for submitting responses to the central hub.
 *
 * @package    catquizcentralhub_client
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_responses extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'scaleid' => new external_value(PARAM_INT, 'ID of the scale for which responses are submitted', VALUE_REQUIRED),
        ]);
    }

    /**
     * Submit responses for a given scale to the central hub.
     *
     * @param int $scaleid The ID of the scale for which responses are submitted
     * @return array Status message indicating the result of the submission
     */
    public static function execute($scaleid) {
        try {
            $config = get_config('catquizcentralhub_client');
            if (empty($config->central_host) || empty($config->central_token)) {
                throw new moodle_exception('nocentralconfig', 'catquizcentralhub_client');
            }

            global $DB;

            $params = self::validate_parameters(self::execute_parameters(), ['scaleid' => $scaleid]);

            // Issue #65: a data-egress endpoint enforces its own permission. The entry in
            // db/services.php is advisory metadata and is not checked at run time, so it
            // is no protection on its own.
            $context = \context_system::instance();
            self::validate_context($context);
            require_capability('moodle/site:config', $context);

            // The switch is a kill-switch, not a display setting: with synchronisation
            // off nothing may leave this instance, whatever a caller asks for.
            sync_policy::require_enabled();
            if (!$label = $DB->get_field('local_catquiz_catscales', 'label', ['id' => $scaleid], MUST_EXIST)) {
                return [
                    'message' => get_string(
                        'submission_error',
                        'catquizcentralhub_client',
                        get_string('missing_scale_label', 'catquizcentralhub_client')
                    ),
                    'success' => false,
                ];
            }

            $submission = new response_submitter(
                $config->central_host,
                $config->central_token,
                $label
            );
            $result = $submission->submit_responses();

            if ($result->success) {
                return [
                    'message' => $result->message ?? get_string(
                        'submission_success',
                        'catquizcentralhub_client',
                        (object)[
                            'total' => $result->processed,
                            'added' => $result->added,
                            'skipped' => $result->skipped,
                        ]
                    ),
                    'success' => true,
                ];
            }

            return [
                'message' => get_string('submission_error', 'catquizcentralhub_client', $result->error),
                'success' => false,
            ];
        } catch (Throwable $t) {
            return [
                'message' => sprintf('Could not submit responses: "%s" in %s:%d', $t->getMessage(), $t->getFile(), $t->getLine()),
                'success' => false,
            ];
        }
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'message' => new external_value(PARAM_TEXT, 'Message'),
            'success' => new external_value(PARAM_BOOL, 'Request was successful'),
        ]);
    }
}
