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
 * Field mapping configuration page for PSA ELM Sync.
 *
 * Allows admins to discover API field names and map them to the internal
 * concepts the plugin uses for enrollment processing and completion reporting.
 *
 * @package    local_psaelmsync
 * @copyright  2025 BC Public Service
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Commenting.MissingDocblock

require_once('../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_psaelmsync\field_mapper;

require_login();

$context = context_system::instance();
require_capability('local/psaelmsync:viewlogs', $context);

$PAGE->set_url('/local/psaelmsync/field-mapping.php');
$PAGE->set_context($context);
$PAGE->set_title(
    get_string('pluginname', 'local_psaelmsync')
    . ' - '
    . get_string('fieldmapping', 'local_psaelmsync')
);
$PAGE->set_heading(get_string('fieldmapping', 'local_psaelmsync'));

$mapper = new field_mapper();
$intakemap = $mapper->get_intake_map();
$completionmap = $mapper->get_completion_map();

// Handle form actions.
$action = optional_param('action', '', PARAM_ALPHA);
$feedback = '';
$feedbacktype = '';
$discoveredfields = [];

// Discover fields from API.
if ($action === 'discover' && confirm_sesskey()) {
    $result = field_mapper::discover_fields();
    if ($result['success']) {
        $discoveredfields = $result['fields'];
        $feedback = get_string(
            'mapping_discover_success',
            'local_psaelmsync',
            count($discoveredfields)
        );
        $feedbacktype = 'success';
    } else {
        $feedback = $result['error'];
        $feedbacktype = 'danger';
    }
}

// Save mappings.
if ($action === 'save' && confirm_sesskey()) {
    $intakeinput = optional_param_array('intake', [], PARAM_RAW);
    $completioninput = optional_param_array('completion', [], PARAM_RAW);

    // Validate: required intake fields must not be empty.
    $intakefields = field_mapper::get_intake_fields();
    $errors = [];
    foreach ($intakefields as $canonical => $def) {
        if ($def['required'] && empty(trim($intakeinput[$canonical] ?? ''))) {
            $errors[] = get_string($def['label'], 'local_psaelmsync');
        }
    }

    if (!empty($errors)) {
        $feedback = get_string('mapping_save_missing', 'local_psaelmsync')
            . ' ' . implode(', ', $errors);
        $feedbacktype = 'danger';
    } else {
        // Build clean intake map.
        $newintakemap = [];
        foreach ($intakefields as $canonical => $def) {
            $val = trim($intakeinput[$canonical] ?? '');
            $newintakemap[$canonical] = !empty($val) ? $val : $def['default'];
        }
        field_mapper::save_intake_map($newintakemap);
        $intakemap = $newintakemap;

        // Build clean completion map.
        $completionfields = field_mapper::get_completion_fields();
        $newcompletionmap = [];
        foreach ($completionfields as $canonical => $def) {
            $val = trim($completioninput[$canonical] ?? '');
            $newcompletionmap[$canonical] = !empty($val) ? $val : $def['default'];
        }
        field_mapper::save_completion_map($newcompletionmap);
        $completionmap = $newcompletionmap;

        $feedback = get_string('mapping_save_success', 'local_psaelmsync');
        $feedbacktype = 'success';

        // Reload mapper with new config.
        $mapper = new field_mapper();
    }
}

// Reset to defaults.
if ($action === 'reset' && confirm_sesskey()) {
    field_mapper::save_intake_map(field_mapper::get_defaults('intake'));
    field_mapper::save_completion_map(field_mapper::get_defaults('completion'));

    $mapper = new field_mapper();
    $intakemap = $mapper->get_intake_map();
    $completionmap = $mapper->get_completion_map();

    $feedback = get_string('mapping_reset_success', 'local_psaelmsync');
    $feedbacktype = 'info';
}

// For the discovery dropdown, we need to preserve discovered fields across the save form.
// We pass them as a hidden field if they were just discovered.
$discoveredfieldscsv = optional_param('discovered_fields', '', PARAM_RAW);
if (!empty($discoveredfieldscsv) && empty($discoveredfields)) {
    $discoveredfields = explode(',', $discoveredfieldscsv);
}

$intakefields = field_mapper::get_intake_fields();
$completionextrafields = field_mapper::get_completion_extra_fields();

echo $OUTPUT->header();
?>

<!-- Tabbed Navigation -->
<nav aria-label="PSA ELM Sync sections">
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link"
               href="/admin/settings.php?section=local_psaelmsync">
                Settings</a>
        </li>
        <li class="nav-item">
            <a class="nav-link"
               href="/local/psaelmsync/dashboard.php">
                Learner Dashboard</a>
        </li>
        <li class="nav-item">
            <a class="nav-link"
               href="/local/psaelmsync/dashboard-courses.php">
                Course Dashboard</a>
        </li>
        <li class="nav-item">
            <a class="nav-link"
               href="/local/psaelmsync/dashboard-intake.php">
                Intake Run History</a>
        </li>
        <li class="nav-item">
            <a class="nav-link"
               href="/local/psaelmsync/manual-intake.php">
                Manual Intake</a>
        </li>
        <li class="nav-item">
            <a class="nav-link"
               href="/local/psaelmsync/manual-complete.php">
                Manual Complete</a>
        </li>
        <li class="nav-item">
            <a class="nav-link"
               href="/local/psaelmsync/api-test.php">
                API Test</a>
        </li>
        <li class="nav-item">
            <a class="nav-link active"
               href="/local/psaelmsync/field-mapping.php"
               aria-current="page">Field Mapping</a>
        </li>
    </ul>
</nav>

<?php if (!empty($feedback)) : ?>
<div class="alert alert-<?php echo $feedbacktype; ?>" role="alert">
    <?php echo $feedback; ?>
</div>
<?php endif; ?>

<!-- API Field Discovery -->
<section class="card mb-4">
    <div class="card-header">
        <h3 class="card-title h5 mb-0">
            <?php echo get_string('mapping_discover_heading', 'local_psaelmsync'); ?>
        </h3>
    </div>
    <div class="card-body">
        <p class="text-muted">
            <?php echo get_string('mapping_discover_desc', 'local_psaelmsync'); ?>
        </p>
        <form method="post" action="field-mapping.php">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="discover">
            <button type="submit" class="btn btn-outline-primary">
                <?php echo get_string('mapping_discover_btn', 'local_psaelmsync'); ?>
            </button>
        </form>
        <?php if (!empty($discoveredfields)) : ?>
        <div class="mt-3">
            <p>
                <strong><?php
                        echo get_string('mapping_discover_found', 'local_psaelmsync', count($discoveredfields));
                ?></strong>
            </p>
            <div class="d-flex flex-wrap gap-1">
                <?php foreach ($discoveredfields as $field) : ?>
                    <code class="border rounded px-2 py-1">
                        <?php echo s($field); ?></code>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Mapping Form -->
<form method="post" action="field-mapping.php">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="action" value="save">
    <?php if (!empty($discoveredfields)) : ?>
        <input type="hidden" name="discovered_fields"
               value="<?php echo s(implode(',', $discoveredfields)); ?>">
    <?php endif; ?>

    <!-- Intake Mapping -->
    <section class="card mb-4">
        <div class="card-header">
            <h3 class="card-title h5 mb-0">
                <?php echo get_string('mapping_intake_heading', 'local_psaelmsync'); ?>
            </h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                <?php echo get_string('mapping_intake_desc', 'local_psaelmsync'); ?>
            </p>
            <table class="table table-striped">
                <caption class="sr-only">
                    <?php echo get_string('mapping_intake_heading', 'local_psaelmsync'); ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col"><?php
                            echo get_string('mapping_col_concept', 'local_psaelmsync');
                        ?></th>
                        <th scope="col"><?php
                            echo get_string('mapping_col_apifield', 'local_psaelmsync');
                        ?></th>
                        <th scope="col" class="text-center"><?php
                            echo get_string('mapping_col_required', 'local_psaelmsync');
                        ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($intakefields as $canonical => $def) :
                    $currentval = $intakemap[$canonical] ?? $def['default'];
                    $fieldid = 'intake_' . $canonical;
                    $isrequired = $def['required'];
                ?>
                    <tr>
                        <td>
                            <label for="<?php echo $fieldid; ?>">
                                <?php echo get_string($def['label'], 'local_psaelmsync'); ?>
                            </label>
                            <br>
                            <small class="text-muted">
                                <code><?php echo s($canonical); ?></code>
                            </small>
                        </td>
                        <td>
                            <?php if (!empty($discoveredfields)) : ?>
                            <select id="<?php echo $fieldid; ?>"
                                    name="intake[<?php echo s($canonical); ?>]"
                                    class="form-select"
                                    <?php echo $isrequired ? 'required' : ''; ?>>
                                <?php if (!$isrequired) : ?>
                                    <option value="">
                                        &mdash; <?php
                                                echo get_string('mapping_not_mapped', 'local_psaelmsync');
                                        ?> &mdash;
                                    </option>
                                <?php endif; ?>
                                <?php foreach ($discoveredfields as $apifield) : ?>
                                    <option value="<?php echo s($apifield); ?>"
                                        <?php if ($apifield === $currentval) : ?>
                                            selected
                                        <?php endif; ?>
                                    ><?php echo s($apifield); ?></option>
                                <?php endforeach; ?>
                                <?php
                                // If current value is not in discovered fields, add it.
                                if (
                                    !empty($currentval)
                                    && !in_array($currentval, $discoveredfields)
                                ) : ?>
                                    <option value="<?php echo s($currentval); ?>" selected>
                                        <?php echo s($currentval); ?> (current)
                                    </option>
                                <?php endif; ?>
                            </select>
                            <?php else : ?>
                            <input type="text"
                                   id="<?php echo $fieldid; ?>"
                                   name="intake[<?php echo s($canonical); ?>]"
                                   value="<?php echo s($currentval); ?>"
                                   class="form-control"
                                   <?php echo $isrequired ? 'required' : ''; ?>>
                            <?php endif; ?>
                        </td>
                        <td class="text-center align-middle">
                            <?php if ($isrequired) : ?>
                                <span class="badge bg-danger" aria-label="Required">
                                    <?php echo get_string(
                                        'mapping_required',
                                        'local_psaelmsync'
                                    ); ?></span>
                            <?php else : ?>
                                <span class="text-muted"><?php
                                                         echo get_string('mapping_optional', 'local_psaelmsync');
                                ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Completion Mapping -->
    <section class="card mb-4">
        <div class="card-header">
            <h3 class="card-title h5 mb-0">
                <?php echo get_string('mapping_completion_heading', 'local_psaelmsync'); ?>
            </h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                <?php echo get_string('mapping_completion_desc', 'local_psaelmsync'); ?>
            </p>

            <h4 class="h6 mt-3">
                <?php echo get_string('mapping_completion_extra', 'local_psaelmsync'); ?>
            </h4>
            <table class="table table-striped">
                <caption class="sr-only">
                    <?php echo get_string('mapping_completion_heading', 'local_psaelmsync'); ?>
                    &mdash;
                    <?php echo get_string('mapping_completion_extra', 'local_psaelmsync'); ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col"><?php
                            echo get_string('mapping_col_concept', 'local_psaelmsync');
                        ?></th>
                        <th scope="col"><?php
                            echo get_string('mapping_col_apifield', 'local_psaelmsync');
                        ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($completionextrafields as $canonical => $def) :
                    $currentval = $completionmap[$canonical] ?? $def['default'];
                    $fieldid = 'completion_' . $canonical;
                ?>
                    <tr>
                        <td>
                            <label for="<?php echo $fieldid; ?>">
                                <?php echo get_string($def['label'], 'local_psaelmsync'); ?>
                            </label>
                        </td>
                        <td>
                            <input type="text"
                                   id="<?php echo $fieldid; ?>"
                                   name="completion[<?php echo s($canonical); ?>]"
                                   value="<?php echo s($currentval); ?>"
                                   class="form-control"
                                   required>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h4 class="h6 mt-3">
                <?php echo get_string('mapping_completion_shared', 'local_psaelmsync'); ?>
            </h4>
            <p class="text-muted small">
                <?php echo get_string('mapping_completion_shared_desc', 'local_psaelmsync'); ?>
            </p>
            <table class="table table-striped">
                <caption class="sr-only">
                    <?php echo get_string('mapping_completion_heading', 'local_psaelmsync'); ?>
                    &mdash;
                    <?php echo get_string('mapping_completion_shared', 'local_psaelmsync'); ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col"><?php
                            echo get_string('mapping_col_concept', 'local_psaelmsync');
                        ?></th>
                        <th scope="col"><?php
                            echo get_string('mapping_col_apifield', 'local_psaelmsync');
                        ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($intakefields as $canonical => $def) :
                    $currentval = $completionmap[$canonical] ?? $def['default'];
                    $fieldid = 'completion_shared_' . $canonical;
                ?>
                    <tr>
                        <td>
                            <label for="<?php echo $fieldid; ?>">
                                <?php echo get_string($def['label'], 'local_psaelmsync'); ?>
                            </label>
                        </td>
                        <td>
                            <input type="text"
                                   id="<?php echo $fieldid; ?>"
                                   name="completion[<?php echo s($canonical); ?>]"
                                   value="<?php echo s($currentval); ?>"
                                   class="form-control">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Action buttons -->
    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary">
            <?php echo get_string('mapping_save', 'local_psaelmsync'); ?>
        </button>
        <button type="submit" name="action" value="reset"
                class="btn btn-outline-secondary"
                formnovalidate>
            <?php echo get_string('mapping_reset', 'local_psaelmsync'); ?>
        </button>
    </div>
</form>

<style>
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>

<?php
echo $OUTPUT->footer();
