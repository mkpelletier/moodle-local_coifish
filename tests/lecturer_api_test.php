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

namespace local_coifish;

/**
 * Tests for the lecturer API.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coifish\lecturer_api
 */
final class lecturer_api_test extends \advanced_testcase {
    /**
     * Test that get_lecturer_profile returns empty when no profile exists.
     */
    public function test_no_profile(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $result = lecturer_api::get_lecturer_profile($user->id);
        $this->assertEmpty($result);
    }

    /**
     * Test that get_lecturer_profile returns formatted data.
     */
    public function test_profile_with_data(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_coifish_lecturer', (object)[
            'userid' => $user->id,
            'coursecount' => 4,
            'avgfeedbackquality' => 72,
            'avgcoverage' => 80,
            'avgdepth' => 65,
            'avgpersonalisation' => 70,
            'avgturnarounddays' => 3.5,
            'totalinterventions' => 10,
            'interventionsimproved' => 6,
            'interventioneffectiveness' => 60,
            'avgforumpostspw' => 2.5,
            'avgstudentgrade' => 68.2,
            'studentgradetrend' => 'improving',
            'hours_marking' => 15.5,
            'hours_communication' => 3.2,
            'hours_livesessions' => 8.0,
            'hours_total' => 26.7,
            'strengths' => json_encode(['feedback_quality', 'feedback_coverage']),
            'focusareas' => json_encode(['forum_engagement']),
            'timemodified' => time(),
        ]);

        $result = lecturer_api::get_lecturer_profile($user->id);
        $this->assertTrue($result['hasprofile']);
        $this->assertEquals(4, $result['coursecount']);
        $this->assertEquals(72, $result['avgfeedbackquality']);
        $this->assertEquals(3.5, $result['avgturnarounddays']);
        $this->assertEquals(60, $result['interventioneffectiveness']);
        $this->assertEquals(15.5, $result['hours_marking']);
        $this->assertTrue($result['hashours']);
        $this->assertTrue($result['hasstrengths']);
        $this->assertTrue($result['hasfocusareas']);
    }

    /**
     * Test get_all_lecturer_profiles returns all profiles when unfiltered.
     */
    public function test_all_profiles_unfiltered(): void {
        global $DB;
        $this->resetAfterTest();

        $teacher1 = $this->getDataGenerator()->create_user();
        $teacher2 = $this->getDataGenerator()->create_user();

        foreach ([$teacher1->id, $teacher2->id] as $uid) {
            $DB->insert_record('local_coifish_lecturer', (object)[
                'userid' => $uid,
                'coursecount' => 2,
                'avgfeedbackquality' => 50,
                'avgturnarounddays' => 5.0,
                'totalinterventions' => 0,
                'interventionsimproved' => 0,
                'avgstudentgrade' => 60.0,
                'studentgradetrend' => 'stable',
                'hours_marking' => 0,
                'hours_communication' => 0,
                'hours_livesessions' => 0,
                'hours_total' => 0,
                'strengths' => '[]',
                'focusareas' => '[]',
                'timemodified' => time(),
            ]);
        }

        $result = lecturer_api::get_all_lecturer_profiles();
        $this->assertCount(2, $result);
    }

    /**
     * Test get_all_lecturer_profiles with explicit user ID filter.
     */
    public function test_all_profiles_filtered_by_userids(): void {
        global $DB;
        $this->resetAfterTest();

        $teacher1 = $this->getDataGenerator()->create_user();
        $teacher2 = $this->getDataGenerator()->create_user();
        $teacher3 = $this->getDataGenerator()->create_user();

        foreach ([$teacher1->id, $teacher2->id, $teacher3->id] as $uid) {
            $DB->insert_record('local_coifish_lecturer', (object)[
                'userid' => $uid,
                'coursecount' => 1,
                'totalinterventions' => 0,
                'interventionsimproved' => 0,
                'studentgradetrend' => 'unknown',
                'hours_marking' => 0,
                'hours_communication' => 0,
                'hours_livesessions' => 0,
                'hours_total' => 0,
                'strengths' => '[]',
                'focusareas' => '[]',
                'timemodified' => time(),
            ]);
        }

        // Filter to only two teachers.
        $result = lecturer_api::get_all_lecturer_profiles(0, [$teacher1->id, $teacher3->id]);
        $this->assertCount(2, $result);
        $ids = array_map('intval', array_column($result, 'userid'));
        $this->assertContains((int)$teacher1->id, $ids);
        $this->assertContains((int)$teacher3->id, $ids);
        $this->assertNotContains((int)$teacher2->id, $ids);
    }

    /**
     * Test self-view flag is set correctly.
     */
    public function test_self_view_flag(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_coifish_lecturer', (object)[
            'userid' => $user->id,
            'coursecount' => 1,
            'totalinterventions' => 0,
            'interventionsimproved' => 0,
            'studentgradetrend' => 'unknown',
            'hours_marking' => 0,
            'hours_communication' => 0,
            'hours_livesessions' => 0,
            'hours_total' => 0,
            'strengths' => '[]',
            'focusareas' => '[]',
            'timemodified' => time(),
        ]);

        $selfresult = lecturer_api::get_lecturer_profile($user->id, true);
        $otherresult = lecturer_api::get_lecturer_profile($user->id, false);
        $this->assertTrue($selfresult['isself']);
        $this->assertFalse($otherresult['isself']);
    }

    /**
     * Insert a minimal course snapshot with the given final grade.
     *
     * @param int $userid
     * @param int $courseid
     * @param float $grade
     */
    private function insert_snapshot(int $userid, int $courseid, float $grade): void {
        global $DB;
        $DB->insert_record('local_coifish_course_snapshot', (object)[
            'userid' => $userid,
            'courseid' => $courseid,
            'finalgrade' => $grade,
            'engagement' => 50,
            'social' => 50,
            'selfregulation' => 50,
            'feedbackpct' => 50,
            'cognitiveengagement' => 50,
            'interventioncount' => 0,
            'interventionsimproved' => 0,
            'courseenddate' => time() - DAYSECS,
            'timecreated' => time(),
        ]);
    }

    /**
     * Student-outcome stats must reflect only the students allocated to each
     * lecturer by group, not the whole course cohort.
     */
    public function test_student_outcomes_scoped_by_group(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        $course = $gen->create_course();

        $teachera = $gen->create_user();
        $teacherb = $gen->create_user();
        $gen->enrol_user($teachera->id, $course->id, 'editingteacher');
        $gen->enrol_user($teacherb->id, $course->id, 'editingteacher');

        $groupa = $gen->create_group(['courseid' => $course->id]);
        $groupb = $gen->create_group(['courseid' => $course->id]);
        groups_add_member($groupa, $teachera);
        groups_add_member($groupb, $teacherb);

        // Group A students score high (80, 90 -> 85); group B low (40, 50 -> 45).
        foreach ([80, 90] as $g) {
            $s = $gen->create_user();
            $gen->enrol_user($s->id, $course->id, 'student');
            groups_add_member($groupa, $s);
            $this->insert_snapshot($s->id, $course->id, $g);
        }
        foreach ([40, 50] as $g) {
            $s = $gen->create_user();
            $gen->enrol_user($s->id, $course->id, 'student');
            groups_add_member($groupb, $s);
            $this->insert_snapshot($s->id, $course->id, $g);
        }

        lecturer_api::build_lecturer_profiles(time());

        $gradea = (float)$DB->get_field('local_coifish_lecturer', 'avgstudentgrade', ['userid' => $teachera->id]);
        $gradeb = (float)$DB->get_field('local_coifish_lecturer', 'avgstudentgrade', ['userid' => $teacherb->id]);

        // Each lecturer sees only their own group, not the course-wide 65 average.
        $this->assertEqualsWithDelta(85.0, $gradea, 0.01);
        $this->assertEqualsWithDelta(45.0, $gradeb, 0.01);
        $this->assertNotEquals($gradea, $gradeb);
    }

    /**
     * A student the lecturer graded counts even without shared group membership
     * (the grader fallback).
     */
    public function test_student_outcomes_grader_fallback(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');

        // No groups at all. The student is "allocated" only via being graded.
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $this->insert_snapshot($student->id, $course->id, 73);

        // A second student the teacher did NOT grade and isn't grouped with -> excluded.
        $other = $gen->create_user();
        $gen->enrol_user($other->id, $course->id, 'student');
        $this->insert_snapshot($other->id, $course->id, 20);

        // Record a grade by the teacher for $student.
        $assign = $gen->create_module('assign', ['course' => $course->id]);
        $DB->insert_record('assign_grades', (object)[
            'assignment' => $assign->id,
            'userid' => $student->id,
            'grader' => $teacher->id,
            'grade' => 60.0,
            'timecreated' => time(),
            'timemodified' => time(),
            'attemptnumber' => 0,
        ]);

        lecturer_api::build_lecturer_profiles(time());

        $grade = (float)$DB->get_field('local_coifish_lecturer', 'avgstudentgrade', ['userid' => $teacher->id]);
        // Only the graded student (73) counts, not the ungraded/ungrouped one (20).
        $this->assertEqualsWithDelta(73.0, $grade, 0.01);
    }

    /**
     * Excluded courses must drop out of the lecturer's course count and stats.
     */
    public function test_excluded_courses_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        $real = $gen->create_course();
        $adminonly = $gen->create_course();
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $real->id, 'editingteacher');
        $gen->enrol_user($teacher->id, $adminonly->id, 'editingteacher');

        lecturer_api::build_lecturer_profiles(time());
        $this->assertEquals(2, (int)$DB->get_field('local_coifish_lecturer', 'coursecount', ['userid' => $teacher->id]));

        // Exclude the administrative course and rebuild.
        set_config('lecturer_excluded_courses', (string)$adminonly->id, 'local_coifish');
        lecturer_api::build_lecturer_profiles(time());
        $this->assertEquals(1, (int)$DB->get_field('local_coifish_lecturer', 'coursecount', ['userid' => $teacher->id]));

        // A lecturer who ONLY teaches the excluded course loses their profile entirely.
        $adminteacher = $gen->create_user();
        $gen->enrol_user($adminteacher->id, $adminonly->id, 'editingteacher');
        lecturer_api::build_lecturer_profiles(time());
        $this->assertFalse($DB->record_exists('local_coifish_lecturer', ['userid' => $adminteacher->id]));
    }

    /**
     * Create a graded assignment submission for turnaround tests.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $teacher Grader user.
     * @param \stdClass $student Student user.
     * @param int $submittedts Submission timestamp (asub.timemodified).
     * @param int $gradecreatedts First-grade timestamp (ag.timecreated).
     * @param int $grademodifiedts Last-grade timestamp (ag.timemodified).
     * @return \stdClass The assign module record (->cmid, ->id = instance).
     */
    private function make_graded_submission(
        \stdClass $course,
        \stdClass $teacher,
        \stdClass $student,
        int $submittedts,
        int $gradecreatedts,
        int $grademodifiedts
    ): \stdClass {
        global $DB;
        $gen = $this->getDataGenerator();
        $assign = $gen->create_module('assign', ['course' => $course->id]);
        $DB->insert_record('assign_submission', (object)[
            'assignment' => $assign->id,
            'userid' => $student->id,
            'status' => 'submitted',
            'timecreated' => $submittedts,
            'timemodified' => $submittedts,
            'attemptnumber' => 0,
            'latest' => 1,
        ]);
        $DB->insert_record('assign_grades', (object)[
            'assignment' => $assign->id,
            'userid' => $student->id,
            'grader' => $teacher->id,
            'grade' => 75.0,
            'timecreated' => $gradecreatedts,
            'timemodified' => $grademodifiedts,
            'attemptnumber' => 0,
        ]);
        return $assign;
    }

    /**
     * Turnaround must clock from the FIRST grade (timecreated), so a late grade
     * edit (timemodified far in the future) does not inflate it.
     */
    public function test_turnaround_uses_timecreated_not_timemodified(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $student = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $gen->enrol_user($student->id, $course->id, 'student');

        $t0 = time() - 60 * DAYSECS;
        // First grade 2 days after submission; a correction 30 days later.
        $this->make_graded_submission(
            $course,
            $teacher,
            $student,
            $t0,
            $t0 + 2 * DAYSECS,
            $t0 + 30 * DAYSECS
        );

        $profile = lecturer_api::compute_lecturer_profile_for_range($teacher->id, true, 0, time());
        // Should reflect ~2 days (first grade), not ~30 days (last edit).
        $this->assertEqualsWithDelta(2.0, (float)$profile['avgturnarounddays'], 0.2);
    }

    /**
     * An integrity referral pauses the clock at referral time, so turnaround
     * reflects the referral instant rather than the (later) grading instant.
     */
    public function test_turnaround_paused_by_referral(): void {
        global $DB;
        $this->resetAfterTest();

        if (!$DB->get_manager()->table_exists('local_unifiedgrader_referral')) {
            $this->markTestSkipped('local_unifiedgrader_referral table not present.');
        }

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $student = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $gen->enrol_user($student->id, $course->id, 'student');

        $t0 = time() - 60 * DAYSECS;
        // First grade 10 days after submission, but a referral was raised at day 3.
        $assign = $this->make_graded_submission(
            $course,
            $teacher,
            $student,
            $t0,
            $t0 + 10 * DAYSECS,
            $t0 + 10 * DAYSECS
        );
        $DB->insert_record('local_unifiedgrader_referral', (object)[
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'authorid' => $teacher->id,
            'reason' => 'similarity',
            'note' => '',
            'status' => 'open',
            'outcome' => '',
            'timereferred' => $t0 + 3 * DAYSECS,
            'timeresolved' => 0,
            'timemodified' => $t0 + 3 * DAYSECS,
        ]);

        $profile = lecturer_api::compute_lecturer_profile_for_range($teacher->id, true, 0, time());
        // Clock stops at the referral (~3 days), not the grade (~10 days).
        $this->assertEqualsWithDelta(3.0, (float)$profile['avgturnarounddays'], 0.2);
    }

    /**
     * The drill-down must count held (referred) items and report the adjusted average.
     */
    public function test_turnaround_breakdown_counts_held(): void {
        global $DB;
        $this->resetAfterTest();

        if (!$DB->get_manager()->table_exists('local_unifiedgrader_referral')) {
            $this->markTestSkipped('local_unifiedgrader_referral table not present.');
        }

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $student = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $gen->enrol_user($student->id, $course->id, 'student');

        $t0 = time() - 60 * DAYSECS;
        $assign = $this->make_graded_submission(
            $course,
            $teacher,
            $student,
            $t0,
            $t0 + 10 * DAYSECS,
            $t0 + 10 * DAYSECS
        );
        $DB->insert_record('local_unifiedgrader_referral', (object)[
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'authorid' => $teacher->id,
            'reason' => 'similarity',
            'note' => '',
            'status' => 'open',
            'outcome' => '',
            'timereferred' => $t0 + 3 * DAYSECS,
            'timeresolved' => 0,
            'timemodified' => $t0 + 3 * DAYSECS,
        ]);

        $rows = lecturer_api::get_turnaround_breakdown($teacher->id, [$course->id]);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertEquals($assign->id, $DB->get_field('course_modules', 'instance', ['id' => $row['cmid']]));
        $this->assertEquals(1, $row['ngraded']);
        $this->assertEquals(1, $row['nheld']);
        // Raw uses first-grade (~10 days); adjusted is paused at referral (~3 days).
        $this->assertEqualsWithDelta(10.0, $row['rawavgdays'], 0.2);
        $this->assertEqualsWithDelta(3.0, $row['adjavgdays'], 0.2);
    }

    /**
     * The feedback breakdown lists one row per course with graded work, worst
     * composite first, and excludes courses with totalgraded = 0.
     */
    public function test_feedback_breakdown_excludes_ungraded_and_orders_worst_first(): void {
        global $DB;
        $this->resetAfterTest();

        if (!$DB->get_manager()->table_exists('gradereport_coifish_feedback')) {
            $this->markTestSkipped('gradereport_coifish_feedback table not present.');
        }

        $gen = $this->getDataGenerator();
        $teacher = $gen->create_user();
        $good = $gen->create_course();
        $poor = $gen->create_course();
        $empty = $gen->create_course();

        // A high-composite course, a low-composite course, and one with no graded work.
        $row = function (int $courseid, int $composite, int $totalgraded) use ($teacher) {
            return (object)[
                'courseid' => $courseid,
                'userid' => $teacher->id,
                'coverage' => 60,
                'depth' => 55,
                'personalisation' => 70,
                'structured' => 50,
                'composite' => $composite,
                'totalgraded' => $totalgraded,
                'withfeedback' => $totalgraded,
                'avgwords' => 40,
                'uniquepct' => 80,
                'qualityscore' => $composite,
                'timemodified' => time(),
            ];
        };
        $DB->insert_record('gradereport_coifish_feedback', $row($good->id, 80, 5));
        $DB->insert_record('gradereport_coifish_feedback', $row($poor->id, 30, 4));
        $DB->insert_record('gradereport_coifish_feedback', $row($empty->id, 90, 0));

        $rows = lecturer_api::get_feedback_breakdown(
            $teacher->id,
            [$good->id, $poor->id, $empty->id]
        );

        // The ungraded course is excluded; the remaining two are worst-first.
        $this->assertCount(2, $rows);
        $this->assertEquals($poor->id, $rows[0]['courseid']);
        $this->assertEquals(30, $rows[0]['composite']);
        $this->assertEquals($good->id, $rows[1]['courseid']);
        $this->assertEquals(5, $rows[1]['ngraded']);

        // Empty course list short-circuits.
        $this->assertSame([], lecturer_api::get_feedback_breakdown($teacher->id, []));
    }

    /**
     * The course_category setting must limit lecturer profiles to courses within
     * that category tree; courses in other categories (e.g. dev/test) drop out.
     */
    public function test_category_scope_limits_courses(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        $cata = $gen->create_category();
        $catb = $gen->create_category();
        $coursea = $gen->create_course(['category' => $cata->id]);
        $courseb = $gen->create_course(['category' => $catb->id]);
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $coursea->id, 'editingteacher');
        $gen->enrol_user($teacher->id, $courseb->id, 'editingteacher');

        // No category limit: both courses count.
        lecturer_api::build_lecturer_profiles(time());
        $this->assertEquals(2, (int)$DB->get_field('local_coifish_lecturer', 'coursecount', ['userid' => $teacher->id]));

        // Limit to category A: only the category-A course counts.
        set_config('course_category', $cata->id, 'local_coifish');
        lecturer_api::build_lecturer_profiles(time());
        $this->assertEquals(1, (int)$DB->get_field('local_coifish_lecturer', 'coursecount', ['userid' => $teacher->id]));

        // A lecturer who only teaches outside the category loses their profile.
        $devteacher = $gen->create_user();
        $gen->enrol_user($devteacher->id, $courseb->id, 'editingteacher');
        lecturer_api::build_lecturer_profiles(time());
        $this->assertFalse($DB->record_exists('local_coifish_lecturer', ['userid' => $devteacher->id]));
    }
}
