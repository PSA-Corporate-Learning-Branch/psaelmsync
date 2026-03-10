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
 * Admin setting for the high-water mark with double confirmation.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_psaelmsync\admin;

/**
 * A protected integer setting that requires two confirmation checkboxes before saving.
 *
 * The field is displayed read-only by default. The user must check an "unlock"
 * checkbox to reveal the input field, and then check a second "I understand"
 * confirmation before the new value will be saved.
 */
class setting_highwatermark extends \admin_setting {
    /**
     * Return the current setting value.
     *
     * @return mixed
     */
    public function get_setting() {
        return $this->config_read($this->name);
    }

    /**
     * Write the setting only if both confirmation checkboxes are checked.
     *
     * @param mixed $data The form data (array with 'value', 'unlock', 'confirm' keys).
     * @return string Empty on success, error message on failure.
     */
    public function write_setting($data) {
        if (!is_array($data)) {
            return '';
        }

        $newvalue = (int) ($data['value'] ?? 0);
        $unlock = !empty($data['unlock']);
        $confirm = !empty($data['confirm']);
        $currentvalue = (int) $this->get_setting();

        // If the value hasn't changed, just accept it silently.
        if ($newvalue === $currentvalue) {
            return '';
        }

        // Value changed but unlock not checked.
        if (!$unlock) {
            return get_string('highwatermark_err_unlock', 'local_psaelmsync');
        }

        // Unlock checked but confirmation not checked.
        if (!$confirm) {
            return get_string('highwatermark_err_confirm', 'local_psaelmsync');
        }

        // Validate: must be a non-negative integer.
        if ($newvalue < 0) {
            return get_string('highwatermark_err_negative', 'local_psaelmsync');
        }

        return ($this->config_write($this->name, $newvalue) ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Render the setting HTML.
     *
     * @param mixed $data Current value.
     * @param string $query Search query for highlighting.
     * @return string HTML output.
     */
    public function output_html($data, $query = '') {
        $default = $this->get_defaultsetting();
        $id = $this->get_id();
        $name = $this->get_full_name();
        $currentvalue = (int) $data;

        $warning = get_string('highwatermark_warning', 'local_psaelmsync');
        $unlocklabel = get_string('highwatermark_unlock', 'local_psaelmsync');
        $confirmlabel = get_string('highwatermark_confirm', 'local_psaelmsync');

        $html = '';

        // Hidden input always submits the current value. If the user unlocks
        // and types a new value, the visible input (same name, later in DOM)
        // will override this on submission.
        $html .= \html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => $name . '[value]',
            'value' => $currentvalue,
        ]);

        // Current value display.
        $html .= \html_writer::tag(
            'div',
            \html_writer::tag('strong', get_string('highwatermark_current', 'local_psaelmsync') . ' ')
            . \html_writer::tag('code', s($currentvalue)),
            ['style' => 'margin-bottom: 0.5rem;']
        );

        // Collapsible modify section using <details> (works without JS).
        $html .= \html_writer::tag(
            'details',
            \html_writer::tag(
                'summary',
                get_string('highwatermark_modify', 'local_psaelmsync'),
                ['style' => 'cursor: pointer; font-weight: bold; color: #b94a48;']
            )
            . \html_writer::tag(
                'div',
                // Warning box.
                \html_writer::tag('div', $warning, [
                    'class' => 'alert alert-danger',
                    'role' => 'alert',
                    'style' => 'margin-top: 0.5rem;',
                ])
                // Step 1: Unlock checkbox.
                . \html_writer::tag(
                    'div',
                    \html_writer::checkbox(
                        $name . '[unlock]',
                        1,
                        false,
                        $unlocklabel,
                        ['id' => $id . '_unlock']
                    ),
                    ['style' => 'margin-bottom: 0.5rem; font-weight: bold;']
                )
                // The editable input field — visible in no-JS, hidden by JS until unlock.
                . \html_writer::tag(
                    'div',
                    \html_writer::tag(
                        'label',
                        get_string('highwatermark_newvalue', 'local_psaelmsync'),
                        ['for' => $id, 'class' => 'sr-only']
                    )
                    . \html_writer::empty_tag('input', [
                        'type' => 'number',
                        'id' => $id,
                        'name' => $name . '[value]',
                        'value' => $currentvalue,
                        'min' => 0,
                        'class' => 'form-control',
                        'style' => 'max-width: 20rem;',
                    ]),
                    ['id' => $id . '_field', 'style' => 'margin-bottom: 0.5rem;']
                )
                // Step 2: Confirmation checkbox.
                . \html_writer::tag(
                    'div',
                    \html_writer::checkbox(
                        $name . '[confirm]',
                        1,
                        false,
                        '',
                        ['id' => $id . '_confirm']
                    )
                    . \html_writer::tag('label', $confirmlabel, [
                        'for' => $id . '_confirm',
                        'style' => 'font-weight: bold; color: #b94a48;',
                    ]),
                    ['id' => $id . '_confirmdiv', 'style' => 'margin-top: 0.25rem;']
                ),
                []
            ),
            ['id' => $id . '_details']
        );

        // Progressive enhancement: hide field + confirm until unlock is checked.
        $html .= \html_writer::script("
            (function() {
                var unlock = document.getElementById('" . $id . "_unlock');
                var fieldDiv = document.getElementById('" . $id . "_field');
                var input = document.getElementById('" . $id . "');
                var confirmDiv = document.getElementById('" . $id . "_confirmdiv');
                var confirmBox = document.getElementById('" . $id . "_confirm');

                function toggle() {
                    if (unlock.checked) {
                        fieldDiv.style.display = '';
                        input.disabled = false;
                        confirmDiv.style.display = '';
                    } else {
                        fieldDiv.style.display = 'none';
                        input.disabled = true;
                        input.value = " . (int) $currentvalue . ";
                        confirmDiv.style.display = 'none';
                        confirmBox.checked = false;
                    }
                }

                unlock.addEventListener('change', toggle);
                toggle();
            })();
        ");

        return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', $default, $query);
    }
}
