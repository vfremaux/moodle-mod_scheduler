<?php

/**
* @package mod-scheduler
* @category mod
* @author Gustav Delius, Valery Fremaux > 1.8
*
*/

/**
* Parameter $local added by power-web.at
* When local Date is needed the $local Param must be set to 1 
* @param int $date a timestamp
* @param int $local
* @todo check consistence
* @return string printable date
*/
function scheduler_userdate($date, $local=0) {
    if ($date == 0) {
        return '';
    } else {
        return userdate($date, get_string('strftimedaydate'));
    }
}

/**
* Parameter $local added by power-web.at 
* When local Time is needed the $local Param must be set to 1 
* @param int $date a timestamp
* @param int $local
* @todo check consistence
* @return string printable time
*/
function scheduler_usertime($date, $local=0) {
    if ($date == 0) {
            return '';
    } else {
        if (!$timeformat = get_user_preferences('calendar_timeformat')) {
            $timeformat = get_string('strftimetime');
        }
        return userdate($date, $timeformat);    
    }
}

/**
* get list of attendants for slot form
* @param int $cmid the course module
* @return array of moodle user records
*/
function scheduler_get_attendants($cmid){
    $context = get_context_instance(CONTEXT_MODULE, $cmid);
    $attendants = get_users_by_capability ($context, 'mod/scheduler:attend', 'u.id,lastname,firstname,email,picture', 'lastname', '', '', '', '', false, false, false);
    return $attendants;
}

/**
* Returns an array of slots that would overlap with this one.
* @param int $schedulerid the current activity module id
* @param int $starttimethe start of time slot as a timestamp
* @param int $endtime end of time slot as a timestamp
* @param int $teacher if not null, the id of the teacher constraint, 0 otherwise standas for "all teachers"
* @param int $others selects where to search for conflicts, [SCHEDULER_SELF, SCHEDULER_OTHERS, SCHEDULER_ALL]
* @param boolean $careexclusive if false, conflict will consider all slots wether exlusive or not. Use it for testing if user is appointed in the given scope.
* @uses $CFG
* @return array array of conflicting slots
*/
function scheduler_get_conflicts($schedulerid, $starttime, $endtime, $teacher=0, $student=0, $others=SCHEDULER_SELF, $careexclusive=true) {
    global $CFG;
    
    switch ($others){
        case SCHEDULER_SELF:
            $schedulerScope = " s.schedulerid = '{$schedulerid}' AND ";
            break;
        case SCHEDULER_OTHERS:
            $schedulerScope = " s.schedulerid != '{$schedulerid}' AND ";
            break;
        default:
            $schedulerScope = '';
    }
    $teacherScope = ($teacher != 0) ? " s.teacherid = '{$teacher}' AND " : '' ;
    $studentScope = ($student != 0) ? " a.studentid = '{$student}' AND " : '' ;
    $exclusiveClause = ($careexclusive) ? " exclusivity != 0 AND " : '' ;
    $sql = "
        SELECT
            s.*,
            a.studentid,
            a.id as appointmentid
        FROM
            {$CFG->prefix}scheduler_slots AS s
        LEFT JOIN
            {$CFG->prefix}scheduler_appointment AS a
        ON
            a.slotid = s.id
        WHERE
            {$schedulerScope}
            {$teacherScope}
            {$studentScope}
            {$exclusiveClause}
            ( (s.starttime <= {$starttime} AND
            s.starttime + s.duration * 60 > {$starttime}) OR
            (s.starttime < {$endtime} AND
            s.starttime + s.duration * 60 >= {$endtime}) OR
            (s.starttime >= {$starttime} AND
            s.starttime + s.duration * 60 <= {$endtime}) )
    ";
    $conflicting = get_records_sql($sql);
    
    return $conflicting;
}

/**
* Returns count of slots that would overlap with this
* use it as a test function before toggling to exclusive
* @param int $schedulerid the actual scheduler instance
* @param int $starttime the starttime identifying the slot
* @param int $endtime the endtime of the period
* @param int $teacher the teacher constraint, if null stands for "all teachers"
* @return int the number of compatible slots 
*/
function scheduler_get_consumed($schedulerid, $starttime, $endtime, $teacherid=0) {
    global $CFG;
    
    $teacherScope = ($teacherid != 0) ? " teacherid = '{$teacherid}' AND " : '' ;
    $sql = "
        SELECT
            COUNT(*)
        FROM
            {$CFG->prefix}scheduler_slots AS s,
            {$CFG->prefix}scheduler_appointment AS a
        WHERE
            a.slotid = s.id AND
            schedulerid = {$schedulerid} AND
            {$teacherScope}
            ( (s.starttime <= {$starttime} AND
            {$starttime} < s.starttime + s.duration * 60) OR
            (s.starttime < {$endtime} AND
            {$endtime} <= s.starttime + s.duration * 60) OR
            (s.starttime >= {$starttime} AND
            s.starttime + s.duration * 60 <= {$endtime}) )
    ";
    $count = count_records_sql($sql);
    return $count;
}

