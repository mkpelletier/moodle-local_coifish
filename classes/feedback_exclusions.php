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
 * Decides whether an assignment counts toward the feedback-quality analytics.
 *
 * The default is a grade-type heuristic: complete/incomplete (scale-graded)
 * assignments are treated as "not feedback-relevant" — these are typically
 * self-study activities that never receive written feedback, so counting them
 * would drag coverage down unfairly. Point-graded assignments are feedback-
 * relevant by default. The programme coordinator can override the heuristic
 * either way from the lecturer-profile drill-down, and those overrides are
 * stored as two small lists:
 *
 *  - {@see self::CONFIG} ($excluded) — point-graded assignments the coordinator
 *    forced OUT of the analytics.
 *  - {@see self::CONFIG_INCLUDED} ($included) — scale-graded assignments the
 *    coordinator forced back IN.
 *
 * Only genuine overrides (where the coordinator disagrees with the heuristic)
 * are stored, so the lists stay tiny. Owned by local_coifish; gradereport_coifish
 * reads the same two config keys (via plain get_config, no code dependency) and
 * applies the identical heuristic in SQL so its cohort coverage stays consistent.
 */
class feedback_exclusions {
    /** @var string Config name holding the comma-separated force-excluded cmids. */
    public const CONFIG = 'feedback_excluded_cmids';

    /** @var string Config name holding the comma-separated force-included cmids. */
    public const CONFIG_INCLUDED = 'feedback_included_cmids';

    /**
     * Course-module ids the coordinator forced out of the analytics.
     *
     * @return int[] Excluded cmids (may be empty).
     */
    public static function get_excluded_cmids(): array {
        return self::read(self::CONFIG);
    }

    /**
     * Course-module ids the coordinator forced back into the analytics,
     * overriding the not-feedback-relevant heuristic.
     *
     * @return int[] Included cmids (may be empty).
     */
    public static function get_included_cmids(): array {
        return self::read(self::CONFIG_INCLUDED);
    }

    /**
     * Whether an assignment is a complete/incomplete (scale-graded) activity,
     * which the heuristic treats as not feedback-relevant by default.
     *
     * @param int $cmid Course-module id.
     * @return bool True when the assignment is scale-graded.
     */
    public static function is_scale_graded(int $cmid): bool {
        global $DB;
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return false;
        }
        $grade = $DB->get_field('assign', 'grade', ['id' => $cm->instance]);
        // assign.grade: > 0 points, 0 none, < 0 the negated scale id.
        return $grade !== false && (float)$grade < 0;
    }

    /**
     * Whether an assignment is currently kept out of the feedback analytics —
     * the effective result of the heuristic plus any coordinator override.
     *
     * @param int $cmid Course-module id.
     * @return bool
     */
    public static function is_excluded(int $cmid): bool {
        if (in_array($cmid, self::get_included_cmids(), true)) {
            return false;
        }
        if (in_array($cmid, self::get_excluded_cmids(), true)) {
            return true;
        }
        return self::is_scale_graded($cmid);
    }

    /**
     * Set an assignment's effective feedback-relevance, storing only the minimal
     * override needed against the grade-type heuristic.
     *
     * @param int $cmid Course-module id.
     * @param bool $excluded True to keep it out of the analytics, false to count it.
     * @return array ['excluded' => bool, 'excludedauto' => bool] effective state.
     */
    public static function apply_state(int $cmid, bool $excluded): array {
        $scale = self::is_scale_graded($cmid);
        $excludebefore = self::get_excluded_cmids();
        $includebefore = self::get_included_cmids();
        $excludeset = array_flip($excludebefore);
        $includeset = array_flip($includebefore);

        // Clear any existing override; we re-add one only if it disagrees with
        // the heuristic, so a request that matches the default stores nothing.
        unset($excludeset[$cmid], $includeset[$cmid]);
        if ($excluded && !$scale) {
            // Point-graded (heuristic: included) forced out.
            $excludeset[$cmid] = true;
        } else if (!$excluded && $scale) {
            // Scale-graded (heuristic: excluded) forced back in.
            $includeset[$cmid] = true;
        }

        // Only write the keys whose contents actually changed — set_config
        // purges the config cache, so skip the no-op writes.
        $excludeafter = self::normalise(array_keys($excludeset));
        $includeafter = self::normalise(array_keys($includeset));
        if (self::normalise($excludebefore) !== $excludeafter) {
            self::write(self::CONFIG, $excludeafter);
        }
        if (self::normalise($includebefore) !== $includeafter) {
            self::write(self::CONFIG_INCLUDED, $includeafter);
        }

        // Effective state: an override wins, otherwise the heuristic stands.
        if ($excluded && !$scale) {
            return ['excluded' => true, 'excludedauto' => false];
        }
        if (!$excluded && $scale) {
            return ['excluded' => false, 'excludedauto' => false];
        }
        return ['excluded' => $scale, 'excludedauto' => $scale];
    }

    /**
     * Parse a comma-separated cmid config value into a list of ints.
     *
     * @param string $config Config name.
     * @return int[] Parsed cmids (may be empty).
     */
    private static function read(string $config): array {
        $raw = (string)get_config('local_coifish', $config);
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
     * Persist a list of cmids (sorted, de-duplicated) to a config key.
     *
     * @param string $config Config name.
     * @param int[] $cmids The cmids to store.
     */
    private static function write(string $config, array $cmids): void {
        set_config($config, implode(',', self::normalise($cmids)), 'local_coifish');
    }

    /**
     * Sort, de-duplicate and int-cast a list of cmids for stable comparison/storage.
     *
     * @param int[] $cmids The cmids.
     * @return int[] Normalised cmids.
     */
    private static function normalise(array $cmids): array {
        $cmids = array_values(array_unique(array_map('intval', $cmids)));
        sort($cmids);
        return $cmids;
    }
}
