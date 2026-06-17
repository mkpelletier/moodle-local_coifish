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
 * Coordinators additionally get a per-row action to mark an assignment as not
 * feedback-relevant; doing so drops it from the analytics and removes its row.
 *
 * @module     local_coifish/feedback_breakdown
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Ajax from 'core/ajax';
import {getString} from 'core/str';
import Notification from 'core/notification';

const cache = {};
const state = {canmanage: false};

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
 * @param {string} excludelabel Localised action label for coordinators.
 * @param {string} excludetitle Localised action title/aria text for coordinators.
 */
const render = (target, rows, emptytext, excludelabel, excludetitle) => {
    if (!rows.length) {
        target.innerHTML = '<tr class="table-light"><td colspan="7" class="text-muted small ps-4 py-1">'
            + escape(emptytext) + '</td></tr>';
        return;
    }
    let html = '';
    rows.forEach((row) => {
        let action = '';
        if (state.canmanage) {
            action = ' <a href="#" class="coifish-fb-exclude small ms-2" '
                + 'data-cmid="' + escape(row.cmid) + '" '
                + 'title="' + escape(excludetitle) + '" '
                + 'aria-label="' + escape(excludetitle) + '">'
                + escape(excludelabel) + '</a>';
        }
        html += '<tr class="table-light">'
            + '<td class="ps-4">' + escape(row.name) + action + '</td>'
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
 * Handle a coordinator clicking the "not feedback-relevant" action on a row.
 *
 * @param {Event} e The click event.
 */
const onExcludeClick = (e) => {
    const link = e.target.closest('.coifish-fb-exclude');
    if (!link) {
        return;
    }
    e.preventDefault();
    const cmid = parseInt(link.getAttribute('data-cmid'), 10);
    const row = link.closest('tr');
    Ajax.call([{
        methodname: 'local_coifish_toggle_feedback_exclusion',
        args: {cmid: cmid, excluded: true},
    }])[0].then((result) => {
        if (row && result && result.excluded) {
            row.parentNode.removeChild(row);
        }
        return result;
    }).catch((error) => {
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
    document.querySelectorAll('.coifish-fb-course').forEach((collapse) => {
        if (state.canmanage) {
            collapse.addEventListener('click', onExcludeClick);
        }
        collapse.addEventListener('show.bs.collapse', () => {
            const courseid = parseInt(collapse.getAttribute('data-courseid'), 10);
            if (cache[courseid]) {
                return;
            }
            cache[courseid] = true;
            const request = Ajax.call([{
                methodname: 'local_coifish_get_assignment_feedback',
                args: {userid: userid, courseid: courseid},
            }])[0];
            Promise.all([
                request,
                getString('lecturer_feedback_breakdown_none', 'local_coifish'),
                getString('lecturer_feedback_exclude_action', 'local_coifish'),
                getString('lecturer_feedback_exclude_title', 'local_coifish'),
            ]).then(([rows, emptytext, excludelabel, excludetitle]) => {
                render(collapse, rows, emptytext, excludelabel, excludetitle);
                return rows;
            }).catch((error) => {
                cache[courseid] = false;
                Notification.exception(error);
                return error;
            });
        });
    });
};
