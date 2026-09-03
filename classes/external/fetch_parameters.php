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

namespace catquizcentralhub_client\external;

use catquizcentralhub_client\local\sync_event;
use catquizcentralhub_client\repository\sync_repository;
use local_catquiz\hash\question_hasher;
use dml_write_exception;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_multiple_structure;
use local_catquiz\catquiz;
use local_catquiz\catscale;
use local_catquiz\data\dataapi;
use local_catquiz\local\model\model_item_param;
use catquizcentralhub_client\local\sync_policy;

/**
 * External service for fetching calculated item parameters from the central hub.
 *
 * @package    catquizcentralhub_client
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fetch_parameters extends external_api {
    /** @var array Internal helper to check if an item exists already in the new context */
    private static array $existingitems = [];

    /** @var ?array Stores context IDs between the fetched and older contexts */
    private static ?array $intermediatecontexts = null;

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'scaleid' => new external_value(PARAM_INT, 'ID of the scale to sync'),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Status of the sync'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'duration' => new external_value(PARAM_FLOAT, 'Duration in seconds'),
            'synced' => new external_value(PARAM_INT, 'Number of parameters synced'),
            'errors' => new external_value(PARAM_INT, 'Number of errors encountered'),
            'warnings' => new external_multiple_structure(
                new external_single_structure([
                    'item' => new external_value(PARAM_TEXT, 'Item identifier'),
                    'warning' => new external_value(PARAM_TEXT, 'Warning message'),
                ])
            ),
        ]);
    }

    /**
     * Fetch and store item parameters from the central hub.
     *
     * @param int $scaleid ID of the scale to sync
     * @return array Status and result information
     */
    public static function execute(int $scaleid) {
        global $DB;
        $repo = new catquiz();

        $params = self::validate_parameters(self::execute_parameters(), [
            'scaleid' => $scaleid,
        ]);

        // Issue #65: an endpoint that contacts an external host enforces its own
        // permission; the entry in db/services.php is metadata, not a runtime check.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $centralurl = get_config('catquizcentralhub_client', 'central_host') ?? '';
        $wstoken = get_config('catquizcentralhub_client', 'central_token') ?? '';

        $starttime = microtime(true);
        $warnings = [];
        $errors = 0;
        $changedparams = [];

        $scale = $DB->get_record('local_catquiz_catscales', ['id' => $params['scaleid']], '*', MUST_EXIST);
        if (!$scale) {
            throw new \moodle_exception('scalenotfound', 'catquizcentralhub_client', '', $params['scaleid']);
        }
        if (!$scale->label) {
            throw new \moodle_exception('scalehasnolabel', 'catquizcentralhub_client', '', $params['scaleid']);
        }

        $targetcontext = sync_repository::get_last_synced_context_id($scale->id);
        $activecontext = catscale::get_context_id($scale->id);
        if (!$targetcontext) {
            $targetcontext = $activecontext;
        }

        $scalemapping = [];
        $catscaleids = [$scale->id, ...catscale::get_subscale_ids($scale->id)];
        [$inscalesql, $inscaleparams] = $DB->get_in_or_equal($catscaleids, SQL_PARAMS_NAMED, 'scaleid');

        $sql = "SELECT * FROM {local_catquiz_catscales} lcs WHERE lcs.id $inscalesql";
        $scalerecords = $DB->get_records_sql($sql, $inscaleparams);
        foreach ($scalerecords as $s) {
            $scalemapping[$s->label] = $s->id;
        }

        $sql = "SELECT DISTINCT q.id
                FROM {local_catquiz_items} lci
                JOIN {question} q ON q.id = lci.componentid
                WHERE lci.catscaleid $inscalesql";

        $questions = $DB->get_records_sql($sql, $inscaleparams);
        $hashmap = [];

        foreach ($questions as $question) {
            try {
                $hash = question_hasher::generate_hash($question->id);
                $hashmap[$hash] = $question->id;
            } catch (dml_write_exception $e) {
                $error = 'Error generating hash for question ' . $question->id . ': ' . $e->error;
                debugging($error, DEBUG_DEVELOPER);
                return [
                    'status' => false,
                    'message' => $e->error,
                    'duration' => 0,
                    'synced' => 0,
                    'errors' => 1,
                    'warnings' => [],
                ];
            } catch (\Exception $e) {
                $error = 'Error generating hash for question ' . $question->id . ': ' . $e->getMessage();
                debugging($error, DEBUG_DEVELOPER);
                return [
                    'status' => false,
                    'message' => $error,
                    'duration' => 0,
                    'synced' => 0,
                    'errors' => 1,
                    'warnings' => [],
                ];
            }
        }

        $serverurl = rtrim($centralurl, '/') . '/webservice/rest/server.php';
        $wsparams = [
            'wstoken' => $wstoken,
            'wsfunction' => 'catquizcentralhub_host_distribute_parameters',
            'moodlewsrestformat' => 'json',
            'scalelabel' => $scale->label,
        ];

        // Issue #65: the second of the two places that actually reach outside. With
        // synchronisation switched off no request may leave the instance, even though
        // host and token are still stored - keeping the credentials is not consent to
        // use them.
        sync_policy::require_enabled();
        sync_policy::require_scale_allowed((string) $scale->label);

        $sslverify = !get_config('catquizcentralhub_client', 'skip_ssl_verification');
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_SSL_VERIFYPEER' => $sslverify,
            'CURLOPT_SSL_VERIFYHOST' => $sslverify ? 2 : 0,
        ]);
        $response = $curl->post($serverurl, $wsparams);
        $result = json_decode($response);

        if (!$result) {
            return [
                'status' => false,
                'message' => 'Invalid response from server: ' . $response,
                'duration' => 0,
                'synced' => 0,
                'errors' => 1,
                'warnings' => [],
            ];
        }

        if (!empty($result->exception)) {
            return [
                'status' => false,
                'message' => 'Web service error: ' . $result->message,
                'duration' => 0,
                'synced' => 0,
                'errors' => 1,
                'warnings' => [],
            ];
        }

        if (!$result->status) {
            return [
                'status' => false,
                'message' => 'Error: ' . $result->message,
                'duration' => 0,
                'synced' => 0,
                'errors' => 1,
                'warnings' => [],
            ];
        }

        $source = "Fetch from " . parse_url($centralurl, PHP_URL_HOST);
        $transaction = $DB->start_delegated_transaction();
        $newcontext = dataapi::create_new_context_for_scale(
            $scale->id,
            $scale->name,
            $source,
            false,
            false
        );

        $stored = 0;

        foreach ($result->parameters as $param) {
            $questionid = $hashmap[$param->questionhash] ?? null;
            if (!$questionid) {
                $warnings[] = [
                    'item' => $param->questionhash,
                    'warning' => get_string('noquestionhashmatch', 'catquizcentralhub_client'),
                ];
                continue;
            }

            if (!$param->scalelabel) {
                $warnings[] = [
                    'item' => $param->questionhash,
                    'warning' => 'Missing scale label',
                ];
                continue;
            }

            if (!array_key_exists($param->scalelabel, $scalemapping)) {
                $warnings[] = [
                    'item' => $param->scalelabel,
                    'warning' => get_string(
                        'nolocalmappingforscale',
                        'catquizcentralhub_client',
                        (object) ['remotelabel' => $param->scalelabel]
                    ),
                ];
                continue;
            }

            try {
                $localparam = $repo->get_item_with_params(
                    $questionid,
                    $param->model,
                    $targetcontext
                );

                if (!$localparam) {
                    $intermediatecontexts = self::get_intermediate_context_ids(
                        min($activecontext, [$targetcontext]),
                        $newcontext->id
                    );
                    foreach ($intermediatecontexts as $context) {
                        $localparam = $repo->get_itemparams_for(
                            [
                                'componentid' => $questionid,
                                'model' => $param->model,
                                'contextid' => $context,
                            ]
                        );
                        if ($localparam) {
                            break;
                        }
                    }
                }

                if (!$localparam) {
                    if (!self::item_exists($questionid)) {
                        $itemrecord = new \stdClass();
                        $itemrecord->componentid = $questionid;
                        $itemrecord->componentname = 'question';
                        $itemrecord->catscaleid = $scalemapping[$param->scalelabel];
                        $itemrecord->contextid = $newcontext->id;
                        $itemrecord->status = LOCAL_CATQUIZ_STATUS_UPDATED_MANUALLY;
                        $itemid = $DB->insert_record('local_catquiz_items', $itemrecord);
                        self::$existingitems[$questionid] = true;
                    }
                }

                $changed = !$localparam
                    || !self::check_float_equal($param->difficulty, $localparam->difficulty)
                    || !self::check_float_equal($param->discrimination, $localparam->discrimination)
                    || !self::check_float_equal($param->guessing, $localparam->guessing)
                    || $param->json != $localparam->json
                    || $param->status != $localparam->status;

                if ($changed) {
                    $changedparams[] = ['old' => $localparam ?? null, 'new' => $param];
                }

                $record = (object)[
                    'itemid' => $itemid ?? null,
                    'componentid' => $questionid,
                    'componentname' => 'question',
                    'model' => $param->model,
                    'contextid' => $newcontext->id,
                    'difficulty' => $param->difficulty,
                    'discrimination' => $param->discrimination,
                    'guessing' => $param->guessing ?? 0,
                    'status' => $param->status,
                    'json' => $param->json,
                ];

                $itemparam = model_item_param::from_record($record);
                $itemparam->save();
                $stored++;
            } catch (\Exception $e) {
                debugging('Error storing parameter: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $warnings[] = [
                    'item' => $param->scalelabel,
                    'warning' => $e->getMessage(),
                ];
                $errors++;
            }
        }

        $countchanged = count($changedparams);
        if (count($changedparams) > 0) {
            $DB->commit_delegated_transaction($transaction);

            $syncevent = new sync_event(
                $newcontext->id,
                $scale->id,
                $countchanged
            );
            $syncevent->save();
        }

        $duration = microtime(true) - $starttime;

        return [
            'status' => $errors == 0,
            'message' => $countchanged > 0
            ? get_string(
                'fetchsuccess',
                'catquizcentralhub_client',
                (object) ['num' => $stored, 'contextname' => $newcontext->name]
            )
            : get_string('fetchempty', 'catquizcentralhub_client'),
            'duration' => round($duration, 2),
            'synced' => $countchanged,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Internal helper to check if there is an item for that question.
     *
     * @param int $questionid The questionid
     * @return bool
     */
    private static function item_exists(int $questionid) {
        global $DB;
        if (array_key_exists($questionid, self::$existingitems)) {
            return true;
        }
        $existsforquestion = $DB->record_exists('local_catquiz_items', ['componentid' => $questionid]);
        self::$existingitems[$questionid] = $existsforquestion;
        return $existsforquestion;
    }

    /**
     * Checks if two float numbers are equal within a given precision.
     *
     * @param float $num1 First number to compare
     * @param float $num2 Second number to compare
     * @param int $precision Number of decimal places to check (default: 4)
     * @return bool
     */
    private static function check_float_equal(float $num1, float $num2, int $precision = 4) {
        return abs($num1 - $num2) < pow(10, -1 * $precision);
    }

    /**
     * Returns a simple range of context IDs between start- and end-context.
     *
     * @param int $startcontextid
     * @param int $endcontextid
     * @return array
     */
    private static function get_intermediate_context_ids(int $startcontextid, int $endcontextid) {
        if (is_null(self::$intermediatecontexts)) {
            self::$intermediatecontexts = array_reverse(range($startcontextid, $endcontextid));
        }
        return self::$intermediatecontexts;
    }
}
