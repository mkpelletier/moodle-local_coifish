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
 * Filter helper for organisation mode (category vs cohort).
 *
 * Abstracts the difference between category-based and cohort-based filtering
 * so that pages and APIs can work with either mode transparently.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish;

/**
 * Helper for resolving filters based on organisation mode.
 */
class filter_helper {
    /**
     * Get the configured organisation mode.
     *
     * @return string 'category' or 'cohort'.
     */
    public static function get_mode(): string {
        return get_config('local_coifish', 'organisation_mode') ?: 'category';
    }

    /**
     * Build a SQL fragment that restricts a query to courses inside the
     * site-wide "Limit course matching to category" admin setting (category id
     * + its descendants).
     *
     * Returns ['', []] when no category is configured (i.e. "All categories"),
     * so callers can append the fragment unconditionally.
     *
     * @param string $coursealias SQL alias for the {course} table in the caller's query (default 'c').
     * @param string $paramprefix Prefix for named bind params to avoid collisions (default 'cscope').
     * @return array [string sqlfragment, array params] e.g. [" AND c.category IN (:cscope0, :cscope1)", ['cscope0' => 12, ...]].
     */
    public static function get_category_scope_sql(string $coursealias = 'c', string $paramprefix = 'cscope'): array {
        global $DB;

        $categoryid = (int)get_config('local_coifish', 'course_category');
        if ($categoryid <= 0) {
            return ['', []];
        }

        $cat = \core_course_category::get($categoryid, IGNORE_MISSING);
        if (!$cat) {
            return ['', []];
        }

        $catids = array_merge([$categoryid], $cat->get_all_children_ids());
        [$insql, $params] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, $paramprefix);
        $fragment = " AND {$coursealias}.category {$insql}";
        return [$fragment, $params];
    }

    /**
     * Get role IDs that represent a teaching role.
     *
     * Reads from the admin setting where the user selects which roles
     * are considered teaching roles. Falls back to roles with the
     * grading capability if the setting is not configured.
     *
     * @return array Array of role IDs.
     */
    public static function get_teacher_role_ids(): array {
        global $DB;

        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        // Read from the admin setting.
        $config = get_config('local_coifish', 'teaching_roles');
        if (!empty($config)) {
            $cache = array_map('intval', array_filter(explode(',', $config)));
            return $cache;
        }

        // Fallback: roles with grading capability.
        $roleids = $DB->get_fieldset_sql(
            "SELECT DISTINCT rc.roleid
               FROM {role_capabilities} rc
              WHERE rc.capability = :cap AND rc.permission = 1",
            ['cap' => 'moodle/grade:viewall']
        );

        $cache = array_map('intval', $roleids);
        return $cache;
    }

    /**
     * Get an SQL IN fragment for teacher role IDs.
     *
     * Returns a tuple of [sql_fragment, params] for use in queries.
     * Example: [$insql, $params] = filter_helper::get_teacher_role_sql('tr');
     *          "AND ra.roleid $insql"
     *
     * @param string $prefix Parameter name prefix.
     * @return array [sql_fragment, params]
     */
    public static function get_teacher_role_sql(string $prefix = 'tr'): array {
        global $DB;

        $roleids = self::get_teacher_role_ids();
        if (empty($roleids)) {
            return ['= 0', []]; // No matching roles — will match nothing.
        }
        return $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, $prefix);
    }

    /**
     * Get the programme configuration: included cohorts with their course patterns.
     *
     * Reads from the unified cohort_programmes JSON setting.
     *
     * @return array Keyed by cohort ID => ['enabled' => bool, 'pattern' => string].
     */
    public static function get_programme_config(): array {
        $config = get_config('local_coifish', 'cohort_programmes');
        if (empty($config)) {
            return [];
        }
        $decoded = json_decode($config, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get the IDs of cohorts that the admin has included for use in reports.
     *
     * @return array Array of cohort IDs, empty if none selected.
     */
    public static function get_included_cohort_ids(): array {
        $config = self::get_programme_config();
        $ids = [];
        foreach ($config as $cid => $data) {
            if (!empty($data['enabled'])) {
                $ids[] = (int)$cid;
            }
        }
        return $ids;
    }

    /**
     * Get the course shortname patterns keyed by cohort ID.
     *
     * @return array Keyed by cohort ID => regex pattern string.
     */
    public static function get_cohort_course_patterns(): array {
        $config = self::get_programme_config();
        $patterns = [];
        foreach ($config as $cid => $data) {
            if (!empty($data['enabled']) && !empty($data['pattern'])) {
                $patterns[(int)$cid] = $data['pattern'];
            }
        }
        return $patterns;
    }

    /**
     * Get the course pattern for the current user's cohort(s).
     *
     * Returns a combined regex pattern from all included cohorts the user belongs to.
     *
     * @param int $cohortid Specific cohort (0 for all the user's cohorts).
     * @return string Combined regex pattern, or empty string if none.
     */
    public static function get_user_course_pattern(int $cohortid = 0): string {
        global $DB, $USER;

        $patterns = self::get_cohort_course_patterns();
        if (empty($patterns)) {
            return '';
        }

        if ($cohortid > 0) {
            return $patterns[$cohortid] ?? '';
        }

        // Get all patterns from cohorts the user belongs to.
        $includedids = self::get_included_cohort_ids();
        if (empty($includedids)) {
            return '';
        }
        [$insql, $inparams] = $DB->get_in_or_equal($includedids, SQL_PARAMS_NAMED);
        $mycohortids = $DB->get_fieldset_sql(
            "SELECT cm.cohortid FROM {cohort_members} cm WHERE cm.userid = :uid AND cm.cohortid $insql",
            array_merge(['uid' => $USER->id], $inparams)
        );

        $mypatterns = [];
        foreach ($mycohortids as $cid) {
            if (!empty($patterns[(int)$cid])) {
                $mypatterns[] = $patterns[(int)$cid];
            }
        }
        return !empty($mypatterns) ? implode('|', $mypatterns) : '';
    }

    /**
     * Get user IDs (lecturers or students) enrolled in courses matching a regex pattern.
     *
     * @param string $pattern Regex pattern to match against course shortnames.
     * @param string $role 'teacher' for lecturers, 'student' for students.
     * @return array User IDs.
     */
    /** @var array<string,array<int>> Per-request cache keyed by category+pattern. */
    protected static array $courseidcache = [];

    /**
     * Resolve a course shortname regex pattern to the list of matching course IDs,
     * scoped to the configured "Limit course matching to category" admin setting
     * if it is set.
     *
     * Push-down strategy: for simple anchored prefix patterns like "^THE" we
     * translate to a SQL LIKE so the filter runs in the database. For complex
     * patterns (alternations, groups, lookarounds, etc.) we fall back to
     * fetching shortnames in the scope and matching in PHP — but always scoped
     * to the configured category include rather than the whole site.
     *
     * Result is cached per request keyed on category + pattern so repeated
     * callers (e.g. the risk overview + lecturer list on the same page) don't
     * recompute.
     *
     * @param string $pattern Regex pattern to match against course shortnames.
     * @return int[] List of matching course IDs (de-duplicated, indexed).
     */
    public static function get_course_ids_matching_pattern(string $pattern): array {
        global $DB;

        if ($pattern === '') {
            return [];
        }

        $categoryid = (int)get_config('local_coifish', 'course_category');
        $cachekey = $categoryid . '|' . $pattern;
        if (isset(self::$courseidcache[$cachekey])) {
            return self::$courseidcache[$cachekey];
        }

        // Build a category-scope SQL fragment (empty if no include configured).
        $catfrag = '';
        $catparams = [];
        if ($categoryid > 0) {
            $cat = \core_course_category::get($categoryid, IGNORE_MISSING);
            if ($cat) {
                $catids = array_merge([$categoryid], $cat->get_all_children_ids());
                [$catinsql, $catparams] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'cscat');
                $catfrag = " AND category $catinsql";
            }
        }

        // Try the SQL-side LIKE fast path for simple anchored prefix patterns
        // like "^THE", "^BIB", "^MA[0-9]" -> "THE%", "BIB%". Anything containing
        // regex metacharacters that don't translate cleanly falls through.
        $likepattern = self::pattern_to_sql_like($pattern);
        if ($likepattern !== null) {
            $params = array_merge(['siteid' => SITEID, 'pat' => $likepattern], $catparams);
            // Case-insensitive LIKE: Moodle's $DB->sql_like(..., false) handles
            // driver portability.
            $likeclause = $DB->sql_like('shortname', ':pat', false);
            $matches = $DB->get_fieldset_sql(
                "SELECT id FROM {course}
                  WHERE id != :siteid AND $likeclause $catfrag",
                $params
            );
            $matches = array_values(array_map('intval', $matches));
            self::$courseidcache[$cachekey] = $matches;
            return $matches;
        }

        // Complex pattern fallback: fetch shortnames within the configured
        // scope only (never the whole site if a category is configured).
        $params = array_merge(['siteid' => SITEID], $catparams);
        $courses = $DB->get_records_sql(
            "SELECT id, shortname FROM {course} WHERE id != :siteid $catfrag",
            $params
        );

        $matches = [];
        $delimited = '/' . $pattern . '/i';
        foreach ($courses as $course) {
            // Suppress warnings — pattern validation belongs in the admin setting.
            if (@preg_match($delimited, $course->shortname)) {
                $matches[] = (int)$course->id;
            }
        }
        self::$courseidcache[$cachekey] = $matches;
        return $matches;
    }

    /**
     * Translate a simple anchored-prefix regex like "^THE" or "^BIB2"
     * into a SQL LIKE pattern. Returns null if the pattern contains any
     * regex metacharacter that we don't safely translate.
     *
     * Accepted shape: leading caret followed by 1+ characters drawn only
     * from [A-Za-z0-9_-]. Everything else (alternations, character
     * classes, quantifiers, groups, lookarounds, end anchors) falls
     * through to the PHP-side regex path.
     *
     * @param string $pattern Raw regex pattern (no delimiters).
     * @return string|null SQL LIKE pattern (with trailing %) or null.
     */
    protected static function pattern_to_sql_like(string $pattern): ?string {
        if (!preg_match('/^\^([A-Za-z0-9_-]+)$/', $pattern, $m)) {
            return null;
        }
        // Escape any SQL LIKE metacharacters in the literal prefix (defence in depth;
        // the regex above already rejects % and _).
        $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $m[1]);
        return $prefix . '%';
    }

    public static function get_users_by_course_pattern(string $pattern, string $role = 'student'): array {
        global $DB;

        if (empty($pattern)) {
            return [];
        }

        $matchingcourseids = self::get_course_ids_matching_pattern($pattern);
        if (empty($matchingcourseids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($matchingcourseids, SQL_PARAMS_NAMED);

        if ($role === 'teacher') {
            [$trinsql, $trparams] = self::get_teacher_role_sql();
            return $DB->get_fieldset_sql(
                "SELECT DISTINCT ra.userid
                   FROM {role_assignments} ra
                   JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                  WHERE ctx.instanceid $insql
                    AND ra.roleid $trinsql",
                array_merge(['ctxlevel' => CONTEXT_COURSE], $inparams, $trparams)
            );
        }

        // Students: enrolled users with the completion reports capability (standard student filter).
        $studentids = [];
        foreach ($matchingcourseids as $cid) {
            $context = \context_course::instance($cid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            $enrolled = get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, 'u.id');
            $studentids = array_merge($studentids, array_keys($enrolled));
        }
        return array_unique($studentids);
    }

    /**
     * Get the filter dropdown options for the current mode.
     *
     * @param int $selectedid The currently selected filter ID.
     * @return array ['mode' => string, 'options' => array, 'label' => string, 'paramname' => string]
     */
    public static function get_filter_options(int $selectedid = 0): array {
        $mode = self::get_mode();

        if ($mode === 'cohort') {
            return self::get_cohort_filter_options($selectedid);
        }
        return self::get_category_filter_options($selectedid);
    }

    /**
     * Get lecturer user IDs visible to the current user based on filter.
     *
     * In cohort mode, returns lecturers who share a cohort with the current user
     * (or all lecturers in the selected cohort).
     * In category mode, returns lecturers teaching in the selected category.
     *
     * @param int $filterid The selected filter ID (category or cohort ID, 0 for all).
     * @return array|null Array of user IDs, or null for "no filter" (show all).
     */
    public static function get_filtered_lecturer_ids(int $filterid = 0): ?array {
        $mode = self::get_mode();

        if ($mode === 'cohort') {
            // Use course pattern to find lecturers in the programme.
            $pattern = self::get_user_course_pattern($filterid);
            if (!empty($pattern)) {
                return self::get_users_by_course_pattern($pattern, 'teacher');
            }
            // No specific cohort selected and no pattern — admins see all, others use cohort membership.
            if ($filterid == 0 && is_siteadmin()) {
                return null;
            }
            return self::get_cohort_lecturer_ids($filterid, $GLOBALS['USER']->id);
        }

        if ($filterid > 0) {
            return self::get_category_lecturer_ids($filterid);
        }
        return null; // No filter — show all.
    }

    /**
     * Get student user IDs visible based on filter (for risk overview).
     *
     * In cohort mode, uses the course shortname pattern to find students
     * enrolled in courses belonging to the PC's programme.
     *
     * @param int $filterid The selected filter ID.
     * @return array|null Array of student user IDs, or null for "no filter".
     */
    public static function get_filtered_student_ids(int $filterid = 0): ?array {
        $mode = self::get_mode();

        if ($mode === 'cohort') {
            // Use course pattern to find students in the programme.
            $pattern = self::get_user_course_pattern($filterid);
            if (!empty($pattern)) {
                return self::get_users_by_course_pattern($pattern, 'student');
            }
            // No specific cohort selected and no pattern — admins see all.
            if ($filterid == 0 && is_siteadmin()) {
                return null;
            }
            // Fall back to lecturer-course chain.
            $lecturerids = self::get_cohort_lecturer_ids($filterid, $GLOBALS['USER']->id);
            if ($lecturerids === null) {
                return null;
            }
            if (empty($lecturerids)) {
                return [];
            }
            return self::get_students_of_lecturers($lecturerids);
        }

        // Category mode — handled by the API directly.
        return null;
    }

    /**
     * Get category filter options.
     *
     * @param int $selectedid Currently selected category.
     * @return array Filter data.
     */
    protected static function get_category_filter_options(int $selectedid): array {
        $options = [];
        $catlist = \core_course_category::make_categories_list();
        foreach ($catlist as $id => $name) {
            $options[] = [
                'id' => $id,
                'name' => $name,
                'selected' => ($id == $selectedid),
            ];
        }
        return [
            'mode' => 'category',
            'options' => $options,
            'label' => get_string('filter_category', 'local_coifish'),
            'alllabel' => get_string('filter_all_categories', 'local_coifish'),
            'paramname' => 'categoryid',
        ];
    }

    /**
     * Get cohort filter options. Shows cohorts the current user belongs to.
     *
     * @param int $selectedid Currently selected cohort.
     * @return array Filter data.
     */
    protected static function get_cohort_filter_options(int $selectedid): array {
        global $DB, $USER;

        $includedids = self::get_included_cohort_ids();
        if (empty($includedids)) {
            return [
                'mode' => 'cohort',
                'options' => [],
                'label' => get_string('filter_cohort', 'local_coifish'),
                'alllabel' => get_string('filter_all_cohorts', 'local_coifish'),
                'paramname' => 'cohortid',
            ];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($includedids, SQL_PARAMS_NAMED, 'inc');

        // Get included cohorts the current user is a member of.
        $mycohorts = $DB->get_records_sql(
            "SELECT c.id, c.name
               FROM {cohort} c
               JOIN {cohort_members} cm ON cm.cohortid = c.id
              WHERE cm.userid = :userid AND c.id $insql
           ORDER BY c.name ASC",
            array_merge(['userid' => $USER->id], $inparams)
        );

        // If user is site admin, show all included cohorts.
        if (is_siteadmin()) {
            [$insql2, $inparams2] = $DB->get_in_or_equal($includedids, SQL_PARAMS_NAMED, 'adm');
            $allcohorts = $DB->get_records_sql(
                "SELECT c.id, c.name FROM {cohort} c WHERE c.id $insql2 ORDER BY c.name ASC",
                $inparams2
            );
            $mycohorts = $allcohorts + $mycohorts;
        }

        $options = [];
        foreach ($mycohorts as $cohort) {
            $options[] = [
                'id' => $cohort->id,
                'name' => $cohort->name,
                'selected' => ($cohort->id == $selectedid),
            ];
        }

        return [
            'mode' => 'cohort',
            'options' => $options,
            'label' => get_string('filter_cohort', 'local_coifish'),
            'alllabel' => get_string('filter_all_cohorts', 'local_coifish'),
            'paramname' => 'cohortid',
        ];
    }

    /**
     * Get lecturer IDs from a cohort (or all cohorts the coordinator belongs to).
     *
     * @param int $cohortid Specific cohort (0 for all the user's cohorts).
     * @param int $viewerid The viewing user's ID.
     * @return array|null Lecturer user IDs, or null for no filter.
     */
    protected static function get_cohort_lecturer_ids(int $cohortid, int $viewerid): ?array {
        global $DB;

        $includedids = self::get_included_cohort_ids();
        if (empty($includedids)) {
            // No included cohorts configured — site admins see all, others see nothing.
            if (is_siteadmin()) {
                return null;
            }
            return [];
        }

        // Site admins see all members of included cohorts regardless of their own membership.
        $isadmin = is_siteadmin();

        if ($cohortid > 0) {
            // Specific cohort selected — verify it's included.
            if (!in_array($cohortid, $includedids)) {
                return [];
            }
            // Non-admins must be a member of this cohort to see its members.
            if (!$isadmin) {
                $ismember = $DB->record_exists('cohort_members', [
                    'cohortid' => $cohortid,
                    'userid' => $viewerid,
                ]);
                if (!$ismember) {
                    return [];
                }
            }
            // Return all members except the viewer themselves.
            return $DB->get_fieldset_sql(
                "SELECT cm.userid FROM {cohort_members} cm
                  WHERE cm.cohortid = :cid AND cm.userid != :viewerid",
                ['cid' => $cohortid, 'viewerid' => $viewerid]
            );
        }

        if ($isadmin) {
            // Admins see all members of all included cohorts.
            [$insql, $inparams] = $DB->get_in_or_equal($includedids, SQL_PARAMS_NAMED, 'inc');
            return $DB->get_fieldset_sql(
                "SELECT DISTINCT cm.userid FROM {cohort_members} cm WHERE cm.cohortid $insql",
                $inparams
            );
        }

        // Non-admins: return co-members from included cohorts, excluding the viewer.
        [$insql, $inparams] = $DB->get_in_or_equal($includedids, SQL_PARAMS_NAMED, 'inc');
        $memberids = $DB->get_fieldset_sql(
            "SELECT DISTINCT cm2.userid
               FROM {cohort_members} cm1
               JOIN {cohort_members} cm2 ON cm2.cohortid = cm1.cohortid
              WHERE cm1.userid = :viewerid
                AND cm1.cohortid $insql
                AND cm2.userid != :viewerid2",
            array_merge(['viewerid' => $viewerid, 'viewerid2' => $viewerid], $inparams)
        );

        // If viewer isn't in any included cohort, they see nothing (not "all").
        return $memberids ?: [];
    }

    /**
     * Get lecturer IDs from courses in a category tree.
     *
     * @param int $categoryid Category ID.
     * @return array Lecturer user IDs.
     */
    protected static function get_category_lecturer_ids(int $categoryid): array {
        global $DB;

        $cat = \core_course_category::get($categoryid, IGNORE_MISSING);
        if (!$cat) {
            return [];
        }
        $catids = array_merge([$categoryid], $cat->get_all_children_ids());
        [$insql, $inparams] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED);

        [$trinsql, $trparams] = self::get_teacher_role_sql();
        return $DB->get_fieldset_sql(
            "SELECT DISTINCT ra.userid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
               JOIN {course} c ON c.id = ctx.instanceid
              WHERE c.category $insql
                AND ra.roleid $trinsql",
            array_merge(['ctxlevel' => CONTEXT_COURSE], $inparams, $trparams)
        );
    }

    /**
     * Get student IDs enrolled in courses taught by the given lecturers.
     *
     * @param array $lecturerids Lecturer user IDs.
     * @return array Student user IDs.
     */
    protected static function get_students_of_lecturers(array $lecturerids): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($lecturerids, SQL_PARAMS_NAMED);

        // Get courses these lecturers teach.
        [$trinsql, $trparams] = self::get_teacher_role_sql();
        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT ctx.instanceid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
              WHERE ra.userid $insql
                AND ra.roleid $trinsql",
            array_merge(['ctxlevel' => CONTEXT_COURSE], $inparams, $trparams)
        );

        if (empty($courseids)) {
            return [];
        }

        // Get students enrolled in those courses.
        [$insqlc, $inparamsc] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        return $DB->get_fieldset_sql(
            "SELECT DISTINCT ue.userid
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid $insqlc",
            $inparamsc
        );
    }
}
