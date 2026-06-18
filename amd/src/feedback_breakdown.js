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
 * Lazy per-assignment feedback drill-down on the lecturer profile.
 *
 * When a course row in the "Feedback by course" card is expanded, fetch its
 * per-assignment breakdown once over the web service and cache the result so
 * re-expanding does not re-fetch. Never eager-loaded.
 *
 * Complete/incomplete (scale-graded) assignments are auto-excluded from the
 * feedback metrics and shown greyed with a badge. Coordinators get a per-row
 * action to exclude a feedback-relevant assignment (fa-ban) or include an
 * excluded one (fa-plus-square); toggling updates the row in place.
 *
 * @module     local_coifish/feedback_breakdown
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Ajax from 'core/ajax';
import {getStrings} from 'core/str';
import Notification from 'core/notification';

// Per-course state: rows === null until fetched, then the row array (re-rendered
// in place on toggle). A truthy `loading` guard prevents a double fetch.
const courses = {};
const state = {canmanage: false, strings: {}};

/**
 * Escape a string for safe insertion as text content of an HTML cell.
 *
 * @param {string} value The raw value.
 * @return {string} HTML-escaped value.
 */
const escape = (value) => {
    const div = document.createElement('div');
    div.textContent = value === null || value === undefined ? '' : String(value);
    return div.innerHTML;
};

/**
 * Build the coordinator action icon for a row (exclude or include).
 *
 * @param {Object} row The assignment row.
 * @return {string} The action HTML, or '' when the viewer cannot manage.
 */
const actionFor = (row) => {
    if (!state.canmanage) {
        return '';
    }
    if (row.excluded) {
        return ' <a href="#" class="coifish-fb-toggle ms-2" data-cmid="' + escape(row.cmid) + '" '
            + 'data-exclude="0" title="' + escape(state.strings.include) + '" '
            + 'aria-label="' + escape(state.strings.include) + '">'
            + '<i class="fa fa-plus-square" aria-hidden="true"></i></a>';
    }
    return ' <a href="#" class="coifish-fb-toggle ms-2" data-cmid="' + escape(row.cmid) + '" '
        + 'data-exclude="1" title="' + escape(state.strings.exclude) + '" '
        + 'aria-label="' + escape(state.strings.exclude) + '">'
        + '<i class="fa fa-ban" aria-hidden="true"></i></a>';
};

/**
 * Build the "excluded" badge for a row, if any.
 *
 * @param {Object} row The assignment row.
 * @return {string} The badge HTML, or '' when the row counts toward the metrics.
 */
const badgeFor = (row) => {
    if (!row.excluded) {
        return '';
    }
    const label = row.excludedauto ? state.strings.badgeauto : state.strings.badgeexcluded;
    return ' <span class="badge bg-secondary ms-2">' + escape(label) + '</span>';
};

/**
 * Render the assignment rows into the given course tbody.
 *
 * @param {HTMLElement} target The collapse tbody to render into.
 * @param {Array} rows The assignment rows from the web service.
 */
const render = (target, rows) => {
    if (!rows.length) {
        target.innerHTML = '<tr class="table-light"><td colspan="7" class="text-muted small ps-4 py-1">'
            + escape(state.strings.none) + '</td></tr>';
        return;
    }
    let html = '';
    rows.forEach((row) => {
        const rowclass = row.excluded ? 'table-light text-muted' : 'table-light';
        html += '<tr class="' + rowclass + '">'
            + '<td class="ps-4">' + escape(row.name) + badgeFor(row) + actionFor(row) + '</td>'
            + '<td class="text-end">' + escape(row.coverage) + '%</td>'
            + '<td class="text-end">' + escape(row.depth) + '%</td>'
            + '<td class="text-end">' + escape(row.quality) + '%</td>'
            + '<td class="text-end">' + escape(row.personalisation) + '%</td>'
            + '<td class="text-end fw-semibold">' + escape(row.composite) + '%</td>'
            + '<td class="text-end">' + escape(row.nwithfeedback) + '/' + escape(row.ngraded) + '</td>'
            + '</tr>';
    });
    target.innerHTML = html;
};

/**
 * Handle a coordinator clicking the exclude/include action on a row.
 *
 * @param {Event} e The click event.
 */
const onToggleClick = (e) => {
    const link = e.target.closest('.coifish-fb-toggle');
    if (!link) {
        return;
    }
    e.preventDefault();
    const cmid = parseInt(link.getAttribute('data-cmid'), 10);
    const exclude = link.getAttribute('data-exclude') === '1';
    const tbody = link.closest('tbody.coifish-fb-course');
    const courseid = tbody ? parseInt(tbody.getAttribute('data-courseid'), 10) : null;
    Ajax.call([{
        methodname: 'local_coifish_toggle_feedback_exclusion',
        args: {cmid: cmid, excluded: exclude},
    }])[0].then((result) => {
        const entry = courseid === null ? null : courses[courseid];
        if (tbody && entry && Array.isArray(entry.rows)) {
            entry.rows.forEach((row) => {
                if (parseInt(row.cmid, 10) === cmid) {
                    row.excluded = result.excluded;
                    row.excludedauto = result.excludedauto;
                }
            });
            render(tbody, entry.rows);
        }
        return result;
    }).catch((error) => {
        Notification.exception(error);
        return error;
    });
};

/**
 * Fetch and render one course's breakdown the first time it is expanded.
 *
 * @param {HTMLElement} collapse The course tbody being expanded.
 * @param {number} userid The lecturer user ID.
 */
const loadCourse = (collapse, userid) => {
    const courseid = parseInt(collapse.getAttribute('data-courseid'), 10);
    const entry = courses[courseid];
    if (entry && (entry.loading || entry.rows)) {
        return;
    }
    courses[courseid] = {loading: true, rows: null};
    Ajax.call([{
        methodname: 'local_coifish_get_assignment_feedback',
        args: {userid: userid, courseid: courseid},
    }])[0].then((rows) => {
        courses[courseid] = {loading: false, rows: rows};
        render(collapse, rows);
        return rows;
    }).catch((error) => {
        courses[courseid] = {loading: false, rows: null};
        Notification.exception(error);
        return error;
    });
};

/**
 * Initialise the lazy drill-down for one lecturer.
 *
 * @param {number} userid The lecturer user ID.
 * @param {boolean} canmanage Whether the viewer may toggle feedback relevance.
 */
export const init = (userid, canmanage) => {
    state.canmanage = canmanage === true;
    getStrings([
        {key: 'lecturer_feedback_breakdown_none', component: 'local_coifish'},
        {key: 'lecturer_feedback_exclude_title', component: 'local_coifish'},
        {key: 'lecturer_feedback_include_title', component: 'local_coifish'},
        {key: 'lecturer_feedback_badge_excluded', component: 'local_coifish'},
        {key: 'lecturer_feedback_badge_autoexcluded', component: 'local_coifish'},
    ]).then((strings) => {
        state.strings = {
            none: strings[0],
            exclude: strings[1],
            include: strings[2],
            badgeexcluded: strings[3],
            badgeauto: strings[4],
        };
        return strings;
    }).catch((error) => {
        Notification.exception(error);
        return error;
    });

    document.querySelectorAll('.coifish-fb-course').forEach((collapse) => {
        if (state.canmanage) {
            collapse.addEventListener('click', onToggleClick);
        }
        collapse.addEventListener('show.bs.collapse', () => {
            loadCourse(collapse, userid);
        });
    });
};
