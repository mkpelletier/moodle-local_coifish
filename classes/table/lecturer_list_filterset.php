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
 * Filterset for the institution-wide Lecturer list dynamic table.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace local_coifish\table;

use core_table\local\filter\filterset;
use core_table\local\filter\integer_filter;

/**
 * Carries category / cohort filter state from the page into the dynamic
 * table SQL builder for the lecturer list.
 */
class lecturer_list_filterset extends filterset {
    /**
     * No required filters; both are optional URL params.
     *
     * @return array
     */
    public function get_required_filters(): array {
        return [];
    }

    /**
     * Optional filters supported by the table.
     *
     * @return array
     */
    public function get_optional_filters(): array {
        return [
            'categoryid' => integer_filter::class,
            'cohortid' => integer_filter::class,
        ];
    }
}
