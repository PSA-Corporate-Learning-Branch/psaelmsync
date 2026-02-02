<?php

function xmldb_local_psaelmsync_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager(); // Loads the database manager.

    // Upgrade step for adding elm_course_id to local_psaelmsync_logs.
    if ($oldversion < 2024090606) {
        // Define the new field elm_course_id.
        $table = new xmldb_table('local_psaelmsync_logs');
        $field = new xmldb_field('elm_course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'course_id');

        // Conditionally launch the add field statement.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // PSA ELM Sync savepoint reached.
        upgrade_plugin_savepoint(true, 2024090606, 'local', 'psaelmsync');
    }

    // Upgrade step for increasing action field length from 20 to 100.
    if ($oldversion < 2026012702) {
        $table = new xmldb_table('local_psaelmsync_logs');
        $field_action = new xmldb_field('action', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null, 'elm_enrolment_id');
        // Change the field precision.
        $dbman->change_field_precision($table, $field_action);

        $field_oprid = new xmldb_field('oprid', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null, 'user_email');
        $field_personid = new xmldb_field('person_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0, 'user_email');   
        $field_activityid = new xmldb_field('activity_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0, 'user_email');
        // Conditionally launch the add field statements.
        if (!$dbman->field_exists($table, $field_oprid)) {
            $dbman->add_field($table, $field_oprid);
        }
        if (!$dbman->field_exists($table, $field_personid)) {
            $dbman->add_field($table, $field_personid);
        }
        if (!$dbman->field_exists($table, $field_activityid)) {
            $dbman->add_field($table, $field_activityid);
        }

        // PSA ELM Sync savepoint reached.
        upgrade_plugin_savepoint(true, 2026012702, 'local', 'psaelmsync');
    }

    return true;
}
