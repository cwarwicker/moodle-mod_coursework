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

namespace mod_coursework;

use mod_coursework\auto_grader\average_grade;

/**
 * Tests for average grade calculation
 *
 * @package   mod_coursework
 * @author    Conn Warwicker <conn.warwicker@catalyst-eu.net>
 * @copyright 2026 onwards Catalyst IT EU {@link https://catalyst-eu.net}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group     mod_coursework
 * @covers    \mod_coursework\auto_grader\average_grade
 */
final class average_grade_test extends \advanced_testcase {
    use \mod_coursework\test_helpers\factory_mixin;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Data provider for test_average_grade_calculation_simple_grading.
     *
     * Each case is: [rounding rule, marker 1's grade, marker 2's grade, expected agreed grade].
     * The coursework is graded out of 100 and the grade item uses the default of 2 decimal places.
     *
     * @return array
     */
    public static function provider_average_grade_calculation_simple_grading(): array {
        return [
            // Average is a whole number, so every rounding rule gives the same result.
            'Whole number average - mid' => ['mid', 60, 70, 65],
            'Whole number average - up' => ['up', 60, 70, 65],
            'Whole number average - down' => ['down', 60, 70, 65],

            // Average is 56.5.
            'Half mark average - mid keeps the decimal' => ['mid', 55, 58, 56.5],
            'Half mark average - up rounds to whole number above' => ['up', 55, 58, 57],
            'Half mark average - down rounds to whole number below' => ['down', 55, 58, 56],

            // Average is 56.875, which has more decimal places than the grade item allows.
            'Three decimal place average - mid rounds to 2 decimal places' => ['mid', 55.25, 58.5, 56.88],
            'Three decimal place average - up rounds to whole number above' => ['up', 55.25, 58.5, 57],
            'Three decimal place average - down rounds to whole number below' => ['down', 55.25, 58.5, 56],

            // Average is 56.125, so mid rounds half up to 2 decimal places.
            'Average ending in 5 at 3rd decimal place - mid rounds half up' => ['mid', 55.25, 57, 56.13],

            // Average is 56.15, only just above a whole number.
            'Average just above a whole number - up still rounds to whole number above' => ['up', 56.1, 56.2, 57],
        ];
    }

    /**
     * Provider for marking guide calculation tests.
     * @return array[]
     */
    public static function provider_average_grade_calculation_marking_guide(): array {
        return [
            'Whole number average - mid' => ['mid', [0, 2], [2, 4], [1, 3], 40],
            'Whole number average - down' => ['down', [0, 2], [3, 5], [1, 3], 40],
            'Whole number average - up' => ['up', [0, 2], [3, 5], [2, 4], 60],
            'Half mark average - mid' => ['mid', [0, 1], [0, 0.5], [0, 0.75], 7.5],
            'Half mark average - down' => ['down', [0, 1], [0, 0.5], [0, 0], 0],
            'Half mark average - up' => ['up', [0, 1], [0, 2.5], [0, 2], 20],
            'Three decimal place average - mid' => ['mid', [0.25, 1.25], [3.75, 4.50], [2, 2.88], 48.8],
            'Three decimal place average - down' => ['down', [0.25, 1.25], [3.75, 4.50], [2, 2], 40],
            'Three decimal place average - up' => ['up', [0.25, 1.25], [3.75, 4.50], [2, 3], 50],
        ];
    }

