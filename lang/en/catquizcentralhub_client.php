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
 * Language strings for catquizcentralhub_client.
 *
 * @package     catquizcentralhub_client
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$string['centralhost'] = 'Compute instance';
$string['centralhostdesc'] = 'The host that will perform parameter calculations. E.g. https://www.example.com';
$string['centraltoken'] = 'Token to access compute instance';
$string['centraltokendesc'] = 'The token that was created for you on the compute instance';
$string['enablesyncasnode'] = 'Allow synchronization with hub';
$string['enablesyncasnodedesc'] = 'When activated, this instance can submit response data to a central hub and import calculated item parameters.';
$string['fetchempty'] = 'Parameters are up to date';
$string['fetchparamheading'] = 'Parameters will be received from {$a}';
$string['fetchsuccess'] = 'Successfully stored {$a->num} parameters in new context {$a->contextname}';
$string['invalidresponse'] = 'Invalid response received from central instance.';
$string['missing_scale_label'] = 'The selected scale does not have a label associated with it';
$string['nocentralconfig'] = 'Central instance configuration missing. Please configure host and token in plugin settings.';
$string['nodescalelabels'] = 'Scale labels to synchronize';
$string['nodescalelabelsdesc'] = 'Enter one scale label per line. Only these scales will be submitted to the central hub.';
$string['nolocalmappingforscale'] = 'No scale with label "{$a->remotelabel}" found on local instance';
$string['nonewresponses'] = 'There are no new responses to share';
$string['noquestionhashmatch'] = 'No matching question found for hash';
$string['pluginname'] = 'CatQuiz Central Hub (Client)';
$string['responses_submitted'] = 'New responses were shared';
$string['responses_submitted_desc'] = 'New responses were shared with central compute instance {$a->centralhost}. {$a->added} new '
    . 'responses were added, {$a->skipped} were skipped and {$a->errors} errors occurred';
$string['scalehasnolabel'] = 'Scale has no label';
$string['scalenotallowed'] = 'The scale "{$a}" is not configured for synchronisation.';
$string['scalenotfound'] = 'Scale not found';
$string['skipsslverification'] = 'Skip SSL verification';
$string['skipsslverificationdesc'] = 'Disable SSL certificate verification when connecting to the central hub. Only enable this for development or testing environments.';
$string['submission_error'] = 'Error submitting responses: {$a}';
$string['submission_success'] = '{$a->total} responses successfully submitted. {$a->added} new responses were added and {$a->skipped} were skipped.';
$string['submit_responses'] = 'Submit responses to central instance';
$string['submitresponsescheduled'] = 'Submit responses to central hub (scheduled)';
$string['syncdisabled'] = 'Synchronisation with the central hub is disabled.';
$string['unknownerror'] = 'An unknown error occurred';