/**
* Returns the known exclusivity at that time
* @param int $schedulerid the actual scheduler instance
* @param int $starttime the starttime identifying the slot
* @return int the exclusivity value
*/
function scheduler_get_exclusivity($schedulerid, $starttime) {
    global $CFG;
    
    $sql = "
        SELECT 
            exclusivity
        FROM
            {$CFG->prefix}scheduler_slots AS s
        WHERE
            s.schedulerid = '{$schedulerid}' AND 
            s.starttime <= {$starttime} AND
            {$starttime} <= s.starttime + s.duration * 60
    ";
    return get_field_sql($sql);
}

/**
* retreives the unappointed slots
* @param int $schedulerid
*/
function scheduler_get_unappointed_slots($schedulerid){
    global $CFG;
    
    $sql = "
        SELECT
            s.*,
            MAX(a.studentid) as appointed
        FROM
            {$CFG->prefix}scheduler_slots AS s
        LEFT JOIN
            {$CFG->prefix}scheduler_appointment AS a
        ON
            a.slotid = s.id
        WHERE
            s.schedulerid = {$schedulerid}
        GROUP BY
            s.id
        HAVING 
            appointed = 0 OR appointed IS NULL
        ORDER BY
            s.starttime ASC
    ";

    $recs = get_records_sql($sql);
    return $recs;
}

/**
* retreives the available slots in several situations with a complex query
* @param int $studentid
* @param int $schedulerid
* @param boolean $studentside changes query if we are getting slots in student context
*/
function scheduler_get_available_slots($studentid, $schedulerid, $studentside=false){
    global $CFG;

    /*
    if ($studentside){
        $sql = "
            SELECT
                s.*,
                MAX(a.studentid = {$studentid}) as appointedbyme,
                MAX(a.studentid) as appointed,
                COUNT(IF(a.attended IS NULL OR a.attended = 0, NULL, 1)) as attended,
                COUNT(IF(a.studentid IS NULL, NULL, 1)) as population
            FROM
                {$CFG->prefix}scheduler_slots AS s
            LEFT JOIN
                {$CFG->prefix}scheduler_appointment AS a
            ON
                a.slotid = s.id
            WHERE
                s.schedulerid = {$schedulerid}
             GROUP BY
                s.id
             HAVING
                ((s.exclusivity > 0 AND population < s.exclusivity) OR
                s.exclusivity = 0) OR appointedbyme
             ORDER BY
                s.starttime ASC
        ";
    }
    else{
        $sql = "
            SELECT
                s.*,
                MAX(a.studentid = $studentid) as appointedbyme,
                MAX(a.studentid) as appointed,
                COUNT(IF(a.attended IS NULL OR a.attended = 0, NULL, 1)) as attended,
                COUNT(IF(a.studentid IS NULL, NULL, 1)) as population
             FROM
                {$CFG->prefix}scheduler_slots AS s
             LEFT JOIN
                {$CFG->prefix}scheduler_appointment AS a
             ON 
                a.slotid = s.id
             WHERE
                s.schedulerid = {$schedulerid}
             GROUP BY
                s.id
             HAVING
                ((s.exclusivity > 0 AND population < s.exclusivity) OR
                s.exclusivity = 0) AND
                (NOT appointedbyme OR appointedbyme IS NULL)
              ORDER BY
                s.starttime
        ";
    }

    $recs = get_records_sql($sql);
    return $recs
    */

    // more compatible tryout
    $slots = get_records('scheduler_slots', 'schedulerid', $schedulerid, 'starttime');
    $retainedslots = array();
    if ($slots){
        foreach($slots as $slot){
            $slot->population = count_records('scheduler_appointment', 'slotid', $slot->id);
            $slot->appointed = ($slot->population > 0);
            $slot->attended = record_exists('scheduler_appointment', 'slotid', $slot->id, 'attended', 1);
            if ($studentside){
                $slot->appointedbyme = record_exists('scheduler_appointment', 'slotid', $slot->id, 'studentid', $studentid);
                if ($slot->appointedbyme) {
                    $retainedslots[] = $slot;
                    continue;
                }
            }
            // both side, slot is not complete
            if ($slot->exclusivity == 0 or ($slot->exclusivity > 0 and $slot->population < $slot->exclusivity)){
                $retainedslots[] = $slot;
                continue;
            }
        }    
    }
    
    return $retainedslots;
}