    /**
     * @dataProvider provider_average_grade_calculation_marking_guide
     * @param string $roundingrule
     * @param array $marker1grades
     * @param array $marker2grades
     * @param array $expectedfinalcriteriamarks
     * @param float $expectedgrade
     */
    public function test_average_grade_calculation_marking_guide(
        string $roundingrule,
        array $marker1grades,
        array $marker2grades,
        array $expectedfinalcriteriamarks,
        float $expectedgrade
    ): void {
        global $DB;

        $this->resetAfterTest(false);
        // Create the coursework activity.
        $coursework = $this->create_a_coursework([
            'grade' => 100,
            'numberofmarkers' => 2,
            'automaticagreementstrategy' => 'average_grade',
            'roundingrule' => $roundingrule,
            'advancedgradingmethod_submissions' => 'guide',
        ]);

        $guidegenerator = $this->getDataGenerator()->get_plugin_generator('gradingform_guide');
        $controller = $guidegenerator->create_instance(
            $coursework->get_context(),
            'mod_coursework',
            'submissions',
            'Test guide',
            'Test guide description',
            [
                'Criterion 1' => ['description' => 'C1', 'descriptionmarkers' => 'C1 markers', 'maxscore' => 5],
                'Criterion 2' => ['description' => 'C2', 'descriptionmarkers' => 'C2 markers', 'maxscore' => 5],
            ]
        );

        $this->assertEquals('guide', $coursework->get_advanced_grading_active_controller()->get_definition()->method);

        $submission = $this->create_a_submission_for_the_student();
        $marker1 = $this->create_a_teacher();
        $marker2 = $this->create_another_teacher();

        // Ids of the guide's criteria, so we can give a mark for each one.
        $criterion1id = $guidegenerator->get_criterion_for_values($controller, 'Criterion 1')->id;
        $criterion2id = $guidegenerator->get_criterion_for_values($controller, 'Criterion 2')->id;

        // The marks each marker gives for each criterion, keyed by stage.
        $criteriamarks = [
            'assessor_1' => ['marker' => $marker1, 'marks' => [$criterion1id => $marker1grades[0], $criterion2id => $marker1grades[1]]],
            'assessor_2' => ['marker' => $marker2, 'marks' => [$criterion1id => $marker2grades[0], $criterion2id => $marker2grades[1]]],
        ];

        // Use the coursework's controller rather than the one returned by the guide generator, as the coursework sets
        // its grade range (0 - 100). Without that, the guide can't convert the criteria marks into a grade.
        $activecontroller = $coursework->get_advanced_grading_active_controller();

        $generator = $this->get_coursework_generator();
        foreach ($criteriamarks as $stage => $data) {
            // Create the marker's initial feedback.
            $feedback = $generator->create_feedback([
                'submissionid' => $submission->id,
                'assessorid' => $data['marker']->id,
                'stageidentifier' => $stage,
            ]);

            // Save their criteria marks against the feedback, and set its grade from them,
            // in the same way as assessor_feedback_mform::process_data().
            $criteria = [];
            foreach ($data['marks'] as $criterionid => $score) {
                $criteria[$criterionid] = ['score' => $score, 'remark' => ''];
            }
            $instance = $activecontroller->get_or_create_instance(0, $feedback->assessorid, $feedback->id);
            $feedback->grade = $instance->submit_and_get_grade(['criteria' => $criteria], $feedback->id);
            $feedback->save();
        }

        // Create automatic grades.
        $autograder = new average_grade($coursework, $submission->get_allocatable());
        $autograder->create_auto_grade_if_rules_match();

        // There should now be one automatic agreed final feedback.
        $finalfeedbacks = $DB->get_records('coursework_feedbacks', [
            'submissionid' => $submission->id,
            'stageidentifier' => 'final_agreed_1',
        ]);
        $this->assertCount(1, $finalfeedbacks);
        $finalfeedback = reset($finalfeedbacks);
        $this->assertEquals(1, $finalfeedback->isautomatic);

        // Check the criteria marks automatically created for the agreed final grade.
        $finalinstance = $activecontroller->get_current_instance($finalfeedback->assessorid, $finalfeedback->id);
        $this->assertNotNull($finalinstance, 'No marking guide criteria marks were saved for the final feedback');
        $finalcriteria = $finalinstance->get_guide_filling()['criteria'];
        $this->assertEqualsWithDelta($expectedfinalcriteriamarks[0], (float)$finalcriteria[$criterion1id]['score'], 0.00001);
        $this->assertEqualsWithDelta($expectedfinalcriteriamarks[1], (float)$finalcriteria[$criterion2id]['score'], 0.00001);

        // And check the final grade itself.
        $this->assertEqualsWithDelta($expectedgrade, (float)$finalfeedback->grade, 0.00001);
    }

    /**
     * Test the agreed grade is the average of the initial markers' grades, rounded according to the coursework's
     * rounding rule setting, for a simple point graded coursework (no advanced grading method).
     *
     * @dataProvider provider_average_grade_calculation_simple_grading
     * @param string $roundingrule The coursework's rounding rule setting: mid, up or down.
     * @param float $grade1 The grade given by the first marker.
     * @param float $grade2 The grade given by the second marker.
     * @param float $expectedgrade The expected automatically agreed grade.
     * @return void
     */
    public function test_average_grade_calculation_simple_grading(
        string $roundingrule,
        float $grade1,
        float $grade2,
        float $expectedgrade
    ): void {
        global $DB;

        // Double marked, simple point graded coursework (out of 100), using automatic average grade agreement.
        $coursework = $this->create_a_coursework([
            'grade' => 100,
            'numberofmarkers' => 2,
            'automaticagreementstrategy' => 'average_grade',
            'roundingrule' => $roundingrule,
        ]);
        $this->assertFalse($coursework->get_advanced_grading_active_controller());

        $submission = $this->create_a_submission_for_the_student();
        $marker1 = $this->create_a_teacher();
        $marker2 = $this->create_another_teacher();

        // Both initial markers give their grade.
        $generator = $this->get_coursework_generator();
        $generator->create_feedback([
            'submissionid' => $submission->id,
            'assessorid' => $marker1->id,
            'stageidentifier' => 'assessor_1',
            'grade' => $grade1,
        ]);
        $generator->create_feedback([
            'submissionid' => $submission->id,
            'assessorid' => $marker2->id,
            'stageidentifier' => 'assessor_2',
            'grade' => $grade2,
        ]);

        // Run the auto grader, as happens after a marker saves their feedback.
        $autograder = new average_grade($coursework, $submission->get_allocatable());
        $autograder->create_auto_grade_if_rules_match();

        // There should now be one automatic agreed feedback, with the averaged and rounded grade.
        $finalfeedbacks = $DB->get_records('coursework_feedbacks', [
            'submissionid' => $submission->id,
            'stageidentifier' => 'final_agreed_1',
        ]);
        $this->assertCount(1, $finalfeedbacks);
        $finalfeedback = reset($finalfeedbacks);
        $this->assertEquals(1, $finalfeedback->isautomatic);
        $this->assertEqualsWithDelta($expectedgrade, (float)$finalfeedback->grade, 0.00001);
    }
}
