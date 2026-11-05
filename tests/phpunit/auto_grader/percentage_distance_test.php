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

namespace mod_coursework;

use mod_coursework\auto_grader\percentage_distance;

/**
 * Class percentage_distance_test is responsible for testing the behaviour of the percentage_distance class.
 *
 * @package mod_coursework\auto_grader
 * @group mod_coursework
 */
final class percentage_distance_test extends \advanced_testcase {
    use \mod_coursework\test_helpers\factory_mixin;

    public function setUp(): void {
        parent::setUp();
        $this->setAdminUser();
        $this->resetAfterTest();
    }

    public function test_nothing_happens_when_there_is_already_an_agreed_feedback(): void {
        global $DB;

        $user = $this->createMock('\mod_coursework\models\user');
        $user->expects($this->any())->method('has_agreed_feedback')
            ->with($this->anything())
            ->will($this->returnValue(true));

        $object = new percentage_distance($this->get_coursework(), $user, 10);

        $object->create_auto_grade_if_rules_match();

        $this->assertEquals(0, $DB->count_records('coursework_feedbacks'));
        // reset_after_test() has not been called, so this will fail if the DB is changed.
    }

    public function test_nothing_happens_when_the_initial_feedbacks_are_not_there(): void {
        global $DB;

        $user = $this->createMock('\mod_coursework\models\user');
        $user->expects($this->any())->method('has_agreed_feedback')
            ->with($this->get_coursework())
            ->will($this->returnValue(false));

        $user->expects($this->any())->method('has_all_initial_feedbacks')
            ->with($this->get_coursework())
            ->will($this->returnValue(false));

        $object = new percentage_distance($this->get_coursework(), $user, 10);

        $object->create_auto_grade_if_rules_match($user);

        $this->assertEquals(0, $DB->count_records('coursework_feedbacks'));
    }

    /**
     * Test there is no error after student submission with:
     *   - Number of times each submission should initially be marked: 2
     *   - Sampling enabled: Yes
     *   - Automatic agreement of marks: Percentage distance
     */
    public function test_no_error_with_auto_agreement_and_sampling(): void {
        $coursework = $this->get_coursework();
        $coursework->update_attribute('samplingenabled', 1);
        $user = $this->get_student();
        $this->create_a_submission_for_the_student();
        $object = new percentage_distance($this->get_coursework(), $user, 10);

        $object->create_auto_grade_if_rules_match($user);

        $this->assertTrue(true); // Check there are no errors above.
    }

    public function test_that_a_new_record_is_created_when_all_initial_feedbacks_are_close_enough(): void {
        global $DB;

        $user = $this->createMock('\mod_coursework\models\user');
        $user->expects($this->any())->method('has_agreed_feedback')
            ->with($this->get_coursework())
            ->will($this->returnValue(false));

        $user->expects($this->any())->method('has_all_initial_feedbacks')
            ->with($this->get_coursework())
            ->will($this->returnValue(true));

        $submission = $this->createMock('\mod_coursework\models\submission');
        $user->expects($this->any())->method('get_submission')
            ->with($this->anything())
            ->will($this->returnValue($submission));

        $feedbackone = $this->createMock('\mod_coursework\models\feedback');
        $feedbackone->expects($this->any())->method('get_grade')->will($this->returnValue(50));
        $feedbackone->expects($this->any())->method('get_submission')->will($this->returnValue($submission));

        $feedbacktwo = $this->createMock('\mod_coursework\models\feedback');
        $feedbacktwo->expects($this->any())->method('get_grade')->will($this->returnValue(55));
        $feedbacktwo->expects($this->any())->method('get_submission')->will($this->returnValue($submission));

        $user->expects($this->any())->method('get_initial_feedbacks')
            ->with($this->get_coursework())
            ->will($this->returnValue([$feedbackone, $feedbacktwo]));

        $user->expects($this->any())->method('get_submission')
            ->with($this->anything())
            ->will($this->returnValue($submission));

        $object = new percentage_distance($this->get_coursework(), $user);
        // Constructor percentage_distance no longer accepts percentage param since commit c1132f6, so set 10.
        $object->set_percentage(10);
        $object->create_auto_grade_if_rules_match();

        $this->assertEquals(1, $DB->count_records('coursework_feedbacks'));
    }