/**
* checks if user has an appointment in this scheduler
* @param object $userlist
* @param object $scheduler
* @param boolean $student, if true, is a student, a teacher otherwise
* @param boolean $unattended, if true, only checks for unattended slots
* @param string $otherthan giving a slotid, excludes this slot from the search
* @uses $CFG
* @return the count of records
*/
function scheduler_has_slot($userlist, &$scheduler, $student=true, $unattended = false, $otherthan = 0){
    global $CFG;

    $userlist = str_replace(',', "','", $userlist);

    $unattendedClause = ($unattended) ? ' AND a.attended = 0 ' : '' ;
    $otherthanClause = ($otherthan) ? " AND a.slotid != $otherthan " : '' ;
               
    if ($student){
        $sql = "
            SELECT 
                COUNT(*)
            FROM
                {$CFG->prefix}scheduler_slots AS s,
                {$CFG->prefix}scheduler_appointment AS a
            WHERE
                a.slotid = s.id AND
                s.schedulerid = {$scheduler->id} AND
                a.studentid IN ('{$userlist}')
                $unattendedClause
                $otherthanClause
        ";
        return count_records_sql($sql);
    } else {
        return count_records_sql('scheduler_slots', 'teacherid', $userlist, 'schedulerid', $scheduler->id);
    }
}

/**
* returns an array of appointed user records for a certain slot.
* @param int $slotid
* @uses $CFG
* @return an array of users
*/
function scheduler_get_appointed($slotid){
    global $CFG;
    
    $sql = "
        SELECT
            u.*
        FROM
            {$CFG->prefix}user AS u,
            {$CFG->prefix}scheduler_appointment AS a
        WHERE
            u.id = a.studentid AND
            a.slotid = {$slotid}
    ";   
    return get_records_sql($sql);
}

/**
* fully deletes a slot with all dependancies
* @param int slotid
*/
function scheduler_delete_slot($slotid){
    if ($slot = get_record('scheduler_slots', 'id', $slotid)) {
        scheduler_delete_calendar_events($slot);
    }
    if (!delete_records('scheduler_slots', 'id', $slotid)) {
        notify("Could not delete the slot from the database");
    }
    delete_records('scheduler_appointment', 'slotid', $slotid);
}


/**
* get appointment records for a slot
* @param int $slotid
* @return an array of appointments
*/
function scheduler_get_appointments($slotid){
    global $CFG;
        
    $apps = get_records('scheduler_appointment', 'slotid', $slotid);
    
    return $apps;
}

/**
* get appointment records for a user in a scheduler
* @param int $slotid
* @return an array of appointments
*/
function scheduler_get_user_appointments(&$scheduler, $user=null){
	global $USER, $CFG;
	
	if (is_null($user)){
		$user = &$USER;
	}

	$sql = "
		SELECT
			a.*
		FROM
			{$CFG->prefix}scheduler_appointment sa,
			{$CFG->prefix}scheduler_slots ss
		WHERE
			sa.slotid =  ss.id AND
			ss.schedulerid = $scheduler->id	AND
			sa.userid = $user->id		
	";        
	$apps = get_records_sql($sql);
    
    return $apps;
}

/**
* a high level api function for deleting an appointement, and do
* what ever is needed
* @param int $appointmentid
*/
function scheduler_delete_appointment($appointmentid, $slot=null, $scheduler=null){
    if (!$oldrecord = get_record('scheduler_appointment', 'id', $appointmentid)) return ;
        
    if (!$slot){ // fetch optimization
        $slot = get_record('scheduler_slots', 'id', $oldrecord->slotid);
    }
    if($slot){
        // delete appointment
        if (!delete_records('scheduler_appointment', 'id', $appointmentid)) {
            if (debugging()) notify('Couldn\'t delete old choice from database');
        }

        // not reusable slot. Delete it if slot is too near and has no more appointments.
        if ($slot->reuse == 0) {
            if (!$scheduler){ // fetch optimization
                $scheduler = get_record('scheduler', 'id', $slot->schedulerid);
            }
            $consumed = scheduler_get_consumed($slot->schedulerid, $slot->starttime, $slot->starttime + $slot->duration * 60);
            if (!$consumed){
                if (time() > $slot->starttime - $scheduler->reuseguardtime * 3600){
                    if (!delete_records('scheduler_slots', 'id', $slot->id)) {
                        if (debugging()) notify('Couldn\'t delete old choice from database');
                    }
                }
            }
        }
    }
}

