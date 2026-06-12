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

/**
 * Tests for the active-snapshot task's staleness skip.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coifish\task\build_active_snapshots
 */
final class build_active_snapshots_test extends \advanced_testcase {
    /**
     * Create an active course with one enrolled student.
     *
     * @return array [course, student]
     */
    private function make_course_with_student(): array {
        $gen = $this->getDataGenerator();
        // Active: started in the past, no end date (enddate 0 = ongoing).
        $course = $gen->create_course(['startdate' => time() - 30 * DAYSECS]);
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        return [$course, $student];
    }

    /**
     * Run the task, swallowing its mtrace summary so the test stays non-risky.
     */
    private function run_task(): void {
        ob_start();
        (new build_active_snapshots())->execute();
        ob_end_clean();
    }

    /**
     * Skip a test that needs gradereport_coifish — the snapshot task depends on
     * it for the engagement metric — when that sibling plugin isn't installed
     * (e.g. local_coifish's own CI runs without it).
     */
    private function require_gradereport(): void {
        if (!class_exists('\gradereport_coifish\report')) {
            $this->markTestSkipped('gradereport_coifish is required to build active snapshots');
        }
    }

    /**
     * The fresh/stale predicate: rebuild when there's no row, when a change
     * post-dates the snapshot, or when the TTL has lapsed; skip otherwise.
     */
    public function test_staleness_predicate(): void {
        $m = new \ReflectionMethod(build_active_snapshots::class, 'is_snapshot_fresh');
        $m->setAccessible(true);
        $now = 2000000000;

        // No existing snapshot -> must build.
        $this->assertFalse($m->invoke(null, null, 0, 0, $now));

        // A change landed after the snapshot was computed -> rebuild.
        $changed = (object)['timecomputed' => $now - 100];
        $this->assertFalse($m->invoke(null, $changed, 0, $now, $now));

        // Recent, no change, within TTL -> skip.
        $fresh = (object)['timecomputed' => $now - DAYSECS];
        $this->assertTrue($m->invoke(null, $fresh, 0, 0, $now));

        // Older than the maximum jittered TTL (7 + 6 days) -> force rebuild.
        $stale = (object)['timecomputed' => $now - 20 * DAYSECS];
        $this->assertFalse($m->invoke(null, $stale, 0, 0, $now));
    }

    /**
     * A snapshot with nothing changed since it was computed is left untouched.
     */
    public function test_task_skips_fresh_snapshots(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_gradereport();
        [$course, $student] = $this->make_course_with_student();

        $this->run_task();
        $snap = $DB->get_record('local_coifish_active_snapshot', ['courseid' => $course->id, 'userid' => $student->id]);
        $this->assertNotEmpty($snap);

        // Stamp it in the future: no activity can be newer, and it's within TTL,
        // so the next run must skip it (leaving timecomputed untouched).
        $future = time() + 100000;
        $DB->set_field('local_coifish_active_snapshot', 'timecomputed', $future, ['id' => $snap->id]);

        $this->run_task();
        $after = (int)$DB->get_field('local_coifish_active_snapshot', 'timecomputed', ['id' => $snap->id]);
        $this->assertEquals($future, $after);
    }

    /**
     * A snapshot older than its TTL is rebuilt even with no detected change.
     */
    public function test_task_refreshes_stale_snapshots(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_gradereport();
        [$course, $student] = $this->make_course_with_student();

        $this->run_task();
        $snap = $DB->get_record('local_coifish_active_snapshot', ['courseid' => $course->id, 'userid' => $student->id]);
        $this->assertNotEmpty($snap);

        // Age it past the maximum jittered TTL; the next run must rebuild it.
        $old = time() - 20 * DAYSECS;
        $DB->set_field('local_coifish_active_snapshot', 'timecomputed', $old, ['id' => $snap->id]);

        $this->run_task();
        $after = (int)$DB->get_field('local_coifish_active_snapshot', 'timecomputed', ['id' => $snap->id]);
        $this->assertGreaterThan($old, $after);
    }
}
