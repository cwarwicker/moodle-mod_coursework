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

namespace mod_coursework\task;

use core\task\scheduled_task;
use mod_coursework\mailer;
use mod_coursework\models\coursework;
use stdClass;

/**
 * Reminder notifications scheduled task.
 *
 * @package   mod_coursework
 * @author    Conn Warwicker <conn.warwicker@catalyst-eu.net>
 * @copyright 2026 onwards Catalyst IT EU {@link https://catalyst-eu.net}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reminders_task extends scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:reminders', 'mod_coursework');
    }

    #[\Override]
    public function execute(): void {
        $this->spawn_initial_deadline_reminders();
        $this->spawn_agreed_marking_deadline_reminders(5);
        $this->spawn_agreed_marking_deadline_reminders();
        $this->spawn_manager_deadline_reminders(5);
        $this->spawn_manager_deadline_reminders();
    }

    /**
     * Spawn the initial deadline reminder emails via adhoc task.
     * @param int $daythreshold Number of days ahead to look for initial marking deadlines.
     */
    private function spawn_initial_deadline_reminders(int $daythreshold = 1): void {
        global $DB;

        // Find all the coursework activities with initial marking deadlines in the next x days depending on threshold.
        $now = time();
        $max = strtotime('+ ' . $daythreshold . ' days');
        $activities = $DB->get_records_select(
            'coursework',
            'initialmarkingdeadline >= ? AND initialmarkingdeadline <= ?',
            [$now, $max],
            'id',
            'id, course'
        );

        if (!$activities) {
            return;
        }

        $data = [];
        $courseworks = [];
        $courses = [];
        $markers = [];

        // Loop through each activity and work out who needs the reminder and what stats to include in the message.
        foreach ($activities as $activity) {
            // Load activity object.
            if (!array_key_exists($activity->id, $courseworks)) {
                $courseworks[$activity->id] = \mod_coursework\models\coursework::get_from_id($activity->id);
            }

            // Load the course and its markers if we haven't come across this one yet.
            if (!array_key_exists($activity->course, $courses)) {
                $context = \core\context\course::instance($activity->course);
                $courses[$activity->course] = get_course($activity->course);
                $markers[$activity->course] = get_users_by_capability($context, 'mod/coursework:addinitialgrade');
            }

            // If either the course or the users is empty for some reason, just skip this activity.
            if (!$courses[$activity->course] || !$markers[$activity->course]) {
                continue;
            }

            // Get the reminder data for this activity.
            $activitydata = $this->get_data_for_initial_marking_deadline_reminders(
                $courseworks[$activity->id],
                $courses[$activity->course],
                $markers[$activity->course]
            );

            foreach ($activitydata as $userid => $userdata) {
                if ($userdata['unmarked'] > 0) {
                    $data[$userid][$activity->id] = $userdata;
                }
            }
        }

        // Build adhoc tasks to spawn.
        foreach ($data as $userid => $useractivitydata) {
            mailer::queue(
                'initial_marking_reminder',
                null,
                null,
                $userid,
                null,
                [
                    'data' => $useractivitydata,
                    'period' => $daythreshold,
                ]
            );
        }
    }

    /**
     * Spawn the manager deadline reminder emails via adhoc task.
     * @param int $daythreshold
     * @return void
     */
    private function spawn_manager_deadline_reminders(int $daythreshold = 1): void {
        global $DB;
        $now = time();
        $max = strtotime('+ ' . $daythreshold . ' days');

        // Get the activities that need marking or agreement reminders.
        $activities = [];
        $activities['marking'] = $DB->get_records_select(
            'coursework',
            'initialmarkingdeadline >= ? AND initialmarkingdeadline <= ?',
            [$now, $max],
            'id',
            'id, course'
        );
        $activities['agreement'] = $DB->get_records_select(
            'coursework',
            'agreedgrademarkingdeadline >= ? AND agreedgrademarkingdeadline <= ?',
            [$now, $max],
            'id',
            'id, course'
        );

        if (!$activities['marking'] && !$activities['agreement']) {
            return;
        }

        $data = [];
        $courseworks = [];
        $courses = [];
        $markers = [];
        $coursemanagers = [];

        // Loop through the activities found, both needing marking and agreement, and get the data.
        foreach ($activities as $typekey => $type) {
            foreach ($type as $activity) {
                if (!array_key_exists($activity->id, $courseworks)) {
                    $courseworks[$activity->id] = \mod_coursework\models\coursework::get_from_id($activity->id);
                }

                if (!array_key_exists($activity->course, $courses)) {
                    $context = \core\context\course::instance($activity->course);
                    $courses[$activity->course] = get_course($activity->course);
                    $markers[$activity->course] = get_users_by_capability($context, 'mod/coursework:addagreedgrade');
                    $coursemanagers[$activity->course] = get_users_by_capability($context, 'mod/coursework:moderate');
                }

                if (!$courses[$activity->course] || !$coursemanagers[$activity->course] || !$markers[$activity->course]) {
                    continue;
                }

                [$activitydata, $submissions] = $this->get_data_for_manager_marking_deadline_reminders(
                    $typekey,
                    $courseworks[$activity->id],
                    $courses[$activity->course],
                    $markers[$activity->course]
                );

                foreach ($activitydata as $userid => $userdata) {
                    if ($userdata['unmarked'] > 0) {
                        $data[$activity->course]['activities'][$activity->id][$typekey][$userid] = $userdata;
                        $data[$activity->course]['total'][$typekey] = array_sum($submissions);
                    }
                }
            }
        }

        foreach ($coursemanagers as $courseid => $managers) {
            foreach ($managers as $manager) {
                mailer::queue(
                    'manager_marking_reminder',
                    null,
                    null,
                    $manager->id,
                    null,
                    [
                        'data' => $data[$courseid],
                        'period' => $daythreshold,
                    ]
                );
            }
        }
    }

    /**
     * Get the data for the manager marking deadline reminders.
     * @param string $type
     * @param coursework $coursework
     * @param stdClass $course
     * @param array $users
     * @return array
     */
    private function get_data_for_manager_marking_deadline_reminders(string $type, coursework $coursework, stdClass $course, array $users): array {
        $return = [];
        $submissions = [];
        if ($coursework->allocationenabled) {
            if ($type == 'marking') {
                // Need to look up each user and what they need to mark still.
                foreach ($users as $user) {
                    // Get the submissions which need to be marked by this user.
                    $unmarkedsubmissions = $this->get_submissions_needing_marking_by_user($coursework->id, $user->id);

                    // Add their count to the return array for this user.
                    $return[$user->id] = [
                        'unmarked' => count($unmarkedsubmissions)
                    ];

                    // Also add the submission ids to the submissions array so we can count the unique submissions
                    // needing marking by anyone.
                    foreach ($unmarkedsubmissions as $submission) {
                        $submissions[$submission->id] = 1;
                    }

                }
            } else if ($type == 'agreement') {
                // Need to look up each user and what they need to agree still.
                foreach ($users as $user) {
                    $unagreedsubmissions = $this->get_all_submission_needing_agreement_by_user($coursework->id, $user->id);
                    $return[$user->id] = ['unmarked' => count($unagreedsubmissions)];
                    foreach ($unagreedsubmissions as $submission) {
                        $submissions[$submission->id] = 1;
                    }
                }
            }
        } else {
            // Look for all initial marking still required.
            if ($type == 'marking') {
                $cm = get_coursemodule_from_instance('coursework', $coursework->id, $course->id);
                $audit = new \mod_coursework\audit($cm->id);
                $summary = $audit->get_marking_summary();
                foreach ($users as $user) {
                    $return[$user->id] = [
                        'unmarked' => $summary['unmarked'],
                    ];
                }
                $submissions[] = $summary['unmarked'];
            } else if ($type == 'agreement') {
                $count = $this->count_all_submissions_needing_agreement($coursework->id);
                foreach ($users as $user) {
                    $return[$user->id] = ['unmarked' => $count];
                }
                $submissions[] = $count;
            }
        }
        return [$return, $submissions];
    }

    /**
     * Count the number of submissions that need agreement.
     * @param int $courseworkid
     * @return int
     */
    private function count_all_submissions_needing_agreement(int $courseworkid): int {
        global $DB;
        return $DB->count_records_sql("
            SELECT COUNT(DISTINCT s.id)
              FROM {coursework_submissions} s
             WHERE s.courseworkid = :courseworkid
                   AND s.finalisedstatus = :finalisedstatus
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {coursework_feedbacks} f2
                        WHERE f2.submissionid = s.id
                          AND f2.stageidentifier = :stageidentifier
                   )
        ", [
            'courseworkid' => $courseworkid,
            'finalisedstatus' => \mod_coursework\models\submission::FINALISED_STATUS_FINALISED,
            'stageidentifier' => 'final_agreed_1',
        ]);
    }

    /**
     * Count the number of submissions that need agreement by a given user.
     * @param int $courseworkid
     * @param int $userid
     * @return array
     */
    private function get_all_submission_needing_agreement_by_user(int $courseworkid, int $userid): array {
        global $DB;
        return $DB->get_records_sql("
                    SELECT DISTINCT s.id
                      FROM {coursework_allocation_pairs} ap
                      JOIN {coursework_submissions} s ON (
                           s.courseworkid = ap.courseworkid
                           AND s.allocatabletype = ap.allocatabletype
                           AND s.allocatableid = ap.allocatableid
                         )
                 LEFT JOIN {coursework_feedbacks} f ON (
                           f.submissionid = s.id
                           AND f.stageidentifier = :stageidentifier
                         )
                     WHERE ap.courseworkid = :courseworkid
                           AND ap.assessorid = :assessorid
                           AND f.id IS NULL
                           AND s.finalisedstatus = :finalisedstatus
                ", [
            'courseworkid' => $courseworkid,
            'assessorid' => $userid,
            'stageidentifier' => 'final_agreed_1',
            'finalisedstatus' => \mod_coursework\models\submission::FINALISED_STATUS_FINALISED,
        ]);
    }

    /**
     * Spawn the agreed-mark reminder emails via adhoc task.
     * @param int $daythreshold Number of days ahead to look for agreed marking deadlines.
     */
    private function spawn_agreed_marking_deadline_reminders(int $daythreshold = 1): void {
        global $DB;

        $now = time();
        $max = strtotime('+ ' . $daythreshold . ' days');
        $activities = $DB->get_records_select(
            'coursework',
            'agreedgrademarkingdeadline >= ? AND agreedgrademarkingdeadline <= ?',
            [$now, $max],
            'id',
            'id, course'
        );

        if (!$activities) {
            return;
        }

        $data = [];
        $courseworks = [];
        $courses = [];
        $markers = [];

        foreach ($activities as $activity) {
            if (!array_key_exists($activity->id, $courseworks)) {
                $courseworks[$activity->id] = \mod_coursework\models\coursework::get_from_id($activity->id);
            }

            if (!array_key_exists($activity->course, $courses)) {
                $context = \core\context\course::instance($activity->course);
                $courses[$activity->course] = get_course($activity->course);
                $markers[$activity->course] = get_users_by_capability($context, 'mod/coursework:addagreedgrade');
            }

            if (!$courses[$activity->course] || !$markers[$activity->course]) {
                continue;
            }

            $activitydata = $this->get_data_for_agreed_marking_deadline_reminders(
                $courseworks[$activity->id],
                $courses[$activity->course],
                $markers[$activity->course]
            );

            foreach ($activitydata as $userid => $userdata) {
                if ($userdata['unmarked'] > 0) {
                    $data[$userid][$activity->id] = $userdata;
                }
            }
        }

        foreach ($data as $userid => $useractivitydata) {
            mailer::queue(
                'agreed_marking_reminder',
                null,
                null,
                $userid,
                null,
                [
                    'data' => $useractivitydata,
                    'period' => $daythreshold,
                ]
            );
        }
    }

    /**
     * Count the number of submissions that need marking by a given user.
     * @param int $courseworkid
     * @param int $userid
     * @return int
     */
    private function get_submissions_needing_marking_by_user(int $courseworkid, int $userid): array {
        global $DB;
        return $DB->get_records_sql("
                SELECT s.id
                  FROM {coursework_allocation_pairs} ap
                  JOIN {coursework_submissions} s ON (s.courseworkid = ap.courseworkid AND s.allocatabletype = ap.allocatabletype AND s.allocatableid = ap.allocatableid)
             LEFT JOIN {coursework_feedbacks} f ON (f.submissionid = s.id AND f.stageidentifier = ap.stageidentifier)
                 WHERE ap.courseworkid = :courseworkid
                       AND ap.assessorid = :assessorid
                       AND f.id IS NULL
                       AND s.finalisedstatus = :finalisedstatus
            ", [
            'courseworkid' => $courseworkid,
            'assessorid' => $userid,
            'finalisedstatus' => \mod_coursework\models\submission::FINALISED_STATUS_FINALISED,
        ]);
    }

    /**
     * For a given activity on a course, find out which users need initial marking reminders, and for how many submissions.
     * @param coursework $coursework
     * @param stdClass $course
     * @param array $users
     * @return array
     */
    private function get_data_for_initial_marking_deadline_reminders(coursework $coursework, stdClass $course, array $users): array {
        global $DB;

        $return = [];

        // If we are using marker allocation, we want to specifically use those markers and their unmarked submissions.
        if ($coursework->allocationenabled) {
            // Find submissions still to be marked by each user.
            foreach ($users as $user) {
                $return[$user->id] = [
                    'unmarked' => count($this->get_submissions_needing_marking_by_user($coursework->id, $user->id)),
                ];
            }
        } else {
            // We can just use all the markers.
            $cm = get_coursemodule_from_instance('coursework', $coursework->id, $course->id);
            $audit = new \mod_coursework\audit($cm->id);
            $summary = $audit->get_marking_summary();
            foreach ($users as $user) {
                $return[$user->id] = [
                    'unmarked' => $summary['unmarked'],
                ];
            }
        }
        return $return;
    }

    /**
     * For a given activity on a course, find out which users need agreed-mark reminders, and for how many submissions.
     * @param coursework $coursework
     * @param stdClass $course
     * @param array $users
     * @return array
     */
    private function get_data_for_agreed_marking_deadline_reminders(coursework $coursework, stdClass $course, array $users): array {
        global $DB;

        $return = [];

        // If marker allocation is enabled, count only the ones the marker is allocated to without final agreed mark.
        if ($coursework->allocationenabled) {
            foreach ($users as $user) {
                $unmarked = $DB->count_records_sql("
                    SELECT COUNT(DISTINCT s.id) as total
                      FROM {coursework_allocation_pairs} ap
                      JOIN {coursework_submissions} s ON (
                           s.courseworkid = ap.courseworkid
                           AND s.allocatabletype = ap.allocatabletype
                           AND s.allocatableid = ap.allocatableid
                         )
                 LEFT JOIN {coursework_feedbacks} f ON (
                           f.submissionid = s.id
                           AND f.stageidentifier = :stageidentifier
                         )
                     WHERE ap.courseworkid = :courseworkid
                           AND ap.assessorid = :assessorid
                           AND f.id IS NULL
                           AND s.finalisedstatus = :finalisedstatus
                ", [
                    'courseworkid' => $coursework->id,
                    'assessorid' => $user->id,
                    'stageidentifier' => 'final_agreed_1',
                    'finalisedstatus' => \mod_coursework\models\submission::FINALISED_STATUS_FINALISED,
                ]);

                $return[$user->id] = ['unmarked' => $unmarked];
            }
            return $return;
        }

        // Otherwise, count any submissions on the coursework without the agreed final mark.
        $total = $this->count_all_submissions_needing_agreement($coursework->id);
        foreach ($users as $user) {
            $return[$user->id] = ['unmarked' => $total];
        }

        return $return;
    }
}
