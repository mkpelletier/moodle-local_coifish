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
 * Per-assignment "no written feedback expected" exclusion list.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish;

/**
 * Stores and queries the course-module ids a programme coordinator has marked as
 * not feedback-relevant — e.g. complete/incomplete self-study activities that
 * never receive written feedback — so they are kept out of the feedback-quality
 * analytics rather than dragging coverage down.
 *
 * Owned by local_coifish: the coordinator manages the list from the lecturer
 * profile drill-down. gradereport_coifish reads the same config key (via plain
 * get_config, no code dependency) so its cohort coverage stays consistent.
 */
class feedback_exclusions {
    /** @var string Config name holding the comma-separated excluded cmids. */
    public const CONFIG = 'feedback_excluded_cmids';

    /**
     * Course-module ids marked as not feedback-relevant.
     *
     * @return int[] Excluded cmids (may be empty).
     */
    public static function get_excluded_cmids(): array {
        $raw = (string)get_config('local_coifish', self::CONFIG);
        if (trim($raw) === '') {
            return [];
        }
        $ids = [];
        foreach (preg_split('/[\s,]+/', $raw) as $token) {
            $token = trim($token);
            if ($token !== '' && ctype_digit($token)) {
                $ids[(int)$token] = (int)$token;
            }
        }
        return array_values($ids);
    }

    /**
     * Whether a course module has been excluded.
     *
     * @param int $cmid Course-module id.
     * @return bool
     */
    public static function is_excluded(int $cmid): bool {
        return in_array($cmid, self::get_excluded_cmids(), true);
    }

    /**
     * Add or remove a course module from the exclusion list.
     *
     * @param int $cmid Course-module id.
     * @param bool $excluded True to exclude, false to re-include.
     */
    public static function set_excluded(int $cmid, bool $excluded): void {
        $set = array_flip(self::get_excluded_cmids());
        if ($excluded) {
            $set[$cmid] = true;
        } else {
            unset($set[$cmid]);
        }
        $list = array_keys($set);
        sort($list);
        set_config(self::CONFIG, implode(',', $list), 'local_coifish');
    }
}
