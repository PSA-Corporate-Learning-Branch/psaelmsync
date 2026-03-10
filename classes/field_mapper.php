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
 * Configurable field mapper for translating between API and internal field names.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_psaelmsync;

/**
 * Maps between canonical (internal) field names and configurable API field names.
 *
 * The plugin uses canonical field names internally (matching the original hardcoded
 * API field names). Admins can configure which API field names correspond to each
 * canonical concept. The mapper handles translation at API boundaries.
 */
class field_mapper {
    /**
     * Intake field definitions: canonical_name => [default API name, required, description key].
     *
     * @var array
     */
    private const INTAKE_FIELDS = [
        'record_enrol_id' => [
            'default' => 'record_enrol_id',
            'required' => true,
            'label' => 'mapping_record_enrol_id',
        ],
        'date_created' => [
            'default' => 'date_created',
            'required' => true,
            'label' => 'mapping_date_created',
        ],
        'ENROLMENT_ID' => [
            'default' => 'ENROLMENT_ID',
            'required' => true,
            'label' => 'mapping_enrolment_id',
        ],
        'COURSE_IDENTIFIER' => [
            'default' => 'COURSE_IDENTIFIER',
            'required' => true,
            'label' => 'mapping_course_identifier',
        ],
        'COURSE_STATE' => [
            'default' => 'COURSE_STATE',
            'required' => true,
            'label' => 'mapping_course_state',
        ],
        'COURSE_SHORTNAME' => [
            'default' => 'COURSE_SHORTNAME',
            'required' => true,
            'label' => 'mapping_course_shortname',
        ],
        'FIRST_NAME' => [
            'default' => 'FIRST_NAME',
            'required' => true,
            'label' => 'mapping_first_name',
        ],
        'LAST_NAME' => [
            'default' => 'LAST_NAME',
            'required' => true,
            'label' => 'mapping_last_name',
        ],
        'EMAIL' => [
            'default' => 'EMAIL',
            'required' => true,
            'label' => 'mapping_email',
        ],
        'GUID' => [
            'default' => 'GUID',
            'required' => true,
            'label' => 'mapping_guid',
        ],
        'OPRID' => [
            'default' => 'OPRID',
            'required' => false,
            'label' => 'mapping_oprid',
        ],
        'ACTIVITY_ID' => [
            'default' => 'ACTIVITY_ID',
            'required' => false,
            'label' => 'mapping_activity_id',
        ],
        'PERSON_ID' => [
            'default' => 'PERSON_ID',
            'required' => false,
            'label' => 'mapping_person_id',
        ],
        'COURSE_LONG_NAME' => [
            'default' => 'COURSE_LONG_NAME',
            'required' => false,
            'label' => 'mapping_course_long_name',
        ],
        'USER_STATE' => [
            'default' => 'USER_STATE',
            'required' => false,
            'label' => 'mapping_user_state',
        ],
        'COURSE_STATE_DATE' => [
            'default' => 'COURSE_STATE_DATE',
            'required' => false,
            'label' => 'mapping_course_state_date',
        ],
        'USER_EFFECTIVE_DATE' => [
            'default' => 'USER_EFFECTIVE_DATE',
            'required' => false,
            'label' => 'mapping_user_effective_date',
        ],
    ];

    /**
     * Completion-only field definitions (outbound POST fields not in intake).
     *
     * @var array
     */
    private const COMPLETION_EXTRA_FIELDS = [
        'COURSE_COMPLETE_DATE' => [
            'default' => 'COURSE_COMPLETE_DATE',
            'required' => true,
            'label' => 'mapping_course_complete_date',
        ],
    ];

    /** @var array canonical_name => api_field_name for intake */
    private array $intakemap;

    /** @var array canonical_name => api_field_name for completion */
    private array $completionmap;

    /**
     * Constructor. Loads mappings from plugin config, falling back to defaults.
     */
    public function __construct() {
        $this->intakemap = self::load_map('field_mapping_intake', 'intake');
        $this->completionmap = self::load_map('field_mapping_completion', 'completion');
    }

    /**
     * Load a mapping from config, falling back to defaults.
     *
     * @param string $configkey The config_plugins key.
     * @param string $type 'intake' or 'completion'.
     * @return array canonical => api_field_name mapping.
     */
    private static function load_map(string $configkey, string $type): array {
        $json = get_config('local_psaelmsync', $configkey);
        if (!empty($json)) {
            $map = json_decode($json, true);
            if (is_array($map)) {
                return $map;
            }
        }
        return self::get_defaults($type);
    }

    /**
     * Get default mappings (identity mapping using current hardcoded field names).
     *
     * @param string $type 'intake' or 'completion'.
     * @return array canonical => default_api_field_name mapping.
     */
    public static function get_defaults(string $type = 'intake'): array {
        $map = [];
        if ($type === 'intake') {
            foreach (self::INTAKE_FIELDS as $canonical => $def) {
                $map[$canonical] = $def['default'];
            }
        } else {
            // Completion uses most intake fields plus completion-specific ones.
            $allfields = array_merge(self::INTAKE_FIELDS, self::COMPLETION_EXTRA_FIELDS);
            foreach ($allfields as $canonical => $def) {
                $map[$canonical] = $def['default'];
            }
        }
        return $map;
    }

