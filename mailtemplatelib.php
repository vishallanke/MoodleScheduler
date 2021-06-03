<?php

/**
 * Message formatting from templates.
 *
 * @package mod_scheduler
 * @copyright 2016 Henning Bostelmann and others (see README.txt)
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined ( 'MOODLE_INTERNAL' ) || die ();

/**
 * Message functionality for scheduler module
 *
 * @package mod_scheduler
 * @copyright 2016 Henning Bostelmann and others (see README.txt)
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduler_messenger {
    /**
     * Returns the language to be used in a message to a user
     *
     * @param stdClass $user
     *            the user to whom the message will be sent
     * @param stdClass $course
     *            the course from which the message originates
     * @return string
     */
    protected static function get_message_language($user, $course) {
        if ($course && ! empty ($course->id) and $course->id != SITEID and !empty($course->lang)) {
            // Course language overrides user language.
            $return = $course->lang;
        } else if (!empty($user->lang)) {
            $return = $user->lang;
        } else if (isset ($CFG->lang)) {
            $return = $CFG->lang;
        } else {
            $return = 'en';
        }

        return $return;
    }

    /**
     * Gets the content of an e-mail from language strings.
     *
     * Looks for the language string email_$template_$format and replaces the parameter values.
     *
     * @param string $template the template's identified
     * @param string $format the mail format ('subject', 'html' or 'plain')
     * @param array $parameters an array ontaining pairs of parm => data to replace in template
     * @param string $module module to use language strings from
     * @param string $lang language to use
     * @return a fully resolved template where all data has been injected
     *
     */
    public static function compile_mail_template($template, $format, $parameters, $module = 'scheduler', $lang = null) {
        $params = array ();
        foreach ($parameters as $key => $value) {
            $params [strtolower($key)] = $value;
        }
        $mailstr = get_string_manager()->get_string("email_{$template}_{$format}", $module, $params, $lang);
        return $mailstr;
    }

    /**
     * Sends a message based on a template.
     * Several template substitution values are automatically filled by this routine.
     *
     * @uses $CFG
     * @uses $SITE
     * @param string $modulename
     *            name of the module that sends the message
     * @param string $messagename
     *            name of the message in messages.php
     * @param int $isnotification
     *            1 for notifications, 0 for personal messages
     * @param user $sender
     *            A {@link $USER} object describing the sender
     * @param user $recipient
     *            A {@link $USER} object describing the recipient
     * @param object $course
     *            The course that the activity is in. Can be null.
     * @param string $template
     *            the mail template name as in language config file (without "_html" part)
     * @param array $parameters
     *            a hash containing pairs of parm => data to replace in template
     * @return bool|int Returns message id if message was sent OK, "false" if there was another sort of error.
     */
    public static function send_message_from_template($modulename, $messagename, $isnotification,
                                                      stdClass $sender, stdClass $recipient, $course,
                                                      $template, array $parameters) {
        global $CFG;
        global $SITE;

        $lang = self::get_message_language($recipient, $course);

        $defaultvars = array (
                'SITE' => $SITE->fullname,
                'SITE_SHORT' => $SITE->shortname,
                'SITE_URL' => $CFG->wwwroot,
                'SENDER' => fullname ( $sender ),
                'RECIPIENT' => fullname ( $recipient )
        );

        if ($course) {
            $defaultvars['COURSE_SHORT'] = format_string($course->shortname);
            $defaultvars['COURSE'] = format_string($course->fullname);
            $defaultvars['COURSE_URL'] = $CFG->wwwroot . '/course/view.php?id=' . $course->id;
        }

        $vars = array_merge($defaultvars, $parameters);

        $message = new \core\message\message();
        $message->component = $modulename;
        $message->name = $messagename;
        $message->userfrom = $sender;
        $message->userto = $recipient;
        $message->subject = self::compile_mail_template($template, 'subject', $vars, $modulename, $lang);
        $message->fullmessage = self::compile_mail_template($template, 'plain', $vars, $modulename, $lang);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = self::compile_mail_template ( $template, 'html', $vars, $modulename, $lang );
        $message->notification = '1';
        $message->courseid = $course->id;
        $message->contexturl = $defaultvars['COURSE_URL'];
        $message->contexturlname = $course->fullname;

        $msgid = message_send($message);
        return $msgid;
    }

    /**
     * Construct an array with subtitution rules for mail templates, relating to
     * a single appointment. Any of the parameters can be null.
     *
     * @param scheduler_instance $scheduler The scheduler instance
     * @param scheduler_slot $slot The slot data as an MVC object, may be null
     * @param user $teacher A {@link $USER} object describing the attendant (teacher)
     * @param user $student A {@link $USER} object describing the attendee (student)
     * @param object $course A course object relating to the ontext of the message
     * @param object $recipient A {@link $USER} object describing the recipient of the message
     *                          (used for determining the message language)
     * @return array A hash with mail template substitutions
     */
    public static function get_scheduler_variables(scheduler_instance $scheduler,  $slot,
                                                   $teacher, $student, $course, $recipient) {

        global $CFG;

        $lang = self::get_message_language($recipient, $course);
        // Force any string formatting to happen in the target language.
        $oldlang = force_current_language($lang);

        $tz = core_date::get_user_timezone($recipient);

        $vars = array();

        if ($scheduler) {
            $vars['MODULE']     = format_string($scheduler->name);
            $vars['STAFFROLE']  = $scheduler->get_teacher_name();
            $vars['SCHEDULER_URL'] = $CFG->wwwroot.'/mod/scheduler/view.php?id='.$scheduler->cmid;
        }
        if ($slot) {
            $vars ['DATE']     = userdate($slot->starttime, get_string('strftimedate'), $tz);
            $vars ['TIME']     = userdate($slot->starttime, get_string('strftimetime'), $tz);
            $vars ['ENDTIME']  = userdate($slot->endtime, get_string('strftimetime'), $tz);
            $vars ['LOCATION'] = format_string($slot->appointmentlocation);
        }
        if ($teacher) {
            $vars['ATTENDANT']     = fullname($teacher);
            $vars['ATTENDANT_URL'] = $CFG->wwwroot.'/user/view.php?id='.$teacher->id.'&course='.$scheduler->course;
        }
        if ($student) {
            $vars['ATTENDEE']     = fullname($student);
            $vars['ATTENDEE_URL'] = $CFG->wwwroot.'/user/view.php?id='.$student->id.'&course='.$scheduler->course;
        }

        // Reset language settings.
        force_current_language($oldlang);

        return $vars;

    }


    /**
     * Send a notification message about a scheduler slot.
     *
     * @param scheduler_slot $slot the slot that the notification relates to
     * @param string $messagename name of message as in db/message.php
     * @param string $template template name to use (language string up to prefix/postfix)
     * @param stdClass $sender user record for sender
     * @param stdClass $recipient  user record for recipient
     * @param stdClass $teacher user record for teacher
     * @param stdClass $student user record for student
     * @param stdClass $course course record
     */
    public static function send_slot_notification(scheduler_slot $slot, $messagename, $template,
                                                  stdClass $sender, stdClass $recipient,
                                                  stdClass $teacher, stdClass $student, stdClass $course) {
        $vars = self::get_scheduler_variables($slot->get_scheduler(), $slot, $teacher, $student, $course, $recipient);
        self::send_message_from_template('mod_scheduler', $messagename, 1, $sender, $recipient, $course, $template, $vars);

    }
	
	public static function send_slot_notification_cancelled(scheduler_slot $slot, $messagename, $template,
                                                  stdClass $sender, stdClass $recipient,
                                                  stdClass $teacher, stdClass $student, stdClass $course, $resonOfCancel) {
        $vars = self::get_scheduler_variables($slot->get_scheduler(), $slot, $teacher, $student, $course, $recipient);
		$vars['REASON_CANCEL']   = $resonOfCancel;
        self::send_message_from_template('mod_scheduler', $messagename, 1, $sender, $recipient, $course, $template, $vars);
		self::send_message_from_template('mod_scheduler', $messagename, 1, $sender, $sender, $course, $template, $vars);

    }
	
	public static function send_slot_notification_confirmed(scheduler_slot $slot, $messagename, $template,
                                                  stdClass $sender, stdClass $student,
                                                  stdClass $teacher, stdClass $recipient, stdClass $course, stdClass $teacher1, stdClass $teacher2, stdClass $teacher3, stdClass $teacher4) {
				$vars = self::get_scheduler_variables($slot->get_scheduler(), $slot, $teacher, $student, $course, $recipient);
				//$vars['REASON_CANCEL']   = $resonOfCancel;
			   //self::send_message_from_template('mod_scheduler', $messagename, 1, $sender, $recipient, $course, $template, $vars);
				
					   global $CFG;
				global $SITE;

				$lang = self::get_message_language($recipient, $course);

				$defaultvars = array (
						'SITE' => $SITE->fullname,
						'SITE_SHORT' => $SITE->shortname,
						'SITE_URL' => $CFG->wwwroot,
						'SENDER' => fullname ( $sender ),
						'RECIPIENT' => fullname ( $recipient )
				);

				if ($course) {
					$defaultvars['COURSE_SHORT'] = format_string($course->shortname);
					$defaultvars['COURSE'] = format_string($course->fullname);
					$defaultvars['COURSE_URL'] = $CFG->wwwroot . '/course/view.php?id=' . $course->id;
					
					// https://inpun01002wspr.ad001.siemens.net/moodle/mod/scheduler/view.php?what=viewstudent&id=287&appointmentid=4736
					//$defaultvars['SLOT_BOOKED_URL'] = $CFG->wwwroot . 'mod/scheduler/view.php?what=viewstudent&id=' . $slot->id . '&appointmentid=' . $slot->appointmentid;
				}

			   // $vars = array_merge($defaultvars, $parameters);


				$message = new \core\message\message();
				$message->component = 'mod_scheduler';
				$message->name = $messagename;
				$message->userfrom = $sender;
				$message->userto = $recipient;
				$message->subject =  $vars['DATE']  . ': Hurrey ! Appointment is Confirmed !';
				$message->fullmessage = 'Your appointment on ' . $vars['DATE'] . ' at ' . $vars['TIME'] . '
		with the ' . $vars['STAFFROLE'] . ' ' . $vars['ATTENDANT'] . ' for course:

		' . $defaultvars['COURSE_SHORT'] . ': ' . $defaultvars['COURSE'] . '

		in the scheduler titled "' . $vars['MODULE'] . '" on the website: ' . $defaultvars['SITE'] . '

		has been Confirmed.' .  $defaultvars['COURSE_URL'] . 'This email is autogenerated. We are working on improving this email and provide better user experience.';

				$message->fullmessageformat = FORMAT_PLAIN;
				
				$message->fullmessagehtml = 'Hi ' . $defaultvars['RECIPIENT'] . ', <br/><br/> ' . $recipient->email . '<br/>Your appointment on <b>' . $vars['DATE'] . '</b> at <b>' . $vars['TIME'] . '
		</b> <br/> with the <b>' . $vars['STAFFROLE'] . ' ' . $vars['ATTENDANT'] . '</b><br/> for course:

		<b>' . $defaultvars['COURSE_SHORT'] . ': ' . $defaultvars['COURSE'] . '

		</b><br/>in the scheduler titled <b>"' . $vars['MODULE'] . '"</b> on the website: <b>' . $defaultvars['SITE'] . '

		</b><br/> has been Confirmed. ' .  $defaultvars['COURSE_URL'] . '<br/><br/>This email is autogenerated. We are working on improving this email and provide better user experience.';
				$message->notification = '1';
				$message->courseid = $course->id;
				$message->contexturl = $defaultvars['COURSE_URL'];
				$message->contexturlname = $course->fullname;

				$msgid = message_send($message);
				
				$message1 = new \core\message\message();
				$message1->component = 'mod_scheduler';
				$message1->name = $messagename;
				$message1->userfrom = $sender;
				$message1->userto = $sender;
				$message1->subject =  $vars['DATE']  . ': Hurrey ! Appointment is Confirmed !';
				$message1->fullmessage = 'Appointment of ' . $defaultvars['RECIPIENT'] . ' Email ' . $recipient->email . ' Appointment Date ' . $vars['DATE'] . ' at ' . $vars['TIME'] . '
		with the ' . $vars['STAFFROLE'] . ' ' . $vars['ATTENDANT'] . ' for course:

		' . $defaultvars['COURSE_SHORT'] . ': ' . $defaultvars['COURSE'] . '

		in the scheduler titled "' . $vars['MODULE'] . '" on the website: ' . $defaultvars['SITE'] . '

		has been Confirmed.' .  $defaultvars['COURSE_URL'] . 'This email is autogenerated. We are working on improving this email and provide better user experience.';

				$message1->fullmessageformat = FORMAT_PLAIN;
				
				$message1->fullmessagehtml = 'Appointment of employee <b>' . $defaultvars['RECIPIENT'] . ' </b>, Email <b>' . $recipient->email . '</b> Appointment Date on <b>' . $vars['DATE'] . '</b> at <b>' . $vars['TIME'] . '
		</b> <br/> with the <b>' . $vars['STAFFROLE'] . ' ' . $vars['ATTENDANT'] . '</b><br/> for course:

		<b>' . $defaultvars['COURSE_SHORT'] . ': ' . $defaultvars['COURSE'] . '

		</b><br/>in the scheduler titled <b>"' . $vars['MODULE'] . '"</b> on the website: <b>' . $defaultvars['SITE'] . '

		</b><br/> has been Confirmed. <br/> Employee can visit office now. <br/> ' .  $defaultvars['COURSE_URL'] . '</b><br/><br/>This email is autogenerated. We are working on improving this email and provide better user experience.';
				$message1->notification = '1';
				$message1->courseid = $course->id;
				$message1->contexturl = $defaultvars['COURSE_URL'];
				$message1->contexturlname = $course->fullname;

				$msgid = message_send($message1);
				
				if ($course->id == "83"){
					$message2 = new \core\message\message();
				$message2->component = 'mod_scheduler';
				$message2->name = $messagename;
				$message2->userfrom = $teacher1;
				$message2->userto = $teacher1;
				$message2->subject =  $vars['DATE']  . ': Hurrey ! Appointment is Confirmed !';
				$message2->fullmessage = 'Appointment of ' . $defaultvars['RECIPIENT'] . ' Email ' . $recipient->email . ' Appointment Date ' . $vars['DATE'] . ' at ' . $vars['TIME'] . '
		with the ' . $vars['STAFFROLE'] . ' ' . $vars['ATTENDANT'] . ' for course:

		' . $defaultvars['COURSE_SHORT'] . ': ' . $defaultvars['COURSE'] . '

		in the scheduler titled "' . $vars['MODULE'] . '" on the website: ' . $defaultvars['SITE'] . '

		has been Confirmed.' .  $defaultvars['COURSE_URL'] . 'This email is autogenerated. We are working on improving this email and provide better user experience.';

				$message2->fullmessageformat = FORMAT_PLAIN;
				
				$message2->fullmessagehtml = 'Appointment of employee <b>' . $defaultvars['RECIPIENT'] . ' </b>, Email <b>' . $recipient->email . '</b> Appointment Date on <b>' . $vars['DATE'] . '</b> at <b>' . $vars['TIME'] . '
		</b> <br/> with the <b>' . $vars['STAFFROLE'] . ' ' . $vars['ATTENDANT'] . '</b><br/> for course:

		<b>' . $defaultvars['COURSE_SHORT'] . ': ' . $defaultvars['COURSE'] . '

		</b><br/>in the scheduler titled <b>"' . $vars['MODULE'] . '"</b> on the website: <b>' . $defaultvars['SITE'] . '

		</b><br/> has been Confirmed. <br/> Employee can visit office now. <br/> ' .  $defaultvars['COURSE_URL'] . ' <br/><br/>This email is autogenerated. We are working on improving this email and provide better user experience.';
				$message2->notification = '1';
				$message2->courseid = $course->id;
				$message2->contexturl = $defaultvars['COURSE_URL'];
				$message2->contexturlname = $course->fullname;

				$msgid = message_send($message2);
				
				$message3 = new \core\message\message();
				$message3->component = 'mod_scheduler';
				$message3->name = $messagename;
				$message3->userfrom = $teacher2;
				$message3->userto = $teacher2;
				$message3->subject =  $vars['DATE']  . ': Hurrey ! Appointment is Confirmed !';
				$message3->fullmessage = 'Appointment of ' . $defaultvars['RECIPIENT'] . ' Email ' . $recipient->email . ' Appointment Date ' . $vars['DATE'] . ' at ' . $vars['TIME'] . '
		with the ' . $vars['STAFFROLE'] . ' ' . $vars['ATTENDANT'] . ' for course:

		' . $defaultvars['COURSE_SHORT'] . ': ' . $defaultvars['COURSE'] . '

		in the scheduler titled "' . $vars['MODULE'] . '" on the website: ' . $defaultvars['SITE'] . '

		has been Confirmed.' .  $defaultvars['COURSE_URL'] . 'This email is autogenerated. We are working on improving this email and provide better user experience.';

				$message3->fullmessageformat = FORMAT_PLAIN;
				
				$message3->fullmessagehtml = 'Appointment of employee <b>' . $defaultvars['RECIPIENT'] . ' </b>, Email <b>' . $recipient->email . '</b> Appointment Date on <b>' . $vars['DATE'] . '</b> at <b>' . $vars['TIME'] . '
		</b> <br/> with the <b>' . $vars['STAFFROLE'] . ' ' . $vars['ATTENDANT'] . '</b><br/> for course:

		<b>' . $defaultvars['COURSE_SHORT'] . ': ' . $defaultvars['COURSE'] . '

		</b><br/>in the scheduler titled <b>"' . $vars['MODULE'] . '"</b> on the website: <b>' . $defaultvars['SITE'] . '

		</b><br/> has been Confirmed. <br/> Employee can visit office now. <br/> ' .  $defaultvars['COURSE_URL'] . ' <br/><br/>This email is autogenerated. We are working on improving this email and provide better user experience.';
				$message3->notification = '1';
				$message3->courseid = $course->id;
				$message3->contexturl = $defaultvars['COURSE_URL'];
				$message3->contexturlname = $course->fullname;

				$msgid = message_send($message3);
				
				$message4 = new \core\message\message();
				$message4->component = 'mod_scheduler';
				$message4->name = $messagename;
				$message4->userfrom = $teacher3;
				$message4->userto = $teacher3;
				$message4->subject =  $vars['DATE']  . ': Hurrey ! Appointment is Confirmed !';
				$message4->fullmessage = 'Appointment of ' . $defaultvars['RECIPIENT'] . ' Email ' . $recipient->email . ' Appointment Date ' . $vars['DATE'] . ' at ' . $vars['TIME'] . '
		with the ' . $vars['STAFFROLE'] . ' ' . $vars['ATTENDANT'] . ' for course:

		' . $defaultvars['COURSE_SHORT'] . ': ' . $defaultvars['COURSE'] . '

		in the scheduler titled "' . $vars['MODULE'] . '" on the website: ' . $defaultvars['SITE'] . '

		has been Confirmed.' .  $defaultvars['COURSE_URL'] . 'This email is autogenerated. We are working on improving this email and provide better user experience.';

				$message4->fullmessageformat = FORMAT_PLAIN;
				
				$message4->fullmessagehtml = 'Appointment of employee <b>' . $defaultvars['RECIPIENT'] . ' </b>, Email <b>' . $recipient->email . '</b> Appointment Date on <b>' . $vars['DATE'] . '</b> at <b>' . $vars['TIME'] . '
		</b> <br/> with the <b>' . $vars['STAFFROLE'] . ' ' . $vars['ATTENDANT'] . '</b><br/> for course:

		<b>' . $defaultvars['COURSE_SHORT'] . ': ' . $defaultvars['COURSE'] . '

		</b><br/>in the scheduler titled <b>"' . $vars['MODULE'] . '"</b> on the website: <b>' . $defaultvars['SITE'] . '

		</b><br/> has been Confirmed. <br/> Employee can visit office now. <br/> ' .  $defaultvars['COURSE_URL'] . ' <br/><br/>This email is autogenerated. We are working on improving this email and provide better user experience.';
				$message4->notification = '1';
				$message4->courseid = $course->id;
				$message4->contexturl = $defaultvars['COURSE_URL'];
				$message4->contexturlname = $course->fullname;

				$msgid = message_send($message4);
				}

		
				return $msgid;
    }

}