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

namespace mod_coursework\auto_grader;

use mod_coursework\models\feedback;
use mod_coursework\models\submission;

/**
 * Calculator for Marking Guide automatically calculated grades.
 *
 * @package   mod_coursework
 * @author    Conn Warwicker <conn.warwicker@catalyst-eu.net>
 * @copyright 2026 onwards Catalyst IT EU {@link https://catalyst-eu.net}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class marking_guide_calculator {
    /**
     * Average the marking guide criterion scores given by each initial marker, save the averaged scores
     * as the marking guide filling for the final agreed feedback, and return the resulting grade.
     *
     * Criteria marks are gathered from the initial markers' feedbacks, keyed by criterion id:
     * [
     *     criterionid => [feedbackid => score, feedbackid => score],
     *     ...
     * ]
     * Each criterion is averaged (and rounded) using the auto grader's calculation, then the averages are
     * submitted as a guide instance against the final agreed feedback (stored in gradingform_guide_fillings),
     * so the agreed feedback displays the per-criterion marks the same as a manually graded one.
     *
     * Only criteria which have actually been given a score are included.
     *
     * @param submission $submission
     * @param average_grade $autograder
     * @param \stdClass|feedback $finalfeedback The final agreed feedback (must already exist in the DB).
     * @return float The final grade.
     */
    public static function calculate_and_save_criteria_marks(
        submission $submission,
        auto_grader $autograder,
        \stdClass|feedback $finalfeedback,
    ): float {
        $controller = $submission->get_coursework()->get_advanced_grading_active_controller();
        if (!$controller || $controller->get_definition()->method !== 'guide') {
            return 0;
        }

        $criteriagrades = [];
        foreach ($submission->get_assessor_feedbacks() as $feedback) {
            $instance = $controller->get_current_instance($feedback->assessorid, $feedback->id);
            if (!$instance) {
                continue;
            }

            $filling = $instance->get_guide_filling();
            foreach ($filling['criteria'] ?? [] as $criterionid => $criterion) {
                if (!isset($criterion['score']) || $criterion['score'] === '') {
                    continue;
                }
                $criteriagrades[$criterionid][$feedback->id] = (float)$criterion['score'];
            }
        }

        $avgs = [];
        foreach ($criteriagrades as $criterionid => $marks) {
            $avgs[$criterionid] = $autograder::perform_calculation(
                $submission->get_coursework(),
                $marks,
                method_exists($autograder, 'get_rounding_rule') ? $autograder->get_rounding_rule() : null,
                $submission->get_coursework()->get_grade_item()->get_decimals()
            );
        }

        if (empty($avgs)) {
            return 0;
        }

        // Build the criteria scores. These use the rounded values since we can't have point values.
        $elementvalue = ['criteria' => []];
        foreach ($avgs as $criterionid => $score) {
            $elementvalue['criteria'][$criterionid] = [
                'score' => $score,
                'remark' => '',
            ];
        }

        // Save the individual criteria marks and calculate the grade.
        $instance = $controller->get_or_create_instance(0, (int)$finalfeedback->assessorid, (int)$finalfeedback->id);
        $grade = $instance->submit_and_get_grade($elementvalue, $finalfeedback->id);

        // Return the grade to be saved.
        return $grade;
    }
}
