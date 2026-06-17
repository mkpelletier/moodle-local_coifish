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
 * @module     local_coifish/feedback_breakdown
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Ajax from 'core/ajax';
import {getString} from 'core/str';

const cache = {};

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
 * Render the assignment rows into the given target cell.
 *
 * @param {HTMLElement} target The container to render into.
 * @param {Array} rows The assignment rows from the web service.
 * @param {string} emptytext Localised "no data" message.
 */
const render = (target, rows, emptytext) => {
    if (!rows.length) {
        target.innerHTML = '<div class="text-muted small px-2 py-1">' + escape(emptytext) + '</div>';
        return;
    }
    let html = '<table class="table table-sm mb-0"><tbody>';
    rows.forEach((row) => {
        html += '<tr>'
            + '<td>' + escape(row.name) + '</td>'
            + '<td class="text-end">' + escape(row.coverage) + '%</td>'
            + '<td class="text-end">' + escape(row.depth) + '%</td>'
            + '<td class="text-end">' + escape(row.quality) + '%</td>'
            + '<td class="text-end">' + escape(row.personalisation) + '%</td>'
            + '<td class="text-end fw-semibold">' + escape(row.composite) + '%</td>'
            + '<td class="text-end">' + escape(row.nwithfeedback) + '/' + escape(row.ngraded) + '</td>'
            + '</tr>';
    });
    html += '</tbody></table>';
    target.innerHTML = html;
};

/**
 * Initialise the lazy drill-down for one lecturer.
 *
 * @param {number} userid The lecturer user ID.
 */
export const init = (userid) => {
    document.querySelectorAll('.coifish-fb-course').forEach((collapse) => {
        collapse.addEventListener('show.bs.collapse', () => {
            const courseid = parseInt(collapse.getAttribute('data-courseid'), 10);
            const target = collapse.querySelector('.coifish-fb-assignments');
            if (!target || cache[courseid]) {
                return;
            }
            cache[courseid] = true;
            Ajax.call([{
                methodname: 'local_coifish_get_assignment_feedback',
                args: {userid: userid, courseid: courseid},
            }])[0].then((rows) => {
                return getString('lecturer_feedback_breakdown_none', 'local_coifish').then((emptytext) => {
                    render(target, rows, emptytext);
                    return rows;
                });
            }).catch(() => {
                cache[courseid] = false;
                return getString('error').then((msg) => {
                    target.innerHTML = '<div class="text-danger small px-2 py-1">' + escape(msg) + '</div>';
                    return msg;
                });
            });
        });
    });
};