/**
* get the last considered location in this scheduler
* @param reference $scheduler
* @uses $USER
* @return the last known location for the current user (teacher)
*/
function scheduler_get_last_location(&$scheduler){
    global $USER;
    
    // we could have made an embedded query in Mysql 5.0
    $lastlocation = '';
    $maxtime = get_field_select('scheduler_slots', 'MAX(timemodified)', "schedulerid = {$scheduler->id} AND teacherid = {$USER->id} GROUP BY timemodified");
    if ($maxtime){
        $maxid = get_field_select('scheduler_slots', 'MAX(timemodified)', "schedulerid = {$scheduler->id} AND timemodified = $maxtime AND teacherid = {$USER->id} GROUP BY timemodified");
        $lastlocation = get_field('scheduler_slots', 'appointmentlocation', 'id', $maxid);
    }
    return $lastlocation;
}

/**
* frees all slots unapppointed that are in the past 
* @param int $schedulerid
* @uses $CFG
* @return void
*/
function scheduler_free_late_unused_slots($schedulerid){
    global $CFG;
    
    $now = time();
    $sql = "
        SELECT DISTINCT
            s.id,s.id
        FROM
            {$CFG->prefix}scheduler_slots AS s
        LEFT JOIN
            {$CFG->prefix}scheduler_appointment AS a
        ON
          s.id = a.slotid
        WHERE
            a.studentid IS NULL AND
            s.schedulerid = {$schedulerid} AND
            starttime < {$now}
    ";
    $to_delete = get_records_sql($sql);
    if ($to_delete){
        $ids = implode(',', array_keys($to_delete));
        delete_records_select('scheduler_slots', " id IN ('$ids') ");
    }
}

/// Events related functions

