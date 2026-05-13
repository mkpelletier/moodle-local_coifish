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
 * Custom admin setting: cohort selection with course shortname regex patterns.
 *
 * Renders a table with one row per system-level cohort. Each row has a checkbox
 * to include the cohort and a text input for the course shortname regex pattern.
 *
 * Stored as JSON: {"cohortid": {"enabled": true, "pattern": "^THE"}, ...}
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Admin setting for mapping cohorts to course shortname patterns.
 */
class local_coifish_admin_setting_cohortpatterns extends admin_setting {
    /**
     * Constructor.
     *
     * @param string $name Setting name.
     * @param string $visiblename Localised title.
     * @param string $description Localised description.
     */
    public function __construct(string $name, string $visiblename, string $description) {
        parent::__construct($name, $visiblename, $description, '');
    }

    /**
     * Get the current setting value.
     *
     * @return string JSON string or empty.
     */
    public function get_setting() {
        return $this->config_read($this->name);
    }

    /**
     * Write the setting value from form submission.
     *
     * The form fields use the full setting name as a prefix so Moodle's
     * admin form processor includes them in the POST data.
     *
     * @param string $data The submitted data — JSON encoded by our hidden field.
     * @return string Empty on success, error message on failure.
     */
    public function write_setting($data) {
        // The data comes from our hidden field which JS populates.
        // If JS didn't run, fall back to reading individual POST fields.
        if (!empty($data) && $data !== '' && ($decoded = json_decode($data, true)) !== null) {
            $error = self::validate_patterns($decoded);
            if ($error !== '') {
                return $error;
            }
            return ($this->config_write($this->name, $data) ? '' : get_string('errorsetting', 'admin'));
        }

        // Fallback: read from individual POST fields.
        global $DB;
        $cohorts = $DB->get_records('cohort', ['contextid' => context_system::instance()->id], 'name ASC');
        $settings = [];
        $fullname = $this->get_full_name();

        foreach ($cohorts as $cohort) {
            // phpcs:ignore moodle.Security.SuperGlobals.SuperGlobalUsage
            $enabled = !empty($_POST[$fullname . '_enabled_' . $cohort->id]);
            // phpcs:ignore moodle.Security.SuperGlobals.SuperGlobalUsage
            $pattern = isset($_POST[$fullname . '_pattern_' . $cohort->id])
                ? clean_param($_POST[$fullname . '_pattern_' . $cohort->id], PARAM_RAW_TRIMMED)
                : '';

            if ($enabled) {
                $settings[$cohort->id] = [
                    'enabled' => true,
                    'pattern' => $pattern,
                ];
            }
        }

        $error = self::validate_patterns($settings);
        if ($error !== '') {
            return $error;
        }

        $json = json_encode($settings);
        return ($this->config_write($this->name, $json) ? '' : get_string('errorsetting', 'admin'));
    }

    /**
     * Validate every pattern in a decoded settings array compiles as a valid PHP regex,
     * and reject patterns containing nested quantifiers that are common ReDoS vectors.
     *
     * @param array $settings Decoded cohort->[enabled,pattern] map.
     * @return string Empty on success, localised error message on failure.
     */
    protected static function validate_patterns(array $settings): string {
        foreach ($settings as $entry) {
            $pattern = isset($entry['pattern']) ? trim((string)$entry['pattern']) : '';
            if ($pattern === '') {
                continue;
            }
            // Compile check with warnings suppressed.
            $ok = @preg_match('/' . $pattern . '/', '');
            if ($ok === false) {
                return get_string('setting_cohortpatterns_invalid', 'local_coifish', $pattern);
            }
            // Reject obvious catastrophic-backtracking shapes: nested quantifiers
            // like (a+)+, (a*)+, (a+)*. These are rarely intentional in a course
            // shortname filter and can hang request threads under bad input.
            if (preg_match('/\([^)]*[+*][^)]*\)[+*]/', $pattern)) {
                return get_string('setting_cohortpatterns_redos', 'local_coifish', $pattern);
            }
        }
        return '';
    }

    /**
     * Render the setting HTML.
     *
     * @param mixed $data Current stored value.
     * @param string $query Search query (for highlighting).
     * @return string HTML output.
     */
    public function output_html($data, $query = '') {
        global $DB;

        $cohorts = $DB->get_records('cohort', ['contextid' => context_system::instance()->id], 'name ASC');
        if (empty($cohorts)) {
            $html = '<div class="alert alert-info">'
                . get_string('setting_cohortpatterns_nocohorts', 'local_coifish')
                . '</div>';
            return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', null, $query);
        }

        $current = [];
        if (!empty($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $current = $decoded;
            }
        }

        $fullname = $this->get_full_name();

        // Hidden field that carries the JSON value for Moodle's form processor.
        $html = '<input type="hidden" id="' . $fullname . '" name="' . $fullname . '" value="' . s($data) . '">';

        $html .= '<table class="table table-sm table-bordered" style="max-width: 700px;">';
        $html .= '<thead class="table-light"><tr>';
        $html .= '<th style="width: 40px;"></th>';
        $html .= '<th>' . get_string('setting_cohortpatterns_col_cohort', 'local_coifish') . '</th>';
        $html .= '<th>' . get_string('setting_cohortpatterns_col_pattern', 'local_coifish') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($cohorts as $cohort) {
            $cid = $cohort->id;
            $isenabled = !empty($current[$cid]['enabled']);
            $pattern = $current[$cid]['pattern'] ?? '';
            $checked = $isenabled ? ' checked' : '';

            $html .= '<tr>';
            $html .= '<td class="text-center align-middle">'
                . '<input type="checkbox" class="form-check-input coifish-cohort-cb" '
                . 'data-cohortid="' . $cid . '" '
                . 'name="' . $fullname . '_enabled_' . $cid . '" value="1"' . $checked . '>'
                . '</td>';
            $html .= '<td class="align-middle">' . s($cohort->name) . '</td>';
            $html .= '<td>'
                . '<input type="text" class="form-control form-control-sm coifish-cohort-pattern" '
                . 'data-cohortid="' . $cid . '" '
                . 'name="' . $fullname . '_pattern_' . $cid . '" '
                . 'value="' . s($pattern) . '" '
                . 'placeholder="' . get_string('setting_cohortpatterns_placeholder', 'local_coifish') . '">'
                . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        // Inline JS to sync the table state into the hidden field before form submission.
        $html .= '<script>'
            . 'document.addEventListener("submit", function(e) {'
            . '  var form = e.target.closest("form");'
            . '  if (!form) return;'
            . '  var hidden = document.getElementById("' . $fullname . '");'
            . '  if (!hidden) return;'
            . '  var data = {};'
            . '  form.querySelectorAll(".coifish-cohort-cb").forEach(function(cb) {'
            . '    if (cb.checked) {'
            . '      var cid = cb.getAttribute("data-cohortid");'
            . '      var patInput = form.querySelector(".coifish-cohort-pattern[data-cohortid=\"" + cid + "\"]");'
            . '      data[cid] = {"enabled": true, "pattern": patInput ? patInput.value.trim() : ""};'
            . '    }'
            . '  });'
            . '  hidden.value = JSON.stringify(data);'
            . '});'
            . '</script>';

        return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', null, $query);
    }
}
