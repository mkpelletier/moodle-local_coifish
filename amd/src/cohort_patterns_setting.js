/**
 * Cohort programmes admin setting: serialise the table state into the
 * hidden JSON field before the surrounding admin form is submitted.
 *
 * @module     local_coifish/cohort_patterns_setting
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    return {
        /**
         * Bind a submit handler that writes the per-cohort checkbox/pattern
         * state into the hidden input as a JSON-encoded map.
         *
         * @param {string} hiddenInputId The id of the hidden input to populate.
         */
        init: function(hiddenInputId) {
            var hidden = document.getElementById(hiddenInputId);
            if (!hidden) {
                return;
            }
            var form = hidden.closest('form');
            if (!form) {
                return;
            }
            form.addEventListener('submit', function() {
                var data = {};
                form.querySelectorAll('.coifish-cohort-cb').forEach(function(cb) {
                    if (!cb.checked) {
                        return;
                    }
                    var cid = cb.getAttribute('data-cohortid');
                    var patInput = form.querySelector(
                        '.coifish-cohort-pattern[data-cohortid="' + cid + '"]'
                    );
                    data[cid] = {
                        enabled: true,
                        pattern: patInput ? patInput.value.trim() : ''
                    };
                });
                hidden.value = JSON.stringify(data);
            });
        }
    };
});