    public function test_that_a_new_record_is_not_created_when_all_initial_feedbacks_are_far_apart(): void {
        global $DB;
        $user = $this->createMock('\mod_coursework\models\user');
        $user->expects($this->any())->method('has_agreed_feedback')
            ->with($this->get_coursework())
            ->will($this->returnValue(false));

        $user->expects($this->any())->method('has_all_initial_feedbacks')
            ->with($this->get_coursework())
            ->will($this->returnValue(true));

        $submission = $this->createMock('\mod_coursework\models\submission');

        $feedbackone = $this->createMock('\mod_coursework\models\feedback');
        $feedbackone->expects($this->any())->method('get_grade')->will($this->returnValue(50));
        $feedbackone->expects($this->any())->method('get_submission')->will($this->returnValue($submission));

        $feedbacktwo = $this->createMock('\mod_coursework\models\feedback');
        $feedbacktwo->expects($this->any())->method('get_grade')->will($this->returnValue(55));
        $feedbacktwo->expects($this->any())->method('get_submission')->will($this->returnValue($submission));

        $user->expects($this->any())->method('get_initial_feedbacks')
            ->with($this->get_coursework())
            ->will($this->returnValue([$feedbackone, $feedbacktwo]));

        $user->expects($this->any())->method('get_submission')
            ->with($this->anything())
            ->will($this->returnValue($submission));

        $object = new percentage_distance($this->get_coursework(), $user);
        // Constructor percentage_distance no longer accepts percentage param since commit c1132f6, so set 10.
        $object->set_percentage(10);
        $object->create_auto_grade_if_rules_match();

        $createdfeedbacks = $DB->get_records('coursework_feedbacks', [], 'id DESC', '*', 0, 1);
        $createdfeedback = reset($createdfeedbacks);
        $this->assertEquals($createdfeedback->grade ?? null, 55); // Right grade.
        $this->assertEquals($createdfeedback->stageidentifier ?? null, 'final_agreed_1'); // Right stage.
    }

    /**
     * Provider for marking guide calculation tests.
     *
     * Each case is: [marker 1's criteria marks, marker 2's criteria marks, expected agreed criteria marks,
     * expected agreed grade (null if no agreed grade expected)].
     *
     * The guide has 2 criteria, each out of 5, and the coursework is graded out of 100. So each mark is worth 10%,
     * e.g. [2, 3] is a grade of 50. The percentage distance is 10, so the markers' grades must be no more than 1 mark
     * (10%) apart for an agreed grade to be made. The agreed mark for each criterion is the highest given by either marker.
     *
     * @return array[]
     */
    public static function provider_percentage_distance_calculation_marking_guide(): array {
        return [
            'Same marks' => [[2, 3], [2, 3], [2, 3], 50],
            'Same grade - highest criteria marks come from different markers' => [[3, 2], [2, 3], [3, 3], 60],
            'Within distance - one marker higher on one criterion' => [[1, 2], [2, 2], [2, 2], 40],
            'Exactly the distance apart (50 and 60)' => [[2, 3], [3, 3], [3, 3], 60],
            'Half marks within distance (55 and 60)' => [[2.5, 3], [3, 3], [3, 3], 60],
            'Half marks within distance - highest from different markers' => [[2.5, 3.5], [3, 3], [3, 3.5], 65],
            'Full marks from both markers' => [[5, 5], [5, 5], [5, 5], 100],
            'Just over the distance apart (50 and 62.5)' => [[2, 3], [3, 3.25], [], null],
            'Too far apart (30 and 50)' => [[1, 2], [3, 2], [], null],
            'Completely different (0 and 100)' => [[0, 0], [5, 5], [], null],
        ];
    }

    /**
     * Test the agreed criteria marks are the highest of the initial markers' criteria marks, and the agreed grade is
     * calculated from them, for a coursework using a marking guide - but only when the markers' grades are within the
     * percentage distance of each other.
     *
     * @covers \mod_coursework\auto_grader\percentage_distance::create_auto_grade_if_rules_match
     * @covers \mod_coursework\auto_grader\marking_guide_calculator::calculate_and_save_criteria_marks
     * @dataProvider provider_percentage_distance_calculation_marking_guide
     * @param array $marker1grades
     * @param array $marker2grades
     * @param array $expectedfinalcriteriamarks
     * @param float|null $expectedgrade
     */
    public function test_percentage_distance_calculation_marking_guide(
        array $marker1grades,
        array $marker2grades,
        array $expectedfinalcriteriamarks,
        ?float $expectedgrade
    ): void {
        global $DB;

        // Create the coursework activity.
        $coursework = $this->create_a_coursework([
            'grade' => 100,
            'numberofmarkers' => 2,
            'automaticagreementstrategy' => 'percentage_distance',
            'automaticagreementrange' => 10,
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
            'assessor_1' => [
                'marker' => $marker1,
                'marks' => [$criterion1id => $marker1grades[0], $criterion2id => $marker1grades[1]],
            ],
            'assessor_2' => [
                'marker' => $marker2,
                'marks' => [$criterion1id => $marker2grades[0], $criterion2id => $marker2grades[1]],
            ],
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
        $autograder = new percentage_distance($coursework, $submission->get_allocatable());
        $autograder->create_auto_grade_if_rules_match();

        $finalfeedbacks = $DB->get_records('coursework_feedbacks', [
            'submissionid' => $submission->id,
            'stageidentifier' => 'final_agreed_1',
        ]);

        if ($expectedgrade === null) {
            // Grades too far apart, so there should be no agreed feedback.
            $this->assertCount(0, $finalfeedbacks);
        } else {
            // There should now be one automatic agreed final feedback.
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
    }
}
