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

namespace catquizcentralhub_client\client;

use catquizcentralhub_client\event\responses_submitted;
use local_catquiz\hash\question_hasher;
use context_system;
use curl;
use local_catquiz\catquiz;
use local_catquiz\catscale;
use Throwable;
use catquizcentralhub_client\local\sync_policy;

/**
 * Handles submission of responses to the central hub.
 *
 * @package    catquizcentralhub_client
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class response_submitter {
    /** @var string The central hub URL */
    private $centralhost;

    /** @var string The web service token */
    private $token;

    /** @var int The scale to sync */
    private $scaleid;

    /** @var int The context to use */
    private int $contextid;

    /**
     * Label of the scale being synchronised, kept for the allowlist check.
     * @var string
     */
    private string $scalelabel;

    /**
     * Create a new response submitter.
     *
     * @param string $centralhost The central hub URL
     * @param string $token The web service token
     * @param string $scalelabel The label of the scale that will be synchronized
     * @param ?int $contextid The context to use. If left out, uses the scale's active context.
     */
    public function __construct(string $centralhost, string $token, string $scalelabel, ?int $contextid = null) {
        global $DB;
        $this->centralhost = rtrim($centralhost, '/');
        $this->token = $token;
        $this->scalelabel = $scalelabel;
        $this->scaleid = $DB->get_record('local_catquiz_catscales', ['label' => $scalelabel], 'id')->id;
        $this->contextid = $contextid ?? catscale::return_catscale_object($this->scaleid)->contextid;
    }

    /**
     * Submit responses to the central hub.
     *
     * @return \stdClass Result object with success status and details
     */
    public function submit_responses() {
        global $CFG, $USER;

        // Issue #65: the lowest layer that actually sends. Every caller above could
        // forget the check - and every caller did - so the guard belongs here as
        // well, not only in the task that happens to call it today.
        //
        // No data is read before this point: with synchronisation switched off there
        // is nothing to aggregate, let alone to transmit.
        if (!sync_policy::is_enabled()) {
            return (object) [
                'success' => true,
                'message' => get_string('syncdisabled', 'catquizcentralhub_client'),
            ];
        }

        // The scale allowlist is a server-side rule, not a hint: the setting says
        // only these scales are transmitted, so a scale outside it is refused even
        // when a caller asks for it explicitly.
        if (!sync_policy::is_scale_allowed($this->scalelabel)) {
            return (object) [
                'success' => false,
                'message' => get_string('scalenotallowed', 'catquizcentralhub_client', $this->scalelabel),
            ];
        }

        try {
            $responses = $this->get_response_data();
            if (empty($responses)) {
                return (object)[
                    'success' => true,
                    'message' => get_string('nonewresponses', 'catquizcentralhub_client'),
                ];
            }

            $serverurl = $this->centralhost . '/webservice/rest/server.php';
            $params = [
                'wstoken' => $this->token,
                'wsfunction' => 'catquizcentralhub_host_collect_responses',
                'moodlewsrestformat' => 'json',
                'jsondata' => json_encode($responses),
                'sourceurl' => $CFG->wwwroot,
            ];

            $curl = new curl();
            $sslverify = !get_config('catquizcentralhub_client', 'skip_ssl_verification');
            $curl->setopt([
                'CURLOPT_SSL_VERIFYPEER' => $sslverify,
                'CURLOPT_SSL_VERIFYHOST' => $sslverify ? 2 : 0,
            ]);
        } catch (Throwable $t) {
            return (object)[
                'success' => false,
                'error' => sprintf('Could not send data: %s in %s:%d', $t->getMessage(), $t->getFile(), $t->getLine()),
            ];
        }

        try {
            $response = $curl->post($serverurl, $params);
            $result = json_decode($response);

            if ($result === null) {
                debugging('Invalid JSON response from server: ' . $response, DEBUG_DEVELOPER);
                return (object)[
                    'success' => false,
                    'error' => sprintf('Invalid response from server: %s', $response),
                ];
            }

            if (!empty($result->exception)) {
                return (object)[
                    'success' => false,
                    'error' => $result->message,
                ];
            }

            $event = responses_submitted::create([
                'context' => context_system::instance(),
                'userid' => $USER->id,
                'other' => [
                    'centralhost' => $this->centralhost,
                    'added' => $result->added,
                    'skipped' => $result->skipped,
                    'errors' => count($result->errors),
                ],
            ]);
            $event->trigger();

            return (object)[
                'success' => true,
                'processed' => count($responses),
                'added' => $result->added,
                'skipped' => $result->skipped,
                'errors' => $result->errors,
            ];
        } catch (\Exception $e) {
            debugging('Error submitting responses: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return (object)[
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get response data to submit.
     *
     * @return array Array of response objects
     */
    private function get_response_data() {
        global $CFG, $DB;
        $catscaleids = [$this->scaleid, ...catscale::get_subscale_ids($this->scaleid)];
        [$sql, $params] = catquiz::get_sql_for_model_input(
            $this->contextid,
            $catscaleids,
            null,
            null,
            false,
            $this->scaleid
        );
        $data = $DB->get_records_sql($sql, $params);
        $instancename = parse_url($CFG->wwwroot, PHP_URL_HOST);
        $counter = 0;
        foreach ($data as $uniqueid => $response) {
            $data[$counter++] = [
                'questionhash' => question_hasher::generate_hash($response->questionid),
                'attemptid' => crc32($instancename . $response->attemptid),
                'ability' => $response->ability,
                'fraction' => $response->fraction ?? 0,
            ];
            unset($data[$uniqueid]);
        }
        return $data;
    }
}
