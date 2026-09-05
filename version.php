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
 * Version information for the catquizcentralhub_client subplugin.
 *
 * @package catquizcentralhub_client
 * @copyright  2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026090500;
$plugin->requires  = 2022041900;
$plugin->component = 'catquizcentralhub_client';

// Pinned to the parent plugin: these are subplugins of local_catquiz and use its
// classes directly. Without the pin Moodle installs them against any version of the
// parent, including one that predates the interfaces they rely on - and the failure
// then appears at run time rather than at install time.
//
// The pin follows the released parent on main, not the version currently being
// worked on. Pinning to an unreleased version would make these plugins
// uninstallable everywhere except on the development branch.
$plugin->dependencies = [
    'local_catquiz' => 2026083025,
];
