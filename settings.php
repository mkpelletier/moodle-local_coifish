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
 * Admin settings for the CoIFish longitudinal profile plugin.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Report links under Site administration > Reports.
// These must be outside $hassiteconfig so non-admin coordinators can see them.
$ADMIN->add('reports', new admin_externalpage(
    'local_coifish_riskoverview',
    get_string('risk_overview_title', 'local_coifish'),
    new moodle_url('/local/coifish/index.php'),
    ['local/coifish:viewfullprofile', 'local/coifish:viewlecturerprofile']
));
$ADMIN->add('reports', new admin_externalpage(
    'local_coifish_lecturerprofiles',
    get_string('lecturer_profiles_title', 'local_coifish'),
    new moodle_url('/local/coifish/lecturerprofile.php'),
    ['local/coifish:viewfullprofile', 'local/coifish:viewlecturerprofile']
));

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_coifish', get_string('pluginname', 'local_coifish'));
    $ADMIN->add('localplugins', $settings);

    // Profile building heading.
    $settings->add(new admin_setting_heading(
        'local_coifish/profileheading',
        get_string('setting_profile_heading', 'local_coifish'),
        get_string('setting_profile_heading_desc', 'local_coifish')
    ));

    // Organisation mode.
    $settings->add(new admin_setting_configselect(
        'local_coifish/organisation_mode',
        get_string('setting_organisation_mode', 'local_coifish'),
        get_string('setting_organisation_mode_desc', 'local_coifish'),
        'category',
        [
            'category' => get_string('organisation_mode_category', 'local_coifish'),
            'cohort' => get_string('organisation_mode_cohort', 'local_coifish'),
        ]
    ));

    // Limit course matching to a category.
    $catlist = \core_course_category::make_categories_list();
    $catoptions = [0 => get_string('setting_course_category_all', 'local_coifish')];
    foreach ($catlist as $id => $name) {
        $catoptions[$id] = $name;
    }
    $settings->add(new admin_setting_configselect(
        'local_coifish/course_category',
        get_string('setting_course_category', 'local_coifish'),
        get_string('setting_course_category_desc', 'local_coifish'),
        0,
        $catoptions
    ));

    // Courses to exclude entirely from lecturer profiles (administrative /
    // non-teaching courses). One numeric course ID per line.
    $settings->add(new admin_setting_configtextarea(
        'local_coifish/lecturer_excluded_courses',
        get_string('setting_lecturer_excluded_courses', 'local_coifish'),
        get_string('setting_lecturer_excluded_courses_desc', 'local_coifish'),
        '',
        PARAM_RAW
    ));

    // Programme cohort mapping with course shortname patterns.
    require_once($CFG->dirroot . '/local/coifish/classes/admin_setting_cohortpatterns.php');
    $settings->add(new local_coifish_admin_setting_cohortpatterns(
        'local_coifish/cohort_programmes',
        get_string('setting_cohort_programmes', 'local_coifish'),
        get_string('setting_cohort_programmes_desc', 'local_coifish')
    ));

    // Teaching roles selection.
    $allroles = $DB->get_records('role', null, 'sortorder ASC', 'id, shortname, name');
    $rolechoices = [];
    foreach ($allroles as $role) {
        $rolename = $role->name ?: $role->shortname;
        $rolechoices[$role->id] = $rolename . ' (' . $role->shortname . ')';
    }
    // Default to standard teacher and editingteacher if they exist.
    $defaults = [];
    foreach ($allroles as $role) {
        if (in_array($role->shortname, ['teacher', 'editingteacher'])) {
            $defaults[$role->id] = 1;
        }
    }
    $settings->add(new admin_setting_configmulticheckbox(
        'local_coifish/teaching_roles',
        get_string('setting_teaching_roles', 'local_coifish'),
        get_string('setting_teaching_roles_desc', 'local_coifish'),
        $defaults,
        $rolechoices
    ));

    // Enable profile building.
    $settings->add(new admin_setting_configcheckbox(
        'local_coifish/profile_enabled',
        get_string('setting_profile_enabled', 'local_coifish'),
        get_string('setting_profile_enabled_desc', 'local_coifish'),
        1
    ));

    // Minimum completed courses before building a profile.
    $settings->add(new admin_setting_configtext(
        'local_coifish/min_courses',
        get_string('setting_min_courses', 'local_coifish'),
        get_string('setting_min_courses_desc', 'local_coifish'),
        1,
        PARAM_INT
    ));

    // How far back the historical lecturer-trend backfill should go.
    $settings->add(new admin_setting_configtext(
        'local_coifish/lecturer_backfill_years',
        get_string('setting_lecturer_backfill_years', 'local_coifish'),
        get_string('setting_lecturer_backfill_years_desc', 'local_coifish'),
        3,
        PARAM_INT
    ));

    // Term label source for the Current Enrolments table.
    $settings->add(new admin_setting_configselect(
        'local_coifish/term_source',
        get_string('setting_term_source', 'local_coifish'),
        get_string('setting_term_source_desc', 'local_coifish'),
        'category',
        [
            'category' => get_string('term_source_category', 'local_coifish'),
            'fullname' => get_string('term_source_fullname', 'local_coifish'),
            'customfield' => get_string('term_source_customfield', 'local_coifish'),
        ]
    ));

    // Optional customfield shortname used when term_source = customfield.
    $settings->add(new admin_setting_configtext(
        'local_coifish/term_customfield_shortname',
        get_string('setting_term_customfield_shortname', 'local_coifish'),
        get_string('setting_term_customfield_shortname_desc', 'local_coifish'),
        '',
        PARAM_ALPHANUMEXT
    ));

    // Live session preparation multiplier.
    $settings->add(new admin_setting_configselect(
        'local_coifish/prep_multiplier',
        get_string('setting_prep_multiplier', 'local_coifish'),
        get_string('setting_prep_multiplier_desc', 'local_coifish'),
        '2',
        [
            '0' => get_string('prep_multiplier_none', 'local_coifish'),
            '1' => get_string('prep_multiplier_1', 'local_coifish'),
            '2' => get_string('prep_multiplier_2', 'local_coifish'),
            '3' => get_string('prep_multiplier_3', 'local_coifish'),
        ]
    ));

    // Privacy heading.
    $settings->add(new admin_setting_heading(
        'local_coifish/privacyheading',
        get_string('setting_privacy_heading', 'local_coifish'),
        get_string('setting_privacy_heading_desc', 'local_coifish')
    ));

    // Detail level exposed to course teachers.
    $settings->add(new admin_setting_configselect(
        'local_coifish/teacher_detail_level',
        get_string('setting_teacher_detail', 'local_coifish'),
        get_string('setting_teacher_detail_desc', 'local_coifish'),
        'patterns',
        [
            'patterns' => get_string('detail_level_patterns', 'local_coifish'),
            'summary' => get_string('detail_level_summary', 'local_coifish'),
            'full' => get_string('detail_level_full', 'local_coifish'),
        ]
    ));

    // API access heading.
    $settings->add(new admin_setting_heading(
        'local_coifish/apiheading',
        get_string('setting_api_heading', 'local_coifish'),
        get_string('setting_api_heading_desc', 'local_coifish')
    ));

    // Enable external API (for SIS integration).
    $settings->add(new admin_setting_configcheckbox(
        'local_coifish/api_enabled',
        get_string('setting_api_enabled', 'local_coifish'),
        get_string('setting_api_enabled_desc', 'local_coifish'),
        0
    ));
}
