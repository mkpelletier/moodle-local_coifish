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

use local_coifish\task\build_active_snapshots;
use local_coifish\task\recompute_social_snapshots;

/**
 * Tests for live-session (BigBlueButton) data in longitudinal metrics.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coifish\metrics_helper
 * @covers     \local_coifish\lecturer_api
 * @covers     \local_coifish\task\recompute_social_snapshots
 */
final class live_sessions_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    protected \stdClass $course;

    /** @var \stdClass Teacher. */
    protected \stdClass $teacher;

    /** @var \stdClass[] Students s1..s3. */
    protected array $students = [];

    /** @var \stdClass BBB activity. */
    protected \stdClass $bbb;

    /**
     * Course with a teacher, three students and a BBB activity. s1 and s2 hold a
     * peer role-play (equal voice); the teacher runs one session attended by s1.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \gradereport_coifish\live_sessions::reset_cache();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course([
            'startdate' => time() - 60 * DAYSECS,
            'enddate' => time() - DAYSECS,
        ]);
        $this->teacher = $gen->create_and_enrol($this->course, 'editingteacher');
        for ($i = 1; $i <= 3; $i++) {
            $this->students[$i] = $gen->create_and_enrol($this->course, 'student');
        }
        $this->bbb = $gen->create_module('bigbluebuttonbn', ['course' => $this->course->id]);
    }

    /**
     * Insert a meeting-events Summary row.
     *
     * @param int $userid Attendee.
     * @param string $recordid Sitting id.
     * @param int $time Timestamp.
     * @param int $talk Talk time (s).
     */
    protected function add_summary(int $userid, string $recordid, int $time, int $talk = 0): void {
        global $DB;
        $DB->insert_record('bigbluebuttonbn_logs', [
            'courseid' => $this->course->id,
            'bigbluebuttonbnid' => $this->bbb->id,
            'userid' => $userid,
            'timecreated' => $time,
            'meetingid' => $this->bbb->meetingid . '-' . $this->course->id . '-' . $this->bbb->id . '[0]',
            'log' => 'Summary',
            'meta' => json_encode(['recordid' => $recordid, 'data' => [
                'duration' => 3600,
                'engagement' => ['talk_time' => $talk],
            ]]),
        ]);
    }

    /**
     * Seed the role-play: s1 and s2 with equal voice, 10 days before course end.
     */
    protected function seed_roleplay(): void {
        $t = time() - 10 * DAYSECS;
        $this->add_summary($this->students[1]->id, 'rec-rp', $t, 300);
        $this->add_summary($this->students[2]->id, 'rec-rp', $t, 300);
    }

    /**
     * Social presence blends live interaction with forum participation using
     * gradereport_coifish's definition, and is null only with nothing to measure.
     */
    public function test_capture_social_includes_live(): void {
        $this->seed_roleplay();
        $cid = (int)$this->course->id;

        // No forum discussions at all: the live rate (100) stands alone.
        $this->assertSame(100, metrics_helper::capture_social($cid, (int)$this->students[1]->id, (int)$this->course->enddate));

        // With a forum discussion visible but no posts from s1, the forum part (0)
        // is blended in. Intensity = (2 + 2) / 3 students -> BBB scale clamped to
        // 0.5 -> BBB 10 of forum 50 + BBB 10 + collab 15.
        $gen = $this->getDataGenerator();
        $forum = $gen->create_module('forum', ['course' => $cid]);
        $gen->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $cid, 'forum' => $forum->id, 'userid' => $this->teacher->id,
        ]);
        $social = metrics_helper::capture_social($cid, (int)$this->students[1]->id, (int)$this->course->enddate);
        $this->assertSame((int)round(100 * 10 / 75), $social);

        // Student s3 never used the pair's room and posted nothing: nothing to measure.
        $this->assertNull(metrics_helper::capture_social($cid, (int)$this->students[3]->id));

        $this->assertSame(\gradereport_coifish\report::SOCIAL_METRIC_VERSION, metrics_helper::get_social_version());
    }

    /**
     * The recompute task rewrites only outdated social values and lecturer live fields.
     */
    public function test_recompute_task(): void {
        global $DB;
        set_config('prep_multiplier', 2, 'local_coifish');
        $this->seed_roleplay();
        $sessiontime = time() - 20 * DAYSECS;
        $this->add_summary($this->teacher->id, 'rec-lec', $sessiontime);
        $this->add_summary($this->students[1]->id, 'rec-lec', $sessiontime);

        $snapid = $DB->insert_record('local_coifish_course_snapshot', [
            'userid' => $this->students[1]->id, 'courseid' => $this->course->id, 'finalgrade' => 72.5,
            'engagement' => 40, 'social' => 99, 'socialversion' => 0, 'selfregulation' => 10,
            'feedbackpct' => 50, 'interventioncount' => 0, 'interventionsimproved' => 0,
            'courseenddate' => $this->course->enddate, 'timecreated' => time(),
        ]);
        // The sitting started an hour before its analytics arrived.
        [$wstart, $wend] = lecturer_period_snapshot::week_bounds($sessiontime - 3600);
        $periodid = $DB->insert_record('local_coifish_lecturer_period_snapshot', [
            'userid' => $this->teacher->id, 'periodstart' => $wstart, 'periodend' => $wend, 'coursecount' => 1,
            'hours_marking' => 1.0, 'hours_communication' => 2.0, 'hours_livesessions' => 0, 'hours_total' => 3.0,
            'avgturnarounddays' => 4.5, 'timecomputed' => time(),
        ]);

        (new recompute_social_snapshots())->execute();

        $snap = $DB->get_record('local_coifish_course_snapshot', ['id' => $snapid]);
        $this->assertSame(\gradereport_coifish\report::SOCIAL_METRIC_VERSION, (int)$snap->socialversion);
        $this->assertNotEquals(99, (int)$snap->social);
        $this->assertEqualsWithDelta(72.5, (float)$snap->finalgrade, 0.001);
        $this->assertSame(40, (int)$snap->engagement);

        $period = $DB->get_record('local_coifish_lecturer_period_snapshot', ['id' => $periodid]);
        $this->assertSame(1, (int)$period->livesessions);
        // One hour live, plus 2x preparation.
        $this->assertEqualsWithDelta(3.0, (float)$period->hours_livesessions, 0.01);
        $this->assertEqualsWithDelta(6.0, (float)$period->hours_total, 0.01);
        $this->assertEqualsWithDelta(4.5, (float)$period->avgturnarounddays, 0.01);
    }

    /**
     * Lecturer profiles gain a live-teaching score only where courses held live sessions.
     */
    public function test_lecturer_live_teaching(): void {
        global $DB;
        $this->seed_roleplay();
        $sessiontime = time() - 20 * DAYSECS;
        $this->add_summary($this->teacher->id, 'rec-lec', $sessiontime);
        $this->add_summary($this->students[1]->id, 'rec-lec', $sessiontime);

        // A second lecturer teaching only a course without BBB.
        $gen = $this->getDataGenerator();
        $other = $gen->create_course(['startdate' => time() - 60 * DAYSECS]);
        $plain = $gen->create_and_enrol($other, 'editingteacher');
        $gen->create_and_enrol($other, 'student');

        $live = lecturer_api::get_live_teaching(
            (int)$this->teacher->id,
            [(int)$this->course->id],
            time() - 120 * DAYSECS
        );
        $this->assertSame(1, $live['sessions']);
        // The role-play in the teacher's no-group room is theirs as the only teacher.
        $this->assertSame(1, $live['peersessions']);
        // Students s1 and s2 of three reached.
        $this->assertSame(67, $live['reach']);
        $this->assertNull(lecturer_api::get_live_teaching((int)$plain->id, [(int)$other->id], time() - 120 * DAYSECS));

        lecturer_api::build_lecturer_profiles(time());
        $record = $DB->get_record('local_coifish_lecturer', ['userid' => $this->teacher->id]);
        $this->assertSame(1, (int)$record->livesessions);
        $this->assertSame(67, (int)$record->livereach);
        $this->assertNotNull($record->livescore);
        $plainrecord = $DB->get_record('local_coifish_lecturer', ['userid' => $plain->id]);
        $this->assertNull($plainrecord->livescore);
        $this->assertNotContains('live_teaching', json_decode($plainrecord->strengths, true));
        $this->assertNotContains('live_teaching', json_decode($plainrecord->focusareas, true));
    }

    /**
     * New BBB analytics (which land in BBB's own log table) trigger an active refresh.
     */
    public function test_active_snapshot_refreshes_on_live_activity(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['startdate' => time() - 30 * DAYSECS]);
        $student = $gen->create_and_enrol($course, 'student');
        $bbb = $gen->create_module('bigbluebuttonbn', ['course' => $course->id]);

        ob_start();
        (new build_active_snapshots())->execute();
        ob_end_clean();
        $snap = $DB->get_record('local_coifish_active_snapshot', ['courseid' => $course->id, 'userid' => $student->id]);
        $this->assertSame(metrics_helper::get_social_version(), (int)$snap->socialversion);

        // Stamp it in the future so nothing in the logstore is newer.
        $future = time() + 100000;
        $DB->set_field('local_coifish_active_snapshot', 'timecomputed', $future, ['id' => $snap->id]);
        $DB->insert_record('bigbluebuttonbn_logs', [
            'courseid' => $course->id, 'bigbluebuttonbnid' => $bbb->id, 'userid' => $student->id,
            'timecreated' => $future + 10, 'meetingid' => 'x[0]', 'log' => 'Summary', 'meta' => '{}',
        ]);

        ob_start();
        (new build_active_snapshots())->execute();
        ob_end_clean();
        $after = (int)$DB->get_field('local_coifish_active_snapshot', 'timecomputed', ['id' => $snap->id]);
        $this->assertLessThan($future, $after);
    }
}