    /**
     * Normalize an API record: translate API field names to canonical names.
     *
     * This is the primary translation point for inbound data. After calling
     * this, the rest of the code can use canonical names (e.g. $record['FIRST_NAME']).
     *
     * @param array $apirecord Raw record from the API response.
     * @return array Record with canonical field names as keys.
     */
    public function normalize(array $apirecord): array {
        $result = [];
        // Build reverse map: api_field_name => canonical_name.
        $reverse = array_flip($this->intakemap);

        foreach ($apirecord as $apifield => $value) {
            if (isset($reverse[$apifield])) {
                $result[$reverse[$apifield]] = $value;
            }
        }
        return $result;
    }

    /**
     * Build an outbound payload using the intake field mapping.
     *
     * Translates canonical keys to configured API field names for POSTing
     * records to the intake/enrolment API (e.g. test record generation).
     *
     * @param array $canonicaldata Data keyed by canonical field names.
     * @return array Data keyed by configured API field names.
     */
    public function intake_payload(array $canonicaldata): array {
        $result = [];
        foreach ($this->intakemap as $canonical => $apifield) {
            if (array_key_exists($canonical, $canonicaldata)) {
                $result[$apifield] = $canonicaldata[$canonical];
            }
        }
        return $result;
    }

    /**
     * Build a completion POST payload.
     *
     * Translates canonical keys to configured API field names for the
     * completion API endpoint.
     *
     * @param array $canonicaldata Data keyed by canonical field names.
     * @return array Data keyed by configured completion API field names.
     */
    public function completion_payload(array $canonicaldata): array {
        $result = [];
        foreach ($this->completionmap as $canonical => $apifield) {
            if (array_key_exists($canonical, $canonicaldata)) {
                $result[$apifield] = $canonicaldata[$canonical];
            }
        }
        return $result;
    }

    /**
     * Get the API field name for a canonical concept (for OData filter construction).
     *
     * @param string $canonical The canonical field name.
     * @return string The configured API field name.
     */
    public function filter_field(string $canonical): string {
        return $this->intakemap[$canonical] ?? $canonical;
    }

    /**
     * Get the intake field definitions (for the mapping UI).
     *
     * @return array Field definitions keyed by canonical name.
     */
    public static function get_intake_fields(): array {
        return self::INTAKE_FIELDS;
    }

    /**
     * Get the completion-specific field definitions (for the mapping UI).
     *
     * @return array Field definitions keyed by canonical name.
     */
    public static function get_completion_extra_fields(): array {
        return self::COMPLETION_EXTRA_FIELDS;
    }

    /**
     * Get the full set of completion field definitions (intake + completion extras).
     *
     * @return array Field definitions keyed by canonical name.
     */
    public static function get_completion_fields(): array {
        return array_merge(self::INTAKE_FIELDS, self::COMPLETION_EXTRA_FIELDS);
    }

    /**
     * Get the current intake mapping.
     *
     * @return array canonical => api_field_name.
     */
    public function get_intake_map(): array {
        return $this->intakemap;
    }

    /**
     * Get the current completion mapping.
     *
     * @return array canonical => api_field_name.
     */
    public function get_completion_map(): array {
        return $this->completionmap;
    }

    /**
     * Save an intake mapping to config.
     *
     * @param array $map canonical => api_field_name mapping.
     */
    public static function save_intake_map(array $map): void {
        set_config('field_mapping_intake', json_encode($map), 'local_psaelmsync');
    }

    /**
     * Save a completion mapping to config.
     *
     * @param array $map canonical => api_field_name mapping.
     */
    public static function save_completion_map(array $map): void {
        set_config('field_mapping_completion', json_encode($map), 'local_psaelmsync');
    }

    /**
     * Fetch a sample record from the API for field discovery.
     *
     * @return array ['success' => bool, 'fields' => string[], 'sample' => array, 'error' => string]
     */
    public static function discover_fields(): array {
        $apiurl = get_config('local_psaelmsync', 'apiurl');
        $apitoken = get_config('local_psaelmsync', 'apitoken');

        if (empty($apiurl) || empty($apitoken)) {
            return [
                'success' => false,
                'fields' => [],
                'sample' => [],
                'error' => get_string('mapping_discover_noconfig', 'local_psaelmsync'),
            ];
        }

        // Fetch a single record to discover field names.
        $queryurl = $apiurl . '?%24top=1';

        $options = [
            'RETURNTRANSFER' => 1,
            'HEADER' => 0,
        ];
        $header = ['x-cdata-authtoken: ' . $apitoken];
        $curl = new \curl();
        $curl->setHeader($header);
        $response = $curl->get($queryurl, $options);

        if ($curl->get_errno()) {
            return [
                'success' => false,
                'fields' => [],
                'sample' => [],
                'error' => get_string('mapping_discover_curlerror', 'local_psaelmsync') . $curl->error,
            ];
        }

        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;
        if ($httpcode >= 400) {
            return [
                'success' => false,
                'fields' => [],
                'sample' => [],
                'error' => get_string('mapping_discover_httperror', 'local_psaelmsync') . $httpcode,
            ];
        }

        $data = json_decode($response, true);
        if (empty($data['value']) || !is_array($data['value'])) {
            return [
                'success' => false,
                'fields' => [],
                'sample' => [],
                'error' => get_string('mapping_discover_norecords', 'local_psaelmsync'),
            ];
        }

        $sample = $data['value'][0];
        $fields = array_keys($sample);
        sort($fields);

        return [
            'success' => true,
            'fields' => $fields,
            'sample' => $sample,
            'error' => '',
        ];
    }
}
