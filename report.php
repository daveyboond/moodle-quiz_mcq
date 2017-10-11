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
 * This file defines the quiz MCQ breakdown report class.
 *
 * @package    quiz
 * @subpackage mcq
 * @copyright  2012 Steve Bond
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Variables reference:
 * $allattempts - all attempts on this quiz
 * $alluserscapable - array of user objects for users who can attempt quizzes
 * $attempts - attempts on quiz belonging to eligible users (after filtering)
 * $grades - array of total grade for the quiz for each user
 * $groupids - group IDs passed as parameters (0 = whole cohort/grouping)
 * $maxoptions - maximum number of options in any one question
 * $noofuserattempts - count of the $userattempts array
 * $noofuserscorrect - array of number of users getting each question correct
 * $optioncounts - array of counts of users choosing each option
 * $optiondata - array of arrays for each user, containing user details and what options they chose in each question
 * $options - array of objects (with id and fraction properties) representing each option (answer) for each question
 * $regusers - array of user objects for all users in course with role Student
 * $uncompletedusers - array of user objects for users who have not completed an attempt
 * $userattemptidlist - comma-separated list of all attempt IDs selected
 * $userattempts - array of most-relevant attempts, by user ID
 * $users - array of user objects for users who are in current sample
 * $usersgroups - 2D array of groups and groupings for given user/course
 * $warnings - array of warning messages generated when selecting which attempts to use
 */

defined('MOODLE_INTERNAL') || die();

class quiz_mcq_report extends quiz_default_report {

    protected $cm;
    protected $quiz;
    protected $context;
    protected $course;

    public function display($quiz, $cm, $course) {
        global $CFG, $DB, $PAGE;

        // Get parameters
        $groupids = optional_param_array('groupid', array(), PARAM_INT);
        $filter = optional_param('filter', 2, PARAM_INT);
        $sort = optional_param('sort', 1, PARAM_INT);
        $highlightcorrect = optional_param('highlightcorrect', 1, PARAM_INT);
        $print = optional_param('print', 0, PARAM_INT);

        // Set appropriate page layout if necessary
        if ($print == 1) {
            $PAGE->set_pagelayout('base');
        }

        // Get context
        $context = context_module::instance($cm->id);
        $reporturl = $CFG->wwwroot.'/mod/quiz/report.php';

        // Start output.
        $this->print_header_and_tabs($cm, $course, $quiz, 'mcq');

        // Get list of groups applicable to this quiz. Use 0 as the ID for the whole course/whole grouping option.
        // Start the group name arrays.
        $groupnames = array();
        if ($cm->groupingid > 0) {
            $groups = groups_get_all_groups($course->id, 0, $cm->groupingid);
            array_push($groupnames, array(0, get_string('wholegrouping', 'quiz_mcq')));
        } else {
            $groups = groups_get_all_groups($course->id);
            array_push($groupnames, array(0, get_string('wholecourse', 'quiz_mcq')));
        }

        // Fill up the arrays of group names
        foreach ($groups as $group) {
            array_push($groupnames, array($group->id, $group->name));
        }

        // Remove the first element, sort by name, then put the first element back.
        // Using the 'lastname_cmp' comparison to sort on the [1] column of $groupnames
        $whole = array_shift($groupnames);
        usort($groupnames, array($this, 'lastname_cmp'));
        array_unshift($groupnames, $whole);

        // If no group IDs were passed as a parameter, set the default group ID to be the first group,
        //  if there is one. This saves us always having to display data for the whole cohort, which
        //  may be very large.
        if (count($groupids) == 0) {
            if (count($groups) > 0) {
                $allgroupids = array_keys($groups);
                $groupids[] = $allgroupids[0];
            } else {
                $groupids[] = 0;
            }
        }

        // Create list of filters. Filter 1 has been removed as the report now only includes users who
        // can attempt quiz (i.e. no Teachers)
        $filternames = array();
        array_push($filternames, array(0, get_string('allusers', 'quiz_mcq')));
        array_push($filternames, array(2, get_string('regstudents', 'quiz_mcq')));

        // Get maximum grade possible
        $maxposs = $quiz->grade;

        // *Updated for Moodle 2.7* Get question IDs for this quiz. Use the reportlib function
        // quiz_report_get_significant_questions to exclude description questions.
        // Question order is slot order (that in which they are ordered in the quiz by the editor).
        $allrealquestionids = array();
        $quizquestions = quiz_report_get_significant_questions($quiz);
        foreach ($quizquestions as $quizquestion) {
            $allrealquestionids[] = $quizquestion->id;
        }

        // Create an array of the multiple choice questions only, together with a flag to indicate
        // single-answer questions in the original question order. Create a comma-separated list of the IDs.
        $mcqids = array();
        $mcqidlist = '';
        $noofungradedquestions = 0;
        for ($q = 0; $q < count($allrealquestionids); $q++) {

            $question = $DB->get_record_sql('
                SELECT q.*, qc.contextid
                FROM {question} q
                JOIN {question_categories} qc ON qc.id = q.category
                WHERE q.id = ?', array($allrealquestionids[$q]));

            // Is this a single-answer question?
            $single = $DB->get_record('qtype_multichoice_options',
                array('questionid' => $allrealquestionids[$q]), 'single');

            if ($question->qtype == 'multichoice' or $question->qtype == 'truefalse') {
                $mcqids[$q] = array($allrealquestionids[$q], $single->single);
                $mcqidlist .= $allrealquestionids[$q] . ',';
            } else {
                if ($question->defaultmark == 0) {
                    // Count the ungraded, non-MCQ questions
                    $noofungradedquestions++;
                }
            }
        }
        $noofmcqs = count($mcqids);
        $mcqidlist = trim($mcqidlist, ',');
        $mcqkeys = array_keys($mcqids); // Just to save calling this function every time we need to loop

        // Check number of MCQs and set warnings
        $warnings = array();
        $nodata = false;
        if ($noofmcqs == 0) {
            $warnings[] = get_string('warningnomcq', 'quiz_mcq');
            $nodata = true;
        } else if (($noofmcqs + $noofungradedquestions) < count($allrealquestionids)) {
            $warnings[] = get_string('warningnotallmcq', 'quiz_mcq');
        }

        // Get IDs for registered students, i.e. those with the "Student" role [roleid = 5], as opposed to
        // other student-type roles such as "auditing student" (used at LSE)
        $regusers = get_role_users(5, $context, true);

        // Get all users on this course who can attempt quizzes, and all completed attempts on this quiz
        $alluserscapable = get_users_by_capability($context,
            'mod/quiz:attempt', 'u.id, u.firstname, u.lastname', 'lastname ASC');
        $allattempts = $DB->get_records_sql('SELECT * FROM {quiz_attempts} qa WHERE qa.state = "finished" '
            . 'AND qa.quiz = ' . $quiz->id);
        $users = array();
        $attempts = array();

        // For each user, check whether they should be included in the list of eligible participants
        foreach ($alluserscapable as $user) {

            // Initial assumption is that user is to be included
            $adduser = true;

            // If filtered by group, only include users from selected groups. ($groupids contains 0 if
            // the "whole course" filter was selected, or if no group was passed and there are no groups set up.)
            if (!in_array(0, $groupids)) {
                $adduser = false;
                foreach ($groupids as $gid) {
                    if (groups_is_member($gid, $user->id)) {
                        $adduser = true;
                    }
                }
            } else {
                // If not filtered by group, we still need to exclude users who cannot view this quiz.
                // Start with 2D array of groups and groupings for this user. Array element 0 is always present
                // and represents "whole course". If this element is empty then there are no groups for this user.
                $usersgroups = groups_get_user_groups($course->id, $user->id);

                // If the quiz is restricted to group members only, then only include a user if: they are in
                // a group, and they are in any grouping that has been specified.
                if ($cm->groupmembersonly) {
                    if (!(empty($usersgroups[0]))
                        and (array_key_exists($cm->groupingid, $usersgroups) or ($cm->groupingid == 0))) {

                        $adduser = true;
                    } else {
                        $adduser = false;
                    }
                } else {
                    $adduser = true;
                }

            }

            // Final filter to control for user role. (Note the $filter == 1 "all students" option is removed, as
            // we now select only those users who can attempt the quiz i.e. no teacher previews included).
            if ($filter == 2) {
                // If registered students filter is on, remove users without registered student role
                if (!in_array($user->id, array_keys($regusers))) {
                    $adduser = false;
                }
            }

            // Add user if eligible
            if ($adduser) {
                array_push($users, $user);
            }

        }

        // Extract the quiz attempts belonging to the eligible users
        foreach ($allattempts as $attempt) {
            foreach ($users as $user) {
                if ($attempt->userid == $user->id) {
                    array_push($attempts, $attempt);
                }
            }
        }

        // Match each user to a single attempt, according to the grading method being used
        $userattempts = array();
        $userattemptidlist = '';

        switch ($quiz->grademethod) {
            case 1:
                // Highest grade: Identify highest-graded attempt for each user
                foreach ($attempts as $attempt) {
                    $userid = $attempt->userid;
                    if (empty($userattempts[$userid]) or $attempt->sumgrades > $userattempts[$userid]->sumgrades) {
                        $userattempts[$userid] = $attempt;
                        $userattemptidlist .= $attempt->uniqueid . ',';
                    }
                }
                break;
            case 3:
                // First attempt: Use first attempt
                foreach ($attempts as $attempt) {
                    $userid = $attempt->userid;
                    if ($attempt->attempt == 1) {
                        $userattempts[$userid] = $attempt;
                        $userattemptidlist .= $attempt->uniqueid . ',';
                    }
                }
                break;
            case 2:
                // Average grade: Set warning and carry on to use last attempt
                $warnings[] = get_string('warningaverage', 'quiz_mcq');
            case 4:
                // Last attempt: Use last attempt
                foreach ($attempts as $attempt) {
                    $userid = $attempt->userid;
                    if (empty($userattempts[$userid]) or $attempt->attempt > $userattempts[$userid]->attempt) {
                        $userattempts[$userid] = $attempt;
                        $userattemptidlist .= $attempt->uniqueid . ',';
                    }
                }
            break;
        }

        $userattemptidlist = trim($userattemptidlist, ',');

        // Get a list of users who have not yet completed an attempt
        $uncompletedusers = array();
        foreach ($users as $user) {
            // Initially assume user has not completed (seems clumsy but it is the best way I think)
            array_push($uncompletedusers, $user);
            foreach ($userattempts as $userattempt) {
                if ($userattempt->userid == $user->id) {
                    // If an attempt is found remove the user, and jump to next one
                    array_pop($uncompletedusers);
                    break;
                }
            }
        }

        // Loop through questions and make an array of option objects sorted by ID. The sorting represents
        // the unshuffled option order for each question.
        $options = array();
        $optioncounts = array();
        $maxoptions = 0;
        $fullycorrect = array();
        $anycorrect = array();
        foreach ($mcqkeys as $q) {

            // Function get_records_sql returns an array with IDs as keys, but we need a zero-based array so use array_values
            $options[$q] = array_values($DB->get_records_sql('SELECT id, fraction FROM {question_answers} qans '
                . 'WHERE qans.question = ' . $mcqids[$q][0] . ' ORDER BY id ASC'));

            // Set up the option counts array with zeroes
            $optioncounts[$q] = array_fill(0, count($options[$q]) + 1, 0);

            // Record the largest number of options in one question
            $maxoptions = max($maxoptions, count($options[$q]));

            // Also create arrays of correct options (represented by unshuffled indices). The "fullycorrect" option list
            // contains those worth 100%. The "anycorrect" options are those worth > 0%.
            $fullycorrect[$q] = array();
            $anycorrect[$q] = array();
            for ($opt = 0; $opt < count($options[$q]); $opt++) {
                if ($options[$q][$opt]->fraction > 0) {
                    array_push($anycorrect[$q], ($opt + 1));
                    if ($options[$q][$opt]->fraction == 1) {
                        array_push($fullycorrect[$q], ($opt + 1));
                    }
                }
            }

        }

        // Make the attempts array zero-based array ($userattempts uses user IDs as keys,
        // which was useful when constructing it, but will be a pain later)
        $userattempts = array_values($userattempts);
        $noofuserattempts = count($userattempts);

        // Construct user data array as follows: userid, lastname, firstname, grade, q1 options, q2 options etc.
        // Loop through user attempts and get names and grades, then loop through questions and get option(s) chosen
        $optiondata = array();
        $grades = array();
        $noofuserscorrect = array_fill_keys($mcqkeys, 0);

        for ($u = 0; $u < $noofuserattempts; $u++) {

            // Get user's record
            $user = $DB->get_record('user', array('id' => $userattempts[$u]->userid));

            // Add user ID, last name and first name to this row
            $optiondatarow = array($user->id, $user->lastname, $user->firstname);

            // Calculate grade achieved and add it to this row and to grades array. Grades are stored as a
            // proportion of the sumgrades values (i.e. the sum of question weights in the quiz, which is
            // then scaled up to give the actual grade)
            $grade = round($quiz->grade * $userattempts[$u]->sumgrades / $quiz->sumgrades, $quiz->decimalpoints);
            array_push($grades, $grade);
            array_push($optiondatarow, $grade);

            // Create an array item for the number of questions attempted in this quiz, to be populated later
            $noofqattempts = 0;
            array_push($optiondatarow, $noofqattempts);

            // All changed in Moodle 2 from here. No more use of question_states table.

            // Loop through questions
            foreach ($mcqkeys as $q) {

                // Set a flag for True/False questions
                $qtype = $DB->get_record('question', array('id' => $mcqids[$q][0]), 'qtype');
                $truefalse = ($qtype->qtype == 'truefalse' ? 1 : 0);

                // Get the user's attempt at this question
                $qattempt = $DB->get_record_select('question_attempts', 'questionusageid = '
                    . $userattempts[$u]->uniqueid . ' AND questionid = ' . $mcqids[$q][0]);

                // Get the answer ids in the order they were displayed in this attempt
                $qattemptorder = $DB->get_record_sql('SELECT qasd.value FROM {question_attempt_step_data} qasd,
                    {question_attempt_steps} qas WHERE qas.questionattemptid = ' . $qattempt->id
                        . ' AND qas.state = "todo" AND qasd.attemptstepid = qas.id AND qasd.name = "_order"');

                // Get the attempt step corresponding to the final submitted answer
                $qattemptsubmitstep = $DB->get_record_sql('SELECT qas.id FROM {question_attempt_steps} qas'
                    . ' WHERE qas.questionattemptid = ' . $qattempt->id
                    . ' AND qas.state = "complete" AND qas.sequencenumber ='
                    . ' (SELECT max(qas2.sequencenumber) FROM {question_attempt_steps} qas2'
                    . ' WHERE qas2.questionattemptid = qas.questionattemptid AND qas2.state = qas.state)');

                if ($qattemptsubmitstep) {
                    // Get the index of the answer selected in this attempt (for single-answer MCQs)
                    // The value retrieved here represents the index of the chosen answer in the list $attempt_order->value
                    $qattemptanswer = $DB->get_record_sql('SELECT qasd.value FROM {question_attempt_step_data} qasd'
                        . ' WHERE qasd.attemptstepid = ' . $qattemptsubmitstep->id . ' AND qasd.name = "answer"');

                    // Get the indices of the answers selected in this attempt (for multiple-answer MCQs)
                    $qattemptchoices = $DB->get_records_sql('SELECT qasd.name, qasd.value'
                        . ' FROM {question_attempt_step_data} qasd'
                        . ' WHERE qasd.attemptstepid = ' . $qattemptsubmitstep->id
                        . ' AND qasd.name LIKE "choice%"');
                } else {
                    // Unanswered
                    $qattemptanswer = false;
                    $qattemptchoices = array();
                }

                // Change list of answer ids into array
                if ($qattemptorder) {
                    $optionids = explode(',', $qattemptorder->value);
                }

                $optionschosen = array();

                if ($qattemptanswer) {
                    if (!$qattemptorder) {
                        // True/false question, no order provided, 'value' is Boolean
                        $optionschosen[] = ($qattemptanswer->value == 1 ? 1 : 2);
                    } else {
                        // Convert response indices into answer ids
                        $optionschosen[] = $optionids[$qattemptanswer->value];
                    }
                } else if (count($qattemptchoices) > 0) {
                    // Multiple-answer question
                    foreach ($qattemptchoices as $choice) {
                        $idx = str_replace('choice', '', $choice->name);
                        if ($choice->value > 0) {
                            $optionschosen[] = $optionids[$idx];
                        }
                    }
                } else {
                    // If all else fails (because the quiz data is restored from backup so there is no step data),
                    // simply compare the response string with the answers in the database. Note we have to strip
                    // HTML and whitespace because question_answers and question_attempts store strings differently.
                    $qanswers = $DB->get_records_select('question_answers', 'question = '
                        . $mcqids[$q][0]);
                    $optionstrings = array_map("trim", explode('; ', $qattempt->responsesummary));
                    foreach ($qanswers as $qans) {
                        if (in_array(trim(html_to_text($qans->answer)), $optionstrings)) {
                            $optionschosen[] = $qans->id;
                        }
                    }
                }

                // If any options were chosen, increment the question attempts counter
                if (count($optionschosen) > 0) {
                    $noofqattempts++;
                }

                // Important: the options may be shuffled for the student. We need to represent the options chosen
                // according to their *unshuffled* order if the results are to be comparable between students. The
                // unshuffled order is that in which they are listed when editing the question, and is given by the
                // order of the option IDs. So, here we map the chosen option IDs to their (1-based) indices in the
                // unshuffled order.
                for ($oc = 0; $oc < count($optionschosen); $oc++) {
                    for ($opt = 0; $opt < count($options[$q]); $opt++) {
                        if ($optionschosen[$oc] == $options[$q][$opt]->id) {
                            $optionschosen[$oc] = $opt + 1;
                            break;
                        }
                    }
                }

                // Sort the chosen option indices
                sort($optionschosen);

                // If user chose no options, increment zeroth element of optioncounts and increment the
                // non-choosers counter. Otherwise loop through the options chosen and increment each
                // corresponding element in optioncounts
                if (count($optionschosen) == 0) {
                    $optioncounts[$q][0]++;
                } else {
                    for ($opt = 0; $opt < count($optionschosen); $opt++) {
                        $optioncounts[$q][$optionschosen[$opt]]++;
                    }
                }

                // Check if the array of options chosen matches the correct answer.
                // If so, increment the correct counter for this question. Add a flag to
                // the end of the string to indicate correctness (0 = wrong, 1 = correct, 2= partially correct).
                // Finally add it to the data row for this user.
                $flag = 0;
                if ($mcqids[$q][1] == 1) {
                    // Single-answer question correct if one or more 100% answers chosen, partially correct if
                    // one or more > 0 answers chosen
                    if (count(array_intersect($optionschosen, $fullycorrect[$q])) > 0) {
                        $flag = 1;
                        $noofuserscorrect[$q]++;
                    } else if (count(array_intersect($optionschosen, $anycorrect[$q])) > 0) {
                        $flag = 2;
                    }
                } else {
                    // Multiple-answer question correct if all > 0 answers chosen, partially correct if one or
                    // more > 0 answers chosen
                    if ($optionschosen == $anycorrect[$q]) {
                        $flag = 1;
                        $noofuserscorrect[$q]++;
                    } else if (count(array_intersect($optionschosen, $anycorrect[$q])) > 0) {
                        $flag = 2;
                    }
                }

                // Convert the options chosen into a string (this may prove unnecessary...), and add the flag on the end
                $optionschosenlist = implode(',', $optionschosen);
                $optionschosenlist .= "|$flag";
                array_push($optiondatarow, $optionschosenlist);

            }

            // Update the question attempts counter with the correct number
            $optiondatarow[4] = $noofqattempts;

            // Row is complete, so add it to the user data array
            array_push($optiondata, $optiondatarow);

        }

        // Sort the user data array according to the $sort parameter. Note that the callback has to
        // be passed as an array because the function is a method of this class. Also set the sort arguments
        // for next time a column is clicked
        $sortarg1 = 1;
        $sortarg2 = 2;
        $sortarg3 = 3;
        $sortarg4 = 4;
        switch ($sort) {
            case 1:
                // Sort by lastname asc
                usort($optiondata, array($this, 'lastname_cmp'));
                $sortarg1 = -1;
                break;
            case 2:
                // Sort by firstname asc
                usort($optiondata, array($this, 'firstname_cmp'));
                $sortarg2 = -2;
                break;
            case 3:
                // Sort by grade DESC (as this is the natural starting order)
                usort($optiondata, array($this, 'grade_cmp'));
                $sortarg3 = -3;
                $optiondata = array_reverse($optiondata);
                break;
            case 4:
                // Sort by number of attempts DESC
                usort($optiondata, array($this, 'qattempts_cmp'));
                $sortarg4 = -4;
                $optiondata = array_reverse($optiondata);
                break;
            case -1:
                // Sort by lastname desc
                usort($optiondata, array($this, 'lastname_cmp'));
                $optiondata = array_reverse($optiondata);
            case -2:
                // Sort by firstname desc
                usort($optiondata, array($this, 'firstname_cmp'));
                $optiondata = array_reverse($optiondata);
                break;
            case -3:
                // Sort by grade asc
                usort($optiondata, array($this, 'grade_cmp'));
                break;
            case -4:
                // Sort by number of attempts asc
                usort($optiondata, array($this, 'qattempts_cmp'));
        }

        // Sort the grades, calculate max, mean and median
        sort($grades, SORT_NUMERIC);
        if ($noofuserattempts > 0) {
            $maxgrade = $grades[$noofuserattempts - 1];
            $mean = round(array_sum($grades) / $noofuserattempts, 1);
            if ($noofuserattempts % 2) {
                $median = $grades[floor($noofuserattempts / 2)];
            } else {
                $median = round(($grades[$noofuserattempts / 2] + $grades[$noofuserattempts / 2 - 1]) / 2, 1);
            }
        } else {
            $maxgrade = 0;
            $mean = 0;
            $median = 0;
        }

        // Output
        if ($print == 1) {
            $this->output_print($warnings, $nodata, $quiz, $reporturl, $groupnames, $filternames, $noofuserattempts,
                $uncompletedusers, $maxposs, $maxgrade, $median, $mean, $highlightcorrect, $noofmcqs,
                $sortarg1, $sortarg2, $sortarg3, $sortarg4, $fullycorrect, $anycorrect, $noofuserscorrect, $optioncounts,
                $optiondata, $mcqids, $maxoptions, $groupids, $filter);
        } else {
            $this->output_screen($warnings, $nodata, $quiz, $reporturl, $groupnames, $filternames, $noofuserattempts,
                $uncompletedusers, $maxposs, $maxgrade, $median, $mean, $highlightcorrect, $noofmcqs,
                $sortarg1, $sortarg2, $sortarg3, $sortarg4, $fullycorrect, $anycorrect, $noofuserscorrect, $optioncounts,
                $optiondata, $mcqids, $maxoptions, $groupids, $filter);
        }

        return true;
    }


    // Output for screen
    protected function output_screen ($warnings, $nodata, $quiz, $reporturl, $groupnames, $filternames, $noofuserattempts,
            $uncompletedusers, $maxposs, $maxgrade, $median, $mean, $highlightcorrect, $noofmcqs,
            $sortarg1, $sortarg2, $sortarg3, $sortarg4, $fullycorrect, $anycorrect, $noofuserscorrect, $optioncounts,
            $optiondata, $mcqids, $maxoptions, $groupids, $filter) {

        $mcqkeys = array_keys($mcqids); // Reassign since we didn't pass it

        // Calculate some local values
        $noofuncompletedusers = count($uncompletedusers);
        $noofusers = $noofuserattempts + $noofuncompletedusers;

        // Avoid division by zero later on
        if ($noofusers == 0) {
            $noofusers = 1;
        }

        // Output: Warnings, if any
        while (count($warnings) > 0) {
            echo '<p class="mcq warningcell"><span class="warningheader">' . get_string('warning', 'quiz_mcq')
                . ': </span>' . array_shift($warnings) . '</p>';
        }

        // Abort if nothing to display
        if ($nodata) {
            return true;
        }

        // Output: Quiz close date and link to print view
        $quizclose = $quiz->timeclose ? userdate($quiz->timeclose) : get_string('never', 'quiz_mcq');
        $groupidshttp = '';
        foreach ($groupids as $gid) {
            $groupidshttp .= "&groupid[]=$gid";
        }

        echo '<table width="100%"><tr><td class="mcq date"><span class="mcq subheading">'
            . get_string('quizcloses', 'quiz_mcq') . ':</span> ' . $quizclose . '</td>'
            . '<td align="right"><a href="' . qualified_me()
            . '&print=1' . $groupidshttp . '" target="_blank">'
            . get_string('printview', 'quiz_mcq') . '</a></td></tr></table>';

        // Output: Sample drop-down
        echo '<form id="controlform" method="get" action="' . $reporturl . '">';
        echo '<input type="hidden" name="mode" value="mcq" />';
        echo '<input type="hidden" name="q" value="' . $quiz->id . '" />';
        echo '<table><tr><td>';
        echo '<span class="mcq subheading">' . get_string('sample', 'quiz_mcq') . ':</span>';
        echo '</td><td>';
        echo '<span class="mcq subheading">' . get_string('filter', 'quiz_mcq') . ':</span>';
        echo '</td></tr><tr><td>';
        echo '<select name="groupid[]" multiple size="5" onchange="document.forms[\'controlform\'].submit();">';
        foreach ($groupnames as $groupname) {
            if (in_array($groupname[0], $groupids)) {
                $sel = ' selected';
            } else {
                $sel = '';
            }
            echo '<option value="' . $groupname[0] . '"' . $sel . '>' . $groupname[1] . '</option>';
        }
        echo '</select><br />';
        echo '</td><td valign="top">';

        // Output: Filter drop-down
        echo '<select name="filter" onchange="document.forms[\'controlform\'].submit();">';
        foreach ($filternames as $filtername) {
            if ($filtername[0] == $filter) {
                $sel = ' selected';
            } else {
                $sel = '';
            }
            echo '<option value="' . $filtername[0] . '"' . $sel . '>' . $filtername[1] . '</option>';
        }
        echo '</select>';
        echo '</td></tr></table>';

        // Output: Sample summary table.
        echo '<p class="mcq subheading">' . get_string('samplesummary', 'quiz_mcq') . ':</p>';

        echo '<table id="summary" class="generaltable mcq summarytable">';
        echo '<tbody>';
        echo '<tr>';
        echo '<td>' . get_string('userscompleting', 'quiz_mcq') . '</td>';
        echo '<td><div align="right">' . $noofuserattempts . ' (' . round(100 * $noofuserattempts / $noofusers)
            . '%)</div></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>' . get_string('usersyettocomplete', 'quiz_mcq') . '</td>';
        echo '<td><div align="right">' . $noofuncompletedusers . ' (' . round(100 * $noofuncompletedusers / $noofusers)
            . '%)</div></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>' . get_string('maxgrade', 'quiz_mcq') . ' ('
            . get_string('maxposs', 'quiz_mcq') . ' = ' . $maxposs . ')</td>';
        echo '<td><div align="right">' . $maxgrade . ' (' . round(100 * $maxgrade / $maxposs) . '%)</div></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>' . get_string('mediangrade', 'quiz_mcq') . '</td>';
        echo '<td><div align="right">' . $median . ' (' . round(100 * $median / $maxposs) . '%)</div></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>' . get_string('meangrade', 'quiz_mcq') . '</td>';
        echo '<td><div align="right">' . $mean . ' (' . round(100 * $mean / $maxposs) . '%)</div></td>';
        echo '</tr>';
        echo '</tbody>';
        echo "</table>\n";

        // Output: Key
        echo '<p class="mcq subheading"><a href="' . qualified_me() . '&highlightcorrect='
            . abs(1 - $highlightcorrect) . '">' . get_string('key', 'quiz_mcq') . '</a>:</p> ';

        echo '<table id="key" class="generaltable mcq keytable">';
        echo '<tbody>';
        echo '<tr>';
        if ($highlightcorrect == 1) {
            echo '<td class="correct">' . get_string('correctoptions', 'quiz_mcq') . '</td>';
        } else {
            echo '<td class="correct">' . get_string('incorrectoptions', 'quiz_mcq') . '</td>';
        }
        echo '<td class="partial">' . get_string('partialoption', 'quiz_mcq') . '</td>';
        echo '<td class="unanswered">' . get_string('unanswered', 'quiz_mcq') . '</td>';
        echo '</tr>';
        echo '</tbody>';
        echo "</table>\n";

        // Output: 'Options chosen' and 'Option counts' table
        echo '<table id="options" class="generaltable mcq optionstable">';
        // Label
        echo '<tbody>';
        echo '<tr><th colspan="' . ($noofmcqs + 4) . '" class="subheading">&nbsp;</th></tr><tr>';
        echo '<th colspan="' . ($noofmcqs + 4) . '" class="subheading">'
           . get_string('optionschosen', 'quiz_mcq') . ':<br /></th>';
        echo '</tr>';
        // Headers
        echo '<tr>';
        echo '<th class="header"><a href="' . qualified_me() . '&sort=' . $sortarg1 . '">'
            . get_string('surname', 'quiz_mcq') . '</a></th>'
            . '<th class="header"><a href="' . qualified_me() . '&sort=' . $sortarg2 . '">'
            . get_string('firstname', 'quiz_mcq') . '</a></th>'
            . '<th class="header"><a href="' . qualified_me() . '&sort=' . $sortarg3 . '">'
            . get_string('grade', 'quiz_mcq') . '</a> (%)</th>'
            . '<th class="header"><a href="' . qualified_me() . '&sort=' . $sortarg4 . '">'
            . get_string('qattempts', 'quiz_mcq') . '</a> (%)</th>';

        foreach ($mcqkeys as $q) {
            echo '<th class="header">' . get_string('questionabbr', 'quiz_mcq') . ($q + 1) . '</th>';
        }
        echo '</tr>';
        // Summary rows
        echo '<tr>';
        echo '<td colspan="4" class="subsubheading">' . get_string('correctoptions', 'quiz_mcq') . '</th>';
        foreach ($mcqkeys as $q) {

            if ($mcqids[$q][1] == 1) {
                $correctstring = implode(',', $fullycorrect[$q]);
            } else {
                $correctstring = implode(',', $anycorrect[$q]);
            }

            if ($highlightcorrect == 1) {
                echo '<td class="data numeric correct">' . $correctstring . '</td>';
            } else {
                echo '<td class="data numeric">' . $correctstring . '</td>';
            }
        }
        echo '</tr>';
        echo '<tr>';
        echo '<td colspan="4" class="subsubheading">' . get_string('totalcorrect', 'quiz_mcq') . '</th>';
        foreach ($mcqkeys as $q) {
            $noofcompleters = $noofuserattempts - $optioncounts[$q][0];
            // Avoid division by zero
            if ($noofcompleters == 0) {
                $noofcompleters = 1;
            }
            echo '<td class="data numeric">' . $noofuserscorrect[$q]
                . ' (' . round(100 * $noofuserscorrect[$q] / $noofcompleters) . '%)' . '</td>';
        }
        echo '</tr>';
        echo '<tr>';
        echo '<td colspan="4" class="subsubheading">' . get_string('totalchoosing', 'quiz_mcq') . '</th>';
        foreach ($mcqkeys as $q) {
            echo '<td class="data numeric">'. ($noofuserattempts - $optioncounts[$q][0]) .'</td>';
        }
        echo '</tr>';
        // User data
        foreach ($optiondata as $optiondatarow) {
            echo '<tr>';
            // Output the first 4 columns individually and start the loop at 5
            echo '<td>' . $optiondatarow[1] . '</td>';
            echo '<td>' . $optiondatarow[2] . '</td>';
            echo '<td class="numeric">' . $optiondatarow[3]
                . ' ('. round(100 * $optiondatarow[3] / $maxposs).'%)</td>';
            echo '<td class="numeric">' . $optiondatarow[4]
                . ' (' . round(100 * $optiondatarow[4] / $noofmcqs) . '%)</td>';

            for ($col = 5; $col < count($optiondatarow); $col++) {

                $correctclass = ' class="numeric"';

                // Check whether the last character is a flag, and if so use it to determine the cell
                // class (correct or not, or unanswered)
                if (substr($optiondatarow[$col], -2, 1) == '|') {
                    if (substr($optiondatarow[$col], -1) == $highlightcorrect) {
                           $correctclass = ' class="numeric correct"';
                    } else if (substr($optiondatarow[$col], -1) == 2) {
                           $correctclass = ' class="numeric partial"';
                    }
                    // If there is no option, change the class to unanswered
                    if (substr($optiondatarow[$col], 0, 1) == '|') {
                        $correctclass = ' class="unanswered"';
                    }

                    // Strip off the flag
                    $optiondatarow[$col] = substr($optiondatarow[$col], 0, -2);
                }

                echo '<td' . $correctclass . '>' . $optiondatarow[$col] . '</td>';
            }
            echo '</tr>';
        }

        // 'Option counts' starts here
        // Label
        echo '<tr class="optioncounts"><th colspan="' . ($noofmcqs + 4) . '" class="subheading">&nbsp;</th></tr><tr>';
        echo '<th colspan="' . ($noofmcqs + 4) . '" class="subheading">'
           . get_string('optioncounts', 'quiz_mcq') . ':<br /></th>';
        echo '</tr>';
        // Headers
        echo '<tr>';
        echo '<th colspan="4" class="header">' . get_string('option', 'quiz_mcq') . '</th>';
        foreach ($mcqkeys as $q) {
            echo '<th class="header qnum">' . get_string('questionabbr', 'quiz_mcq') . ($q + 1) . '</th>';
        }
        echo '</tr>';
        // Data
        for ($opt = 0; $opt <= $maxoptions; $opt++) {
            echo '<tr>';
            if ($opt == 0) {
                echo '<td colspan="4">' . get_string('nochoice', 'quiz_mcq') . '</td>';
            } else {
                echo '<td colspan="4">' . $opt . '</td>';
            }
            foreach ($mcqkeys as $q) {

                // Cell classes depend on whether using positive or negative highlighting
                if (in_array($opt, $fullycorrect[$q])) {
                    if ($highlightcorrect == 1) {
                        $correctclass = ' class="numeric correct"';
                    } else {
                        $correctclass = ' class="numeric"';
                    }
                } else if (in_array($opt, $anycorrect[$q])) {
                    $correctclass = ' class="numeric partial"';
                } else {
                    if ($highlightcorrect == 1) {
                        $correctclass = ' class="numeric"';
                    } else {
                        $correctclass = ' class="numeric correct"';
                    }
                }

                // Override as unshaded if cell is not set
                if (!isset($optioncounts[$q][$opt])) {
                    $optioncounts[$q][$opt] = '';
                }

                if (preg_match('/^\s*$/', $optioncounts[$q][$opt])) {
                    $correctclass = ' class="numeric"';
                }

                echo '<td' . $correctclass . '>' . $optioncounts[$q][$opt] . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        // 'Users yet to complete' table. Only show this if populated
        if (count($uncompletedusers) > 0) {
            echo '<p class="mcq subheading">' . get_string('usersyettocomplete', 'quiz_mcq') . ':</p>';
            echo '<table id="summary" class="generaltable mcq summarytable">';
            echo '<tbody>';
            echo '<tr>';
            echo '<th class="header">' . get_string('surname', 'quiz_mcq') . '</th>'
                . '<th class="header">' . get_string('firstname', 'quiz_mcq') . '</th>';
            echo '</tr>';
            foreach ($uncompletedusers as $user) {
                echo '<tr>';
                echo '<td>' . $user->lastname . '</td>'
                    . '<td>' . $user->firstname . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo "</table>\n";
            echo '</form>';

        }

    }



    // Output for page
    protected function output_print ($warnings, $nodata, $quiz, $reporturl, $groupnames, $filternames, $noofuserattempts,
            $uncompletedusers, $maxposs, $maxgrade, $median, $mean, $highlightcorrect, $noofmcqs,
            $sortarg1, $sortarg2, $sortarg3, $sortarg4, $fullycorrect, $anycorrect, $noofuserscorrect, $optioncounts,
            $optiondata, $mcqids, $maxoptions, $groupids, $filter) {

        $mcqkeys = array_keys($mcqids); // Reassign since we didn't pass it

        // Abort if nothing to display
        if ($nodata) {
            return true;
        }

        // Set noof questions per block
        $blocksize = 10;

        // Calculate some local values
        $noofuncompletedusers = count($uncompletedusers);
        $noofusers = $noofuserattempts + $noofuncompletedusers;

        // Avoid division by zero later on
        if ($noofusers == 0) {
            $noofusers = 1;
        }

        echo '<table width="100%"><tr><td width="50%">';

        // Output: Quiz close date and link to print view
        $quizclose = $quiz->timeclose ? userdate($quiz->timeclose) : get_string('never', 'quiz_mcq');
        echo '<p><span class="mcq subheading">' . get_string('quizcloses', 'quiz_mcq')
            . ':</span> ' . $quizclose . '<br />';

        // Output: Sample name
        echo '<span class="mcq subheading">' . get_string('sample', 'quiz_mcq') . ':</span> ';
        $groupslist = '';
        foreach ($groupnames as $groupname) {
            if (in_array($groupname[0], $groupids)) {
                $groupslist .= $groupname[1] . ', ';
            }
        }
        echo trim($groupslist, ', ');

        // Output: Filter name
        echo '<br /><span class="mcq subheading">' . get_string('filter', 'quiz_mcq') . ':</span> ';
        foreach ($filternames as $filtername) {
            if ($filtername[0] == $filter) {
                echo $filtername[1];
                break;
            }
        }
        echo '</p>';

        // Output: Key
        echo '<table id="key" class="mcq keytable print">';
        echo '<tbody>';
        echo '<tr><td class="subheading"><a href="' . qualified_me() . '&highlightcorrect='
            . abs(1 - $highlightcorrect) . '">' . get_string('key', 'quiz_mcq') . '</a>: </td>';
        if ($highlightcorrect == 1) {
            echo '<td class="correct">' . get_string('correctoptions', 'quiz_mcq') . '</td>';
        } else {
            echo '<td class="correct">' . get_string('incorrectoptions', 'quiz_mcq') . '</td>';
        }
        echo '<td class="partial">' . get_string('partialoption', 'quiz_mcq') . '</td>';
        echo '<td class="unanswered">' . get_string('unanswered', 'quiz_mcq') . '</td>';
        echo '</tr>';
        echo '</tbody>';
        echo "</table>\n";

        echo '</td><td width="5%">&nbsp;</td><td>';

        // Output: Sample summary table.
        echo '<p><span class="mcq subheading">' . get_string('samplesummary', 'quiz_mcq') . ':</span>';

        echo '<table id="summary" class="mcq summarytable print">';
        echo '<tbody>';
        echo '<tr>';
        echo '<td>' . get_string('userscompleting', 'quiz_mcq') . '</td>';
        echo '<td><div align="right">' . $noofuserattempts . ' (' . round(100 * $noofuserattempts / $noofusers)
            . '%)</div></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>' . get_string('usersyettocomplete', 'quiz_mcq') . '</td>';
        echo '<td><div align="right">' . $noofuncompletedusers . ' (' . round(100 * $noofuncompletedusers / $noofusers)
            . '%)</div></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>' . get_string('maxgrade', 'quiz_mcq') . ' ('
            . get_string('maxposs', 'quiz_mcq') . ' = ' . $maxposs . ')</td>';
        echo '<td><div align="right">' . $maxgrade . ' (' . round(100 * $maxgrade / $maxposs) . '%)</div></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>' . get_string('mediangrade', 'quiz_mcq') . '</td>';
        echo '<td><div align="right">' . $median . ' (' . round(100 * $median / $maxposs) . '%)</div></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>' . get_string('meangrade', 'quiz_mcq') . '</td>';
        echo '<td><div align="right">' . $mean . ' (' . round(100 * $mean / $maxposs) . '%)</div></td>';
        echo '</tr>';
        echo '</tbody>';
        echo "</table></p>\n";

        echo '</td></tr></table>';

        // Output: 'Options chosen' and 'Option counts' table
        echo '<table id="options" class="mcq optionstable print"><tbody>';
        // Loop through blocks
        $noofblocks = ceil($noofmcqs / $blocksize);
        for ($blk = 0; $blk < $noofblocks; $blk++) {
            // Calc noof questions in this block
            if ($blk == $noofblocks - 1) {
                $noofmcqsinblock = $noofmcqs - $blk * $blocksize;
            } else {
                $noofmcqsinblock = $blocksize;
            }
            // Label. No page break if this is the first block
            if ($blk == 0) {
                echo '<tr><th colspan="' . ($blocksize + 4)
                    . '" class="subheading">&nbsp;</th></tr><tr>';
            } else {
                 echo '<tr class="optionschosen"><th colspan="' . ($blocksize + 4)
                     . '" class="subheading">&nbsp;</th></tr><tr>';
            }
            echo '<th colspan="' . ($blocksize + 4) . '" class="subheading">'
                . get_string('optionschosen', 'quiz_mcq') . ':<br /></th>';
            echo '</tr>';
            // Headers
            echo '<tr>';
            echo '<th class="header"><a href="' . qualified_me() . '&sort=' . $sortarg1 . '">'
                . get_string('surname', 'quiz_mcq') . '</a></th>'
                . '<th class="header"><a href="' . qualified_me() . '&sort=' . $sortarg2 . '">'
                . get_string('firstname', 'quiz_mcq') . '</a></th>'
                . '<th class="header"><a href="' . qualified_me() . '&sort=' . $sortarg3 . '">'
                . get_string('grade', 'quiz_mcq') . '</a> (%)</th>'
                . '<th class="header"><a href="' . qualified_me() . '&sort=' . $sortarg4 . '">'
                . get_string('qattempts', 'quiz_mcq') . '</a> (%)</th>';

            for ($qidx = $blk * $blocksize; $qidx < ($blk * $blocksize + $noofmcqsinblock); $qidx++) {
                $q = $mcqkeys[$qidx];
                echo '<th class="header">' . get_string('questionabbr', 'quiz_mcq') . ($q + 1) . '</th>';
            }
            echo '</tr>';
            // Summary rows
            echo '<tr>';
            echo '<td colspan="4" class="subsubheading">' . get_string('correctoptions', 'quiz_mcq') . '</th>';
            for ($qidx = $blk * $blocksize; $qidx < ($blk * $blocksize + $noofmcqsinblock); $qidx++) {

                $q = $mcqkeys[$qidx];

                if ($mcqids[$q][1] == 1) {
                    $correctstring = implode(',', $fullycorrect[$q]);
                } else {
                    $correctstring = implode(',', $anycorrect[$q]);
                }

                if ($highlightcorrect == 1) {
                    echo '<td class="data numeric correct">' . $correctstring . '</td>';
                } else {
                    echo '<td class="data numeric">' . $correctstring . '</td>';
                }
            }
            echo '</tr>';
            echo '<tr>';
            echo '<td colspan="4" class="subsubheading">' . get_string('totalcorrect', 'quiz_mcq') . '</th>';
            for ($qidx = $blk * $blocksize; $qidx < ($blk * $blocksize + $noofmcqsinblock); $qidx++) {
                $q = $mcqkeys[$qidx];
                $noofcompleters = $noofuserattempts - $optioncounts[$q][0];
                if ($noofcompleters == 0) {
                    $noofcompleters = 1;
                } // Avoid division by zero
                echo '<td class="data numeric">' . $noofuserscorrect[$q]
                    . ' (' . round(100 * $noofuserscorrect[$q] / $noofcompleters) . '%)' . '</td>';
            }
            echo '</tr>';
            echo '<tr>';
            echo '<td colspan="4" class="subsubheading">' . get_string('totalchoosing', 'quiz_mcq') . '</th>';
            for ($qidx = $blk * $blocksize; $qidx < ($blk * $blocksize + $noofmcqsinblock); $qidx++) {
                $q = $mcqkeys[$qidx];
                echo '<td class="data numeric">'. ($noofuserattempts - $optioncounts[$q][0]) .'</td>';
            }
            echo '</tr>';
            // User data
            foreach ($optiondata as $optiondatarow) {
                echo '<tr>';
                // Output the first 4 columns individually and start the loop at the next question for this block
                echo '<td>' . $optiondatarow[1] . '</td>';
                echo '<td>' . $optiondatarow[2] . '</td>';
                echo '<td class="numeric">' . $optiondatarow[3]
                    . ' ('. round(100 * $optiondatarow[3] / $maxposs).'%)</td>';
                echo '<td class="numeric">' . $optiondatarow[4]
                    . ' (' . round(100 * $optiondatarow[4] / $noofmcqs) . '%)</td>';

                for ($col = $blk * $blocksize + 5; $col < ($blk * $blocksize + $noofmcqsinblock + 5); $col++) {

                    $correctclass = ' class="numeric"';

                    // Check whether the last character is a flag, and if so use it to determine the cell
                    // class (correct or not, or unanswered)
                    if (substr($optiondatarow[$col], -2, 1) == '|') {
                        if (substr($optiondatarow[$col], -1) == $highlightcorrect) {
                               $correctclass = ' class="numeric correct"';
                        } else if (substr($optiondatarow[$col], -1) == 2) {
                               $correctclass = ' class="numeric partial"';
                        }
                        // If there is no option, change the class to unanswered
                        if (substr($optiondatarow[$col], 0, 1) == '|') {
                            $correctclass = ' class="unanswered"';
                        }

                        // Strip off the flag
                        $optiondatarow[$col] = substr($optiondatarow[$col], 0, -2);
                    }

                    echo '<td' . $correctclass . '>' . $optiondatarow[$col] . '</td>';
                }
                echo '</tr>';
            }

            // 'Option counts' starts here
            // Label
            echo '<tr class="optioncounts"><th colspan="' . ($blocksize + 4)
                . '" class="subheading">&nbsp;</th></tr><tr>';
            echo '<th colspan="' . ($blocksize + 4) . '" class="subheading">'
               . get_string('optioncounts', 'quiz_mcq') . ':<br /></th>';
            echo '</tr>';
            // Headers
            echo '<tr>';
            echo '<th colspan="4" class="header">' . get_string('option', 'quiz_mcq') . '</th>';
            for ($qidx = $blk * $blocksize; $qidx < ($blk * $blocksize + $noofmcqsinblock); $qidx++) {
                $q = $mcqkeys[$qidx];
                echo '<th class="header qnum">' . get_string('questionabbr', 'quiz_mcq') . ($q + 1) . '</th>';
            }
            echo '</tr>';
            // Data
            for ($opt = 0; $opt <= $maxoptions; $opt++) {
                // Avoid page breaks mid-table, where possible
                if ($opt < $maxoptions) {
                    $breakclass = 'class="printnobreak"';
                } else {
                    $breakclass = '';
                }
                echo '<tr ' . $breakclass . '>';
                if ($opt == 0) {
                    echo '<td colspan="4">' . get_string('nochoice', 'quiz_mcq') . '</td>';
                } else {
                    echo '<td colspan="4">' . $opt . '</td>';
                }
                for ($qidx = $blk * $blocksize; $qidx < ($blk * $blocksize + $noofmcqsinblock); $qidx++) {
                    $q = $mcqkeys[$qidx];

                    // Cell classes depend on whether using positive or negative highlighting
                    if (in_array($opt, $fullycorrect[$q])) {
                        if ($highlightcorrect == 1) {
                            $correctclass = ' class="numeric correct"';
                        } else {
                            $correctclass = ' class="numeric"';
                        }
                    } else if (in_array($opt, $anycorrect[$q])) {
                        $correctclass = ' class="numeric partial"';
                    } else {
                        if ($highlightcorrect == 1) {
                            $correctclass = ' class="numeric"';
                        } else {
                            $correctclass = ' class="numeric correct"';
                        }
                    }

                    // Override as unshaded if cell is empty
                    if (!isset($optioncounts[$q][$opt])) {
                        $optioncounts[$q][$opt] = '';
                    }

                    if (preg_match('/^\s*$/', $optioncounts[$q][$opt])) {
                        $correctclass = ' class="numeric"';
                    }

                    echo '<td' . $correctclass . '>' . $optioncounts[$q][$opt] . '</td>';
                }
                echo '</tr>';
            }
        }

        echo '</tbody>';
        echo '</table>';

        // Users Yet To Complete table. Only show this if populated
        if (count($uncompletedusers) > 0) {
            echo '<p class="mcq subheading">' . get_string('usersyettocomplete', 'quiz_mcq') . ':</p>';
            echo '<table id="summary" class="mcq summarytable print">';
            echo '<tbody>';
            echo '<tr>';
            echo '<th class="header">' . get_string('surname', 'quiz_mcq') . '</th>'
                . '<th class="header">' . get_string('firstname', 'quiz_mcq') . '</th>';
            echo '</tr>';
            foreach ($uncompletedusers as $user) {
                echo '<tr>';
                echo '<td>' . $user->lastname . '</td>'
                    . '<td>' . $user->firstname . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo "</table>\n";
            echo '</form>';

        }
    }


    // Comparison functions for sorting alphabetically by column in a 2D array
    protected function lastname_cmp($a, $b) {
        // Sort on first column (lastname), then second (firstname)
        if (strnatcasecmp($a[1], $b[1]) == 0) {
            return strnatcasecmp($a[2], $b[2]);
        } else {
            return strnatcasecmp($a[1], $b[1]);
        }
    }

    protected function firstname_cmp($a, $b) {
        // Sort on second column (firstname), then first (lastname)
        if (strnatcasecmp($a[2], $b[2]) == 0) {
            return strnatcasecmp($a[1], $b[1]);
        } else {
            return strnatcasecmp($a[2], $b[2]);
        }
    }

    protected function grade_cmp($a, $b) {
        // Sort on third column (grade), then first (lastname)
        // NOTE usort expects an integer back from this function so returning a straight
        // subtraction doesn't work
        if ($a[3] - $b[3] == 0) {
            return strnatcasecmp($a[1], $b[1]);
        } else if ($a[3] > $b[3]) {
            return 1;
        } else {
            return -1;
        }
    }

    protected function qattempts_cmp($a, $b) {
        // Sort on fourth column (noofattempts), then first (lastname)
        if ($a[4] - $b[4] == 0) {
            return strnatcasecmp($a[1], $b[1]);
        } else {
            return $a[4] - $b[4];
        }
    }

}