/**
* Updates events in the calendar to the information provided.
* If the events do not yet exist it creates them.
* The only argument this function requires is the complete database record of a scheduler slot.
* The course parameter should be the full record of the course for this scheduler so the 
* teacher-title and student-title can be determined.
* @param object $slot the slot instance
* @param object $course the actual course
*/
function scheduler_add_update_calendar_events($slot, $course) {    

    //firstly, collect up the information we'll need no matter what.
    $eventDuration = ($slot->duration) * 60;
    $eventStartTime = $slot->starttime;
    
    // get all students attached to that slot
    $appointments = get_records('scheduler_appointment', 'slotid', $slot->id, '', 'studentid,studentid');

    // nothing to do
    if (!$appointments) return;

    $studentids = implode(',', array_keys($appointments));
    
    $teacher = get_record('user', 'id', $slot->teacherid);
    $students = get_records_list('user', 'id', $studentids);
    
    $schedulerDescription = get_field('scheduler', 'description', 'id', $slot->schedulerid);
    $schedulerName = get_field('scheduler', 'name', 'id', $slot->schedulerid);
    $teacherEventDescription = addslashes("$schedulerName<br/><br/>$schedulerDescription");
                                  
    $studentEventDescription = $teacherEventDescription;
    
    //the eventtype field stores a code that is used to relate calendar events with the slots that 'own' them.
    //the code is SSstu (for a student event) or SSsup (for a teacher event).
    //then, the id of the scheduler slot that it belongs to.
    //finally, the courseID. I can't remember why, TODO: remember the good reason.
    //all in a colon delimited string. This will run into problems when the IDs of slots and courses are bigger than 7 digits in length...    
    $teacherEventType = "SSsup:{$slot->id}:{$course->id}";
    $studentEventType = "SSstu:{$slot->id}:{$course->id}";
    
    $studentNames = array();

    // passes studentEvent and teacherEvent by reference so function can fill them
    scheduler_events_exists($slot, $studentEvent, $teacherEvent);

    foreach($students as $student){
        $studentNames[] = fullname($student);
        $studentEventName = get_string('meetingwith', 'scheduler').' '.$course->teacher.', '.fullname($teacher);

        //firstly, deal with the student's event
        //if it exists, update it, else create a new one.
    
        if ($studentEvent) {
            $studentEvent->name = $studentEventName;
            $studentEvent->description = $studentEventDescription;
            $studentEvent->format = 1;
            $studentEvent->userid = $student->id;
            $studentEvent->timemodified = time();
            // $studentEvent->modulename = 'scheduler'; // Issue on delete/edit link
            $studentEvent->instance = $slot->schedulerid;
            $studentEvent->timestart = $eventStartTime;
            $studentEvent->timeduration = $eventDuration;
            $studentEvent->visible = 1;
            $studentEvent->eventtype = $studentEventType;
            update_record('event', $studentEvent);
        } else {
            $studentEvent->name = $studentEventName;
            $studentEvent->description = $studentEventDescription;
            $studentEvent->format = 1;
            $studentEvent->userid = $student->id;
            $studentEvent->timemodified = time();
            // $studentEvent->modulename = 'scheduler';
            $studentEvent->instance = $slot->schedulerid;
            $studentEvent->timestart = $eventStartTime;
            $studentEvent->timeduration = $eventDuration;
            $studentEvent->visible = 1;
            $studentEvent->id = null;
            $studentEvent->eventtype = $studentEventType;
            // This should be changed to use add_event()
            insert_record('event', $studentEvent);
        }
    
    }

    if (count($studentNames) > 1){
        $teacherEventName = get_string('meetingwithplural', 'scheduler').' '.$course->students.', '.implode(', ', $studentNames);
    } else {
        $teacherEventName = get_string('meetingwith', 'scheduler').' '.$course->student.', '.$studentNames[0];
    }
    if ($teacherEvent) {
        $teacherEvent->name = $teacherEventName;
        $teacherEvent->description = $teacherEventDescription;
        $teacherEvent->format = 1;
        $teacherEvent->userid = $slot->teacherid;
        $teacherEvent->timemodified = time();
        // $teacherEvent->modulename = 'scheduler';
        $teacherEvent->instance = $slot->schedulerid;
        $teacherEvent->timestart = $eventStartTime;
        $teacherEvent->timeduration = $eventDuration;
        $teacherEvent->visible = 1;
        $teacherEvent->eventtype = $teacherEventType;
        update_record('event',$teacherEvent);
    } else {
        $teacherEvent->name = $teacherEventName;
        $teacherEvent->description = $teacherEventDescription;
        $teacherEvent->format = 1;
        $teacherEvent->userid = $slot->teacherid;
        $teacherEvent->instance = $slot->schedulerid;
        $teacherEvent->timemodified = time();
        // $teacherEvent->modulename = 'scheduler';
        $teacherEvent->timestart = $eventStartTime;
        $teacherEvent->timeduration = $eventDuration;
        $teacherEvent->visible = 1;
        $teacherEvent->id = null;
        $teacherEvent->eventtype = $teacherEventType;
        insert_record('event', $teacherEvent);
    }
}

/**
* Will delete calendar events for a given scheduler slot, and not complain if the record does not exist.
* The only argument this function requires is the complete database record of a scheduler slot.
* @param object $slot the slot instance
* @uses $CFG 
* @uses $USER
* @uses $COURSE
* @return boolean true if success, false otherwise
*/
function scheduler_delete_calendar_events($slot) {
    global $CFG, $SITE, $COURSE;

    $scheduler = get_record('scheduler', 'id', $slot->schedulerid);
    
    if (!$scheduler) return false ;
    
    $teacherEventType = "SSsup:{$slot->id}:{$scheduler->course}";
    $studentEventType = "SSstu:{$slot->id}:{$scheduler->course}";
    
    $teacherDeletionSuccess = delete_records('event', 'eventtype', $teacherEventType);
    $studentDeletionSuccess = delete_records('event', 'eventtype', $studentEventType);

    // we must fetch back all students identities as they may have been deleted
    $oldstudents = get_records('scheduler_appointment', 'slotid', $slot->id, '', 'studentid, studentid');
    if ($scheduler->allownotifications && $oldstudents){
        foreach(array_keys($oldstudents) as $oldstudent){
            $student = get_record('user', 'id', $oldstudent);
            $teacher = get_record('user', 'id', $slot->teacherid);
            include_once($CFG->dirroot.'/mod/scheduler/mailtemplatelib.php');
            $vars = array( 'SITE' => $SITE->shortname,
                           'SITE_URL' => $CFG->wwwroot,
                           'COURSE_SHORT' => $COURSE->shortname,
                           'COURSE' => $COURSE->fullname,
                           'COURSE_URL' => $CFG->wwwroot.'/course/view.php?id='.$COURSE->id,
                           'MODULE' => $scheduler->name,
                           'USER' => fullname($student),
                           'DATE' => userdate($slot->starttime,get_string('strftimedate')),   // BUGFIX CONTRIB-937
 	                       'TIME' => userdate($slot->starttime,get_string('strftimetime')),   // BUGFIX end
                           'DURATION' => $slot->duration );
            $notification = compile_mail_template('cancelled', $vars );
            $notificationHtml = compile_mail_template('cancelled_html', $vars );
            email_to_user($teacher, $student, get_string('schedulecancelled', 'scheduler', $SITE->shortname), $notification, $notificationHtml);
        }
    }
    
    return ($teacherDeletionSuccess && $studentDeletionSuccess);
    //this return may not be meaningful if the delete records functions do not return anything meaningful.
}

