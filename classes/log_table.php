<?php
namespace local_psaelmsync\output;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

class log_table extends \table_sql {
    public function __construct($uniqueid) {

        parent::__construct($uniqueid);

        $columns = array('status', 'action', 'timestamp', 'course_name', 'person_id', 'elm_enrolment_id', 'user_lastname', 'user_email', 'user_guid', 'record_date_created');
        $this->define_columns($columns);
        $headers = array(
            get_string('status', 'local_psaelmsync'),
            get_string('action', 'local_psaelmsync'),
            get_string('timestamp', 'local_psaelmsync'),
            get_string('course_name', 'local_psaelmsync'),
            get_string('person_id', 'local_psaelmsync'),
            get_string('elm_enrolment_id', 'local_psaelmsync'),
            get_string('user_lastname', 'local_psaelmsync'),
            get_string('user_email', 'local_psaelmsync'),
            get_string('user_guid', 'local_psaelmsync'),
            get_string('record_date_created', 'local_psaelmsync')
        );
        $this->define_headers($headers);
        $this->sortable(true, 'timestamp', SORT_DESC);
        // $this->no_sorting('action');
        // $this->no_sorting('status');
    }

    public function col_status($values) {
        return ucfirst($values->status);
    }
    public function col_action($values) {
        return ucfirst($values->action);
    }
    public function col_user_lastname($values) {
        $name = $values->user_firstname . ' ' . $values->user_lastname;
        $user_url = new \moodle_url('/user/view.php', array('id' => $values->user_id));
        return \html_writer::link($user_url, $name);
    }
    public function col_user_email($values) {
        return $values->user_email;
    }
    public function col_user_guid($values) {
        return $values->user_guid;
    }

    public function col_course_name($values) {
        global $CFG;
        $course_url = new \moodle_url('/user/index.php', array('id' => $values->course_id));
        return \html_writer::link($course_url, $values->course_name);
    }

    public function col_user_details($values) {
        global $CFG;
        $user_url = new \moodle_url('/user/profile.php', array('id' => $values->user_id));
        $user_details = "{$values->user_firstname} {$values->user_lastname}<br>GUID: {$values->user_guid}<br>Email: {$values->user_email}";
        return \html_writer::link($user_url, $user_details);
    }

    public function col_timestamp($values) {
        // if($values->action == 'Imported') {
        //     $c = date('Y-m-d H:i:s' , $values->timestamp) . '<span style="background: red; color: #FFF;" title="This record was (likely) imported using the date that Moodle listed as the enrolment datetime as the original data was not available at time of import.">!!!</span>';
        // } else {
        // }
        $c = date('Y-m-d H:i:s' , $values->timestamp);
        return $c;
    }
}
