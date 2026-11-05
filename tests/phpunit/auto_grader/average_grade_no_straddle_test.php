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
 * @copyright  2025 UCL
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_coursework;

use mod_coursework\auto_grader\average_grade_no_straddle;


/**
 * Class average_grade_no_straddle_test is responsible for testing the behaviour of the average_grade_no_straddle class.
 *
 * @package mod_coursework\auto_grader
 * @group mod_coursework
 */
final class average_grade_no_straddle_test extends \advanced_testcase {
    use \mod_coursework\test_helpers\factory_mixin;

    public function setUp(): void {
        parent::setUp();
        $this->setAdminUser();
        $coursework = $this->get_coursework();
        $coursework->update_attribute('automaticagreementstrategy', 'average_grade_no_straddle');
        // Grades within 5 percent of eachother.
        $coursework->update_attribute('automaticagreementrange', 5);
        set_config('autogradeclassboundaries', average_grade_no_straddle::get_example_setting(), 'mod_coursework');
        $this->resetAfterTest();
    }

    /**
     * Test that setting content is as expected.
     * @covers \mod_coursework\average_grade_no_straddle::get_config_setting()
     * @return void
     * @throws \core\exception\invalid_parameter_exception
     */
    public function test_get_setting(): void {
        $setting = average_grade_no_straddle::get_config_setting('autogradeclassboundaries');
        $this->assertEquals([[70, 100], [60, 69.99], [50, 59.99], [40, 49.99], [1, 39.99], [0, 0.99]], $setting);
    }

    /**
     * Data provider for test_create_auto_grade.
     *
     * @return array
     */
    public static function provider_test_create_auto_grade(): array {
        return [
            'Grades close enough and in same class - auto grade is 52.5' => [50, 55, 52.5],
            'Grades close enough and in same class - auto grade is 63.5' => [64, 67, 65.5],
            'Grades close enough and in same class - auto grade is 73' => [71, 75, 73],
            'Grades in same class, but too far apart - no auto grade' => [50, 58, null],
            'Grades too far apart and in different classes - no auto grade' => [50, 75, null],
            'Grade 101 falls outside all ranges - no auto grade' => [101, 99, null, true],
            'Grade 59.999 rounded down to 59.99 for band classification purposes - auto grade is 59.07' => [
                59.999, 58.15, round((59.99 + 58.15) / 2, average_grade_no_straddle::MAX_DECIMAL_PLACES),
            ],
        ];
    }

    /**
     * Test that auto grade is created and correct, or not created if not appropriate.
     * @param float $gradeone
     * @param float $gradetwo
     * @param float|null $expectedautograde
     * @param bool $expectoutofrange are we expecting an out of range grade?
     * @return void
     * @covers \mod_coursework\auto_grader\average_grade_no_straddle::create_auto_grade_if_rules_match
     * @dataProvider provider_test_create_auto_grade
     */
    public function test_create_auto_grade(
        float $gradeone,
        float $gradetwo,
        ?float $expectedautograde,
        bool $expectoutofrange = false
    ): void {
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
        $feedbackone->expects($this->any())->method('get_grade')->will($this->returnValue($gradeone));
        $feedbackone->expects($this->any())->method('get_submission')->will($this->returnValue($submission));

        $feedbacktwo = $this->createMock('\mod_coursework\models\feedback');
        $feedbacktwo->expects($this->any())->method('get_grade')->will($this->returnValue($gradetwo));
        $feedbacktwo->expects($this->any())->method('get_submission')->will($this->returnValue($submission));

        $user->expects($this->any())->method('get_initial_feedbacks')
            ->with($this->get_coursework())
            ->will($this->returnValue([$feedbackone, $feedbacktwo]));

        $autograder = new average_grade_no_straddle($this->get_coursework(), $user);
        $autograder->create_auto_grade_if_rules_match();
        if ($expectoutofrange) {
            $this->assertDebuggingCalled(
                "Cannot determine whether to assign agreed average grade for coursework " . $this->coursework->id
                . ". Grade '$gradeone'falls outside known class boundaries"
            );
        }

        $records = $DB->get_records(
            'coursework_feedbacks',
            // As it's auto graded, lasteditedbyuser should be zero (no user involved).
            ['stageidentifier' => 'final_agreed_1', 'isautomatic' => 1],
            'ID DESC',
            '*',
            0,
            1
        );
        if ($expectedautograde === null) {
            // Not expecting an auto graded feedback at all.
            $this->assertEquals(0, count($records));
        } else {
            $this->assertEquals($expectedautograde, reset($records)->grade ?? false);
            $this->assertEquals(1, count($records));
        }
    }

    /**
     * Test get range for percentage grades.
     * @covers \mod_coursework\auto_grader\average_grade_no_straddle::get_grade_range_index
     * @return void
     */
    public function test_get_range(): void {
        $setting = average_grade_no_straddle::get_config_setting('autogradeclassboundaries');

        // Value 101 is out of all ranges, so index should be null when using example ranges.
        $this->assertNull(average_grade_no_straddle::get_grade_range_index(101, $setting));

        // Value 90 is in range index 0 when using example ranges.
        $this->assertEquals(0, average_grade_no_straddle::get_grade_range_index(90, $setting));

        // Value 60 is in range index 1 when using example ranges.
        $this->assertEquals(1, average_grade_no_straddle::get_grade_range_index(60, $setting));

        // Value 55 is in range index 2 when using example ranges.
        $this->assertEquals(2, average_grade_no_straddle::get_grade_range_index(55, $setting));

        // Value 59.991 is in range index 2 when using example ranges (as it's rounded down to 59.99).
        $this->assertEquals(2, average_grade_no_straddle::get_grade_range_index(59.991, $setting));

        // Value 42 is in range index 3 when using example ranges.
        $this->assertEquals(3, average_grade_no_straddle::get_grade_range_index(42, $setting));

        // Value 49.999 is in range index 3 when using example ranges (as it's rounded down to 49.99).
        $this->assertEquals(3, average_grade_no_straddle::get_grade_range_index(49.999, $setting));
    }

    /**
     * Provider for marking guide calculation tests.
     * @return array[]
     */
    public static function provider_average_grade_calculation_marking_guide(): array {
        return [
            'mid - no straddle' => ['mid', [0, 1], [0, 2], [0, 1.5], 15],
            'up - no straddle' => ['up', [0, 1], [0, 2], [0, 2], 20],
            'down - no straddle' => ['down', [0, 1], [0, 2], [0, 1], 10],
            'mid - straddle' => ['mid', [0, 1], [4, 5], [], null],
            'up - straddle' => ['up', [0, 1], [4, 5], [], null],
            'down - straddle' => ['down', [0, 1], [4, 5], [], null],
        ];
    }

    /**
     * @dataProvider provider_average_grade_calculation_marking_guide
     * @param string $roundingrule
     * @param array $marker1grades
     * @param array $marker2grades
     * @param array $expectedfinalcriteriamarks
     * @param float|null $expectedgrade
     */
    public function test_average_grade_calculation_marking_guide(
        string $roundingrule,
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
            'automaticagreementstrategy' => 'average_grade_no_straddle',
            'automaticagreementrange' => 10,
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
        $autograder = new average_grade_no_straddle($coursework, $submission->get_allocatable());
        $autograder->create_auto_grade_if_rules_match();

        // There should now be one automatic agreed final feedback.
        $finalfeedbacks = $DB->get_records('coursework_feedbacks', [
            'submissionid' => $submission->id,
            'stageidentifier' => 'final_agreed_1',
        ]);

        if ($expectedgrade === null) {
            $this->assertCount(0, $finalfeedbacks);
        } else {

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