/**
* This function decides if a slot should have calendar events associated with it, 
* and calls the update/delete functions if neccessary.
* it must be passed the complete scheduler_slots record to function correctly.
* The course parameter should be the record that belongs to the course for this scheduler.
* @param object $slot the slot instance
* @param object $course the actual course
*/
function scheduler_events_update($slot, $course) {
   
    $slotDoesntHaveAStudent = ! count_records('scheduler_appointment', 'slotid', $slot->id);
    $slotWasAttended = count_records('scheduler_appointment', 'slotid', $slot->id, 'attended', 1);   

    if ($slotDoesntHaveAStudent || $slotWasAttended) {
        scheduler_delete_calendar_events($slot);
    } 
    else {
        scheduler_add_update_calendar_events($slot, $course);
    }
}

/**
* This function sets the $studentSlot and $teacherSlot to the records of the calendar that relate 
* to these scheduler slots. They will equal false if the records do not exist.
* it requires the full record of the scheduler slot supplied as $slot.
* @param object $slot the slot instance
* @param reference $studentSlot
* @param reference $teacherSlot
* @return void
*/
function scheduler_events_exists($slot, &$studentSlot, &$teacherSlot) {
    
    //first we need to know the course that the scheduler belongs to...
    $courseID = get_field('scheduler', 'course', 'id', $slot->schedulerid);
    
    //now try to fetch the event records...
    $teacherEventType = "SSsup:{$slot->id}:{$courseID}";
    $studentEventType = "SSstu:{$slot->id}:{$courseID}";
    
    $teacherSlot = get_record('event', 'eventtype', $teacherEventType);
    $studentSlot = get_record('event', 'eventtype', $studentEventType);
}

/**
 * a utility function for formatting grades for display
 * @param reference $scheduler
 * @param string $grade the grade to be displayed
 * @param boolean $short formats the grade in short form (rsult empty if grading is
 * not used, or no grade is available; parantheses are put arounf the grade if it is present)
 * @return string the formatted grade
 */
 function scheduler_format_grade(&$scheduler, $grade, $short=false){
 
 	$result = '';
     if ($scheduler->scale == 0){
     	// no grading in this scheduler
     	if (!$short) {
     		$result = get_string('nograde');
     	}
     } else {
         if ($scheduler->scale > 0) {
             // numeric grades
             $result .= $grade;
             if (strlen($grade)>0){
                 $result .=  '/' . $scheduler->scale;
             }
         } else {
         	// grade on scale
             if ($grade > 0) {
                 $scaleid = - ($scheduler->scale);
                 if ($scale = get_record('scale', 'id', $scaleid)) {
                     $levels = explode(',',$scale->scale);
                     $result .= $levels[$grade-1];
                 }
             }
         }
         if ($short && (strlen($result)>0)) {
         	$result = '('.$result.')';
         }
     }
     return $result;
 }
 
/**
* a utility function for making grading lists
* @param reference $scheduler
* @param string $id the form field id
* @param string $selected the selected value
* @param boolean $return if true, prints the list to output elsewhere returns the HTML string.
* @return the output of the choose_from_menu production
*/
function scheduler_make_grading_menu(&$scheduler, $id, $selected = '', $return = false){
    if ($scheduler->scale > 0){
        for($i = 0 ; $i <= $scheduler->scale ; $i++)
            $scalegrades[$i] = $i; 
    }
    else {
        $scaleid = - ($scheduler->scale);
        if ($scale = get_record('scale', 'id', $scaleid)) {
            $scalegrades = make_menu_from_list($scale->scale);
        }
    }
    return choose_from_menu($scalegrades, $id, $selected, 'choose', '', '', $return);
}


