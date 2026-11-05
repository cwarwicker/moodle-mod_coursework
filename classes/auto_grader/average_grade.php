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
 * @package    mod_coursework
 * @copyright  2017 University of London Computer Centre {@link https://www.cosector.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_coursework\auto_grader;

use mod_coursework\allocation\allocatable;
use mod_coursework\models\coursework;
use mod_coursework\models\feedback;
use mod_coursework\stages\final_agreed;
use mod_coursework\traits\autoagreement_functions;
use stdClass;

/**
 * Class average_grade is responsible for calculating and applying the automatically agreed grade based on the initial
 * assessor grades
 *
 * @package mod_coursework\auto_grader
 */
class average_grade implements auto_grader {
    use autoagreement_functions;

    /**
     * @var coursework
     */
    private $coursework;

    /**
     * @var
     */
    private $roundingrule;

    /**
     * @var allocatable
     */
    private $allocatable;

    /**
     * @param coursework $coursework
     * @param allocatable $allocatable
     */
    public function __construct($coursework, $allocatable) {
        $this->coursework = $coursework;
        $this->roundingrule = $this->coursework->roundingrule;
        $this->allocatable = $allocatable;
    }

    /**
     * Getter for rounding rule.
     * @return mixed|null
     */
    public function get_rounding_rule() {
        return $this->roundingrule;
    }

    /**
     * This will test whether there is a grade already present, test whether the rules for this class match the
     * state of the initial assessor grades and make an automatic grade if they do.
     *
     */
    public function create_auto_grade_if_rules_match() {
        $allocatable = $this->allocatable;

        // bounce out if conditions are not right/
        if (!$allocatable->has_all_initial_feedbacks($this->get_coursework())) {
            return;
        }
        if ($this->get_coursework()->numberofmarkers == 1) {
            return;
        }

        if (!$this->get_coursework()->uses_numeric_grade()) {
            return;
        }

        // Most advanced grading configurations can be automatically calculated, except Rubric when using same method
        // for final grade.
        if (
            $this->get_coursework()->get_advanced_grading_active_controller() &&
            in_array($this->get_coursework()->get_advanced_grading_active_controller()->get_definition()->method, [
                'rubric',
                'rubric_ranges',
            ]) &&
            $this->get_coursework()->finalstagegrading == 0)
        {
            return;
        }

        if (!$allocatable->has_agreed_feedback($this->get_coursework())) {
            $this->create_final_feedback();
        } else {
            // update only if AgreedGrade has been automatic
            $agreedfeedback = $allocatable->get_agreed_feedback($this->get_coursework());
            if ($agreedfeedback->isautomatic) {
                $this->update_final_feedback($agreedfeedback);
            }
        }

        // trigger events?
    }

    /**
     * @return coursework
     */
    private function get_coursework() {
        return $this->coursework;
    }

    /**
     * @param stdClass|feedback $finalfeedback The final agreed feedback the grade is being calculated for.
     * @return int
     * @throws \coding_exception
     */
    private function automatic_grade(stdClass|feedback $finalfeedback) {
        $controller = $this->get_coursework()->get_advanced_grading_active_controller();
        if ($controller && $controller->get_definition()->method === 'guide') {
            return marking_guide_calculator::calculate_and_save_criteria_marks(
                $this->allocatable->get_submission($this->get_coursework()),
                $this,
                $finalfeedback,
            );
        }

        // Not using a marking guide, or no criteria marks could be found - average the overall grades.
        $grades = $this->grades_as_percentages();
        return static::perform_calculation(
            $this->get_coursework(),
            $grades,
            $this->roundingrule,
            $this->get_coursework()->get_grade_item()->get_decimals()
        );
    }

    /**
     * Perform the average calculation.
     * @param coursework $coursework
     * @param array $grades
     * @param string $rounding
     * @return float|int
     */
    public static function perform_calculation(coursework $coursework, array $grades, string $rounding, int $decimals) {
        // calculate average
        $avggrade = array_sum($grades) / count($grades);

        // round it according to the chosen rule
        switch ($rounding) {
            case 'mid':
                $avggrade = round($avggrade, $decimals);
                break;
            case 'up':
                $avggrade = ceil($avggrade);
                break;
            case 'down':
                $avggrade = floor($avggrade);
                break;
        }

        return $avggrade;
    }

    /**
     *
     */
    private function create_final_feedback() {
        $feedback = [
            'stageidentifier' => final_agreed::STAGE_FINAL_AGREED_1,
            'submissionid' => $this->allocatable->get_submission($this->get_coursework())->id(),
            'lasteditedbyuser' => 0, // Grade was auto generated - zero here shows no user involved.
            'isfinalgrade' => 1, // This is an "agreed" final grade.
            'isautomatic' => 1,
        ];

        if ($this->coursework->autopopulatefeedbackcomment_enabled()) {
            $feedback['feedbackcomment'] = $this->feedback_comments();
        }

        // Create the feedback first, as advanced grading methods need its id to save their fillings against.
        $feedback = feedback::create($feedback);
        $feedback->update_attribute('grade', $this->automatic_grade($feedback));
    }

    /**
     *
     */
    private function update_final_feedback($feedback) {
        global $DB;

        $updatedfeedback = new stdClass();
        $updatedfeedback->id = $feedback->id;
        $updatedfeedback->grade = $this->automatic_grade($feedback);
        $updatedfeedback->lasteditedbyuser = 0;
        $updatedfeedback->isautomatic = 1;

        if ($this->coursework->autopopulatefeedbackcomment_enabled()) {
            $updatedfeedback->feedbackcomment = $this->feedback_comments();
        }

        $DB->update_record('coursework_feedbacks', $updatedfeedback);
    }

    /**
     * Get grades as percentages.
     * @return array
     */
    protected function grades_as_percentages() {
        $initialfeedbacks = $this->allocatable->get_initial_feedbacks($this->get_coursework());
        return array_map(function ($feedback) {
            return ($feedback->get_grade() / $this->get_coursework()->get_max_grade()) * 100;
        },
            $initialfeedbacks);
    }
}
