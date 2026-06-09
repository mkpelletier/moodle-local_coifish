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
 * Hourly task that fills in missing past-week snapshots for lecturers.
 *
 * Walks weekly backward from today to the configured horizon
 * (local_coifish/lecturer_backfill_years, default 3 years) and writes up to
 * a bounded number of missing weeks per run, so cron stays fast and the
 * logstore_standard_log queries remain week-scoped.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish\task;

use core\task\scheduled_task;

/**
 * Backfill historical weekly lecturer snapshots.
 */
class backfill_lecturer_snapshots extends scheduled_task {
    /** @var int Maximum weeks processed per cron tick to keep runtime bounded. */
    protected const MAX_WEEKS_PER_RUN = 50;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_backfill_lecturer_snapshots', 'local_coifish');
    }

    /**
     * Execute: for each lecturer, find the oldest missing week within the
     * configured horizon and fill it. Stop after MAX_WEEKS_PER_RUN writes.
     */
    public function execute(): void {
        global $DB;

        if (get_config('local_coifish', 'profile_enabled') === '0') {
            return;
        }

        $years = (int)(get_config('local_coifish', 'lecturer_backfill_years') ?: 3);
        $years = max(1, min(10, $years));

        $now = time();
        [, $currentweekend] = \local_coifish\lecturer_period_snapshot::week_bounds($now);
        $horizon = $now - $years * 365 * 86400;
        [$horizonstart] = \local_coifish\lecturer_period_snapshot::week_bounds($horizon);

        // All lecturers that have an aggregate profile (i.e. they teach at least one course).
        $lecturerids = $DB->get_fieldset_select('local_coifish_lecturer', 'userid', '');
        if (empty($lecturerids)) {
            mtrace('No lecturers to backfill.');
            return;
        }

        // Existing periodstart values per lecturer (one query, then group in PHP).
        [$insql, $inparams] = $DB->get_in_or_equal($lecturerids, SQL_PARAMS_NAMED, 'lp');
        $rows = $DB->get_records_sql(
            "SELECT id, userid, periodstart
               FROM {local_coifish_lecturer_period_snapshot}
              WHERE userid $insql",
            $inparams
        );
        $havebyuser = [];
        foreach ($rows as $r) {
            $havebyuser[(int)$r->userid][(int)$r->periodstart] = true;
        }

        // Build the list of expected weekly periodstarts within the horizon.
        $expectedweeks = [];
        $cursor = $horizonstart;
        while ($cursor < $currentweekend) {
            $expectedweeks[] = $cursor;
            $cursor += 7 * 86400;
        }
        // Process oldest-first so coordinators get filled-in history sooner.
        sort($expectedweeks);

        $written = 0;
        foreach ($lecturerids as $uid) {
            if ($written >= self::MAX_WEEKS_PER_RUN) {
                break;
            }
            $have = $havebyuser[(int)$uid] ?? [];
            foreach ($expectedweeks as $wstart) {
                if (isset($have[$wstart])) {
                    continue;
                }
                [$pstart, $pend] = \local_coifish\lecturer_period_snapshot::week_bounds($wstart);
                $ok = \local_coifish\lecturer_period_snapshot::upsert((int)$uid, $pstart, $pend);
                if ($ok) {
                    $written++;
                    if ($written >= self::MAX_WEEKS_PER_RUN) {
                        break;
                    }
                }
            }
        }

        mtrace("Backfilled $written weekly lecturer snapshot(s) this run.");
    }
}