/**
 * Construct an array with subtitution rules for mail templates, relating to 
 * a single appointment. Any of the parameters can be null.
 * @param object $scheduler The scheduler instance
 * @param object $slot The slot data, obtained with get_record().
 * @param user $attendant A {@link $USER} object describing the attendant (teacher)
 * @param user $attendee A {@link $USER} object describing the attendee (student)
 * @return array A hash with mail template substitutions 
 */
function scheduler_get_mail_variables ($scheduler, $slot, $attendant, $attendee) {
    
    global $CFG;
    
    $vars = array();
    
    if ($scheduler) {
        $vars['MODULE']     = $scheduler->name;
        $vars['STAFFROLE']  = format_string($scheduler->staffrolename);
    }
    if ($slot) {
        $vars ['DATE']     = userdate($slot->starttime,get_string('strftimedate'));
        $vars ['TIME']     = userdate($slot->starttime,get_string('strftimetime'));
        $vars ['ENDTIME']  = userdate($slot->starttime+$slot->duration*60, get_string('strftimetime'));
        $vars ['LOCATION'] = $slot->appointmentlocation;
    }
    if ($attendant) {
        $vars['ATTENDANT']     = fullname($attendant);
        $vars['ATTENDANT_URL'] = $CFG->wwwroot.'/user/view.php?id='.$attendant->id;
    }
    if ($attendee) {
        $vars['ATTENDEE']     = fullname($attendee);
        $vars['ATTENDEE_URL'] = $CFG->wwwroot.'/user/view.php?id='.$attendee->id;
    }
    
    return $vars;
    
}

/**
* adds an error css marker in case of matching error
* @param array $errors the current error set
* @param string $errorkey 
*/
function scheduler_print_error_class($errors, $errorkeylist){
    if ($errors){
        foreach($errors as $anError){
            if ($anError->on == '') continue;
            if (preg_match("/\\b{$anError->on}\\b/" ,$errorkeylist)){
                echo " class=\"scheduler_formerror\" ";
                return;
            }
        }        
    }
}

/**
* Get student count that are really attendable : i.e : are capable to be attended
* and HAVE NOT an appointment booked
* TODO : optimize doing the complete SQL statement and getting result count
* from resultset.
*/
function scheduler_filter_appointed($scheduler, &$allstudents = NULL){
	global $CFG;
	
	$appointed = scheduler_get_currently_appointed($scheduler);	
	foreach ($appointed as $id => $a){
		unset($allstudents[$id]);
	}
}

function scheduler_get_currently_appointed($scheduler){
	global $CFG;
	
	$modeclause = '';
	if ($scheduler->schedulermode == 'onetime'){
		$modeclause = ' AND s.starttime > '.time().' ';
	}
	
	$sql = "
		SELECT DISTINCT
			studentid as id,
			studentid
		FROM
			{$CFG->prefix}scheduler_slots s,
			{$CFG->prefix}scheduler_appointment a
		WHERE
			s.id = a.slotid AND
			s.schedulerid = {$scheduler->id}
			$modeclause
	";
	if ($currents = get_records_sql($sql)){
		return $currents;
	}
	return array();
}

function scheduler_get_filtered_appointable_list($studentids, $filter, $from, $limit){
	global $CFG;

	$idlist = implode("','", array_keys($studentids));
	$filterclause = ($filter) ? " AND firstname LIKE '$filter' OR lastname LIKE '$filter' " :  '';

	$sql = "
		SELECT 
			id,
			firstname,
			lastname,
			email,
			picture
		FROM
			{$CFG->prefix}user
		WHERE
			id IN ('$idlist')
			$filterclause
		ORDER BY 
			lastname,firstname
	";
	if ($filtered = get_records_sql($sql, $from, $limit)){
		return $filtered;
	}
	return array();
}

function scheduler_get_filtered_appointable_count($studentids, $filter){
	global $CFG;

	$idlist = implode("','", array_keys($studentids));
	$filterclause = ($filter) ? " AND firstname LIKE '$filter' OR lastname LIKE '$filter' " :  '';

	$sql = "
		SELECT 
			COUNT(*)
		FROM
			{$CFG->prefix}user
		WHERE
			id IN ('$idlist')
			$filterclause
		ORDER BY 
			lastname,firstname
	";
	return $filteredcount = count_records_sql($sql);
}

