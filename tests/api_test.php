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
 * Tests for the longitudinal profile API.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coifish\api
 */
final class api_test extends \advanced_testcase {
    /**
     * Test that get_student_profile returns empty when no profile exists.
     */
    public function test_get_student_profile_no_data(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $result = api::get_student_profile($user->id);
        $this->assertEmpty($result);
    }

    /**
     * Test that get_student_profile returns data when a profile exists.
     */
    public function test_get_student_profile_with_data(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setAdminUser();

        $DB->insert_record('local_coifish_profile', (object)[
            'userid' => $user->id,
            'coursescompleted' => 3,
            'coursesinprogress' => 1,
            'avggrade' => 65.5,
            'gradetrend' => 'improving',
            'engagementpattern' => 'consistent',
            'avgsocial' => 45,
            'socialtrend' => 'stable',
            'avgselfregulation' => 60,
            'selfregtrend' => 'improving',
            'avgfeedbackpct' => 70,
            'totalinterventions' => 2,
            'interventionsimproved' => 1,
            'interventionresponse' => 'mixed',
            'risklevel' => 'moderate',
            'riskfactors' => json_encode(['social_isolation']),
            'timemodified' => time(),
        ]);

        $result = api::get_student_profile($user->id, 0);
        $this->assertTrue($result['hasprofile']);
        $this->assertEquals(3, $result['coursescompleted']);
        $this->assertEquals('moderate', $result['risklevel']);
        $this->assertTrue($result['isriskmoderate']);
        $this->assertFalse($result['isriskhigh']);
        $this->assertTrue($result['hasriskfactors']);
        $this->assertEquals('consistent', $result['engagementpattern']);
    }

    /**
     * Test that get_student_profile respects privacy levels.
     */
    public function test_privacy_level_patterns(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('teacher_detail_level', 'patterns', 'local_coifish');

        $student = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $DB->insert_record('local_coifish_profile', (object)[
            'userid' => $student->id,
            'coursescompleted' => 2,
            'coursesinprogress' => 0,
            'avggrade' => 72.0,
            'gradetrend' => 'stable',
            'engagementpattern' => 'declining',
            'avgsocial' => 30,
            'socialtrend' => 'declining',
            'avgselfregulation' => 40,
            'selfregtrend' => 'stable',
            'avgfeedbackpct' => 50,
            'totalinterventions' => 0,
            'interventionsimproved' => 0,
            'interventionresponse' => 'none',
            'risklevel' => 'low',
            'riskfactors' => '[]',
            'timemodified' => time(),
        ]);

        $this->setUser($teacher);
        $result = api::get_student_profile($student->id, $course->id);

        // Patterns level should have risk and engagement but not grades.
        $this->assertTrue($result['hasprofile']);
        $this->assertEquals('declining', $result['engagementpattern']);
        $this->assertArrayNotHasKey('avggrade', $result);
        $this->assertArrayNotHasKey('gradetrend', $result);
    }

    /**
     * Test that get_student_profile returns empty when disabled.
     */
    public function test_profile_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('profile_enabled', '0', 'local_coifish');

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_coifish_profile', (object)[
            'userid' => $user->id,
            'coursescompleted' => 1,
            'coursesinprogress' => 0,
            'avggrade' => 50.0,
            'gradetrend' => 'unknown',
            'engagementpattern' => 'unknown',
            'avgsocial' => null,
            'socialtrend' => 'unknown',
            'avgselfregulation' => null,
            'selfregtrend' => 'unknown',
            'avgfeedbackpct' => null,
            'totalinterventions' => 0,
            'interventionsimproved' => 0,
            'interventionresponse' => 'none',
            'risklevel' => 'low',
            'riskfactors' => '[]',
            'timemodified' => time(),
        ]);

        $result = api::get_student_profile($user->id);
        $this->assertEmpty($result);
    }

    /**
     * Test get_risk_overview returns only at-risk students.
     */
    public function test_risk_overview(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a low-risk and a high-risk student.
        $lowrisk = $this->getDataGenerator()->create_user();
        $highrisk = $this->getDataGenerator()->create_user();

        $testcases = [
            [$lowrisk->id, 'low', 80.0],
            [$highrisk->id, 'high', 25.0],
        ];
        foreach ($testcases as [$uid, $level, $grade]) {
            $DB->insert_record('local_coifish_profile', (object)[
                'userid' => $uid,
                'coursescompleted' => 2,
                'coursesinprogress' => 0,
                'avggrade' => $grade,
                'gradetrend' => 'stable',
                'engagementpattern' => 'consistent',
                'avgsocial' => 50,
                'socialtrend' => 'stable',
                'avgselfregulation' => 50,
                'selfregtrend' => 'stable',
                'avgfeedbackpct' => 50,
                'totalinterventions' => 0,
                'interventionsimproved' => 0,
                'interventionresponse' => 'none',
                'risklevel' => $level,
                'riskfactors' => '[]',
                'timemodified' => time(),
            ]);
        }

        $result = api::get_risk_overview(0, 'all');
        $this->assertCount(1, $result);
        $this->assertEquals($highrisk->id, $result[0]['userid']);
    }

    /**
     * Test get_course_history returns ordered snapshots.
     */
    public function test_course_history(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course(['shortname' => 'HIST1']);
        $course2 = $this->getDataGenerator()->create_course(['shortname' => 'HIST2']);

        $DB->insert_record('local_coifish_course_snapshot', (object)[
            'userid' => $user->id,
            'courseid' => $course1->id,
            'finalgrade' => 65.0,
            'engagement' => 50,
            'social' => 30,
            'selfregulation' => 40,
            'feedbackpct' => 60,
            'cognitiveengagement' => 50,
            'interventioncount' => 0,
            'interventionsimproved' => 0,
            'courseenddate' => time() - 90 * 86400,
            'timecreated' => time(),
        ]);
        $DB->insert_record('local_coifish_course_snapshot', (object)[
            'userid' => $user->id,
            'courseid' => $course2->id,
            'finalgrade' => 72.0,
            'engagement' => 60,
            'social' => 40,
            'selfregulation' => 55,
            'feedbackpct' => 70,
            'cognitiveengagement' => 60,
            'interventioncount' => 1,
            'interventionsimproved' => 1,
            'courseenddate' => time() - 30 * 86400,
            'timecreated' => time(),
        ]);

        $result = api::get_course_history($user->id);
        $this->assertCount(2, $result);
        // Should be ordered by courseenddate ASC.
        $this->assertEquals($course1->id, $result[0]['courseid']);
        $this->assertEquals($course2->id, $result[1]['courseid']);
        $this->assertEquals(72.0, $result[1]['finalgrade']);
    }

    /**
     * Test that recommendations are generated for risk factors.
     */
    public function test_recommendations_generated(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_coifish_profile', (object)[
            'userid' => $user->id,
            'coursescompleted' => 3,
            'coursesinprogress' => 1,
            'avggrade' => 40.0,
            'gradetrend' => 'declining',
            'engagementpattern' => 'declining',
            'avgsocial' => 10,
            'socialtrend' => 'declining',
            'avgselfregulation' => 20,
            'selfregtrend' => 'declining',
            'avgfeedbackpct' => 15,
            'totalinterventions' => 3,
            'interventionsimproved' => 0,
            'interventionresponse' => 'unresponsive',
            'risklevel' => 'high',
            'riskfactors' => json_encode([
                'grade_decline', 'engagement_decline',
                'social_isolation', 'feedback_neglect',
                'intervention_unresponsive',
            ]),
            'timemodified' => time(),
        ]);

        $result = api::get_student_profile($user->id, 0);
        $this->assertTrue($result['hasrecommendations']);
        $this->assertNotEmpty($result['recommendations']);
        // Should have recommendations for each risk factor.
        $this->assertGreaterThanOrEqual(4, count($result['recommendations']));
    }

    /**
     * Students who withdrew (suspended enrolment) are not listed as early warnings:
     * it is too late for preventative action and would mislead the teacher.
     */
    public function test_early_warnings_exclude_withdrawn_students(): void {
        global $DB;
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $active = $gen->create_user();
        $withdrawn = $gen->create_user();
        $gen->enrol_user($active->id, $course->id, 'student');
        $gen->enrol_user($withdrawn->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);

        foreach ([$active, $withdrawn] as $user) {
            $DB->insert_record('local_coifish_profile', (object)[
                'userid' => $user->id,
                'coursescompleted' => 2,
                'coursesinprogress' => 1,
                'avggrade' => 40,
                'gradetrend' => 'declining',
                'engagementpattern' => 'irregular',
                'avgsocial' => 10,
                'socialtrend' => 'declining',
                'avgselfregulation' => 20,
                'selfregtrend' => 'declining',
                'avgfeedbackpct' => 10,
                'totalinterventions' => 0,
                'interventionsimproved' => 0,
                'interventionresponse' => 'none',
                'risklevel' => 'high',
                'riskfactors' => json_encode(['declining_grades']),
                'timemodified' => time(),
            ]);
        }

        $userids = array_column(api::get_early_warnings($course->id), 'userid');
        $this->assertContains((int)$active->id, array_map('intval', $userids));
        $this->assertNotContains((int)$withdrawn->id, array_map('intval', $userids));
    }
}
