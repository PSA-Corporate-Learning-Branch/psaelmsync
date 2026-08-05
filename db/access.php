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
 * Capability definitions for the PSA ELM Sync plugin.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // The dashboards display learner names, email addresses, GUIDs, IDIR/OPRIDs and
    // government PERSON_IDs, so holding this is enough to read personal data in bulk.
    'local/psaelmsync:viewlogs' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'admin' => CAP_ALLOW,
        ],
    ],
    // This permits creating Moodle accounts, enrolling and suspending users, sending
    // welcome mail, and pushing completion records into an external system of record --
    // none of which is obvious from the capability name alone.
    'local/psaelmsync:managedata' => [
        'riskbitmask' => RISK_PERSONAL | RISK_SPAM | RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'admin' => CAP_ALLOW,
        ],
    ],
];