/**
*
*/
function scheduler_print_pagination($cmid, $view, $offset, $from, $filter, $pagesize, $count, $what = ''){
	global $CFG;
	
	$allpages = ceil($count / $pagesize);
	
	if ($allpages == 0) return;

	$pages = array();
	
	for($i = 1 ; $i <= $allpages ; $i++){
		if (floor($from / $pagesize) + 1 == $i){
			$pages[] = "<b><u>$i</u></b>";
		} else {
			$pagestart = ($i - 1) * $pagesize;
			$pages[] = "<a href=\"{$CFG->wwwroot}/mod/scheduler/view.php?id={$cmid}&amp;view=$view&amp;offset=$offset&amp;from=$pagestart&amp;what={$what}&amp;filter=$filter\">$i</a>";
		}
	}
	
	echo implode(' ', $pages);
}

/**
*
*/
function scheduler_get_slot_data(&$form){
    if (!$form->hideuntil = optional_param('hideuntil', '', PARAM_INT)){
        $form->displayyear = required_param('displayyear', PARAM_INT);
        $form->displaymonth = required_param('displaymonth', PARAM_INT);
        $form->displayday = required_param('displayday', PARAM_INT);
        $form->hideuntil = make_timestamp($form->displayyear, $form->displaymonth, $form->displayday);
    }
    if (!$form->starttime = optional_param('starttime', '', PARAM_INT)){    
        $form->year = required_param('year', PARAM_INT);
        $form->month = required_param('month', PARAM_INT);
        $form->day = required_param('day', PARAM_INT);
        $form->hour = required_param('hour', PARAM_INT);
        $form->minute = required_param('minute', PARAM_INT);
        $form->starttime = make_timestamp($form->year, $form->month, $form->day, $form->hour, $form->minute);
    }
    $form->exclusivity = required_param('exclusivity', PARAM_INT);
    $form->reuse = required_param('reuse', PARAM_INT);
    $form->duration = required_param('duration', PARAM_INT);
    $form->notes = required_param('notes', PARAM_TEXT);
    $form->teacherid = required_param('teacherid', PARAM_INT);
    $form->appointmentlocation = required_param('appointmentlocation', PARAM_CLEAN);
}

/**
*
*/
function scheduler_get_session_data(&$form){
    if (!$form->rangestart = optional_param('rangestart', '', PARAM_INT)){    
        $year = required_param('startyear', PARAM_INT);
        $month = required_param('startmonth', PARAM_INT);
        $day = required_param('startday', PARAM_INT);
        $form->rangestart = make_timestamp($year, $month, $day);
        $form->starthour = required_param('starthour', PARAM_INT);
        $form->startminute = required_param('startminute', PARAM_INT);
        $form->timestart = make_timestamp($year, $month, $day, $form->starthour, $form->startminute);
    }
    if (!$form->rangeend = optional_param('rangeend', '', PARAM_INT)){    
        $year = required_param('endyear', PARAM_INT);
        $month = required_param('endmonth', PARAM_INT);
        $day = required_param('endday', PARAM_INT);
        $form->rangeend = make_timestamp($year, $month, $day);
        $form->endhour = required_param('endhour', PARAM_INT);
        $form->endminute = required_param('endminute', PARAM_INT);
        $form->timeend = make_timestamp($year, $month, $day, $form->endhour, $form->endminute);
    }
    $form->monday = optional_param('monday', 0, PARAM_INT);
    $form->tuesday = optional_param('tuesday', 0, PARAM_INT);
    $form->wednesday = optional_param('wednesday', 0, PARAM_INT);
    $form->thursday = optional_param('thursday', 0, PARAM_INT);
    $form->friday = optional_param('friday', 0, PARAM_INT);
    $form->saturday = optional_param('saturday', 0, PARAM_INT);
    $form->sunday = optional_param('sunday', 0, PARAM_INT);
    $form->forcewhenoverlap = required_param('forcewhenoverlap', PARAM_INT);
    $form->exclusivity = required_param('exclusivity', PARAM_INT);
    $form->reuse = required_param('reuse', PARAM_INT);
    $form->divide = optional_param('divide', 0, PARAM_INT);
    $form->duration = optional_param('duration', 15, PARAM_INT);
    $form->teacherid = required_param('teacherid', PARAM_INT);
    $form->appointmentlocation = optional_param('appointmentlocation', '', PARAM_CLEAN);
    $form->emailfrom = required_param('emailfrom', PARAM_CLEAN);
    $form->displayfrom = required_param('displayfrom', PARAM_CLEAN);
}

?>