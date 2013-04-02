<?php

/**
* @package mod-scheduler
* @category mod
* @author Valery Fremaux > 1.8
*
* This is a controller for major teacher side use cases
*
* @usecase doaddupdateslot
* @usecase doaddsession
* @usecase deleteslot
* @usecase deleteslots
* @usecase saveseen
* @usecase revokeall
* @usecase allowgroup
* @usecase forbidgroup
* @usecase reuse
* @usecase unreuse
* @usecase deleteall
* @usecase deleteunused
* @usecase deleteallunused
* @usecase deleteonlymine
*/

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from view.php in mod/scheduler
}

// We first have to check whether some action needs to be performed
switch ($action) {
/************************************ creates or updates a slot ***********************************************/
/*
* If fails, should reenter within the form signalling error cause
*/
    case 'doaddupdateslot':{
        // get expected parameters
        $slotid = optional_param('slotid', '', PARAM_INT);
        
        // get standard slot parms
        scheduler_get_slot_data($data);
        $appointments = unserialize(stripslashes(optional_param('appointments', '', PARAM_RAW)));
        
        $errors = array();
        // Avoid slots starting in the past (too far)
        if ($data->starttime < (time() - DAYSECS * 10)) {
            $erroritem->message = get_string('startpast', 'scheduler');
            $erroritem->on = 'rangestart';
            $errors[] = $erroritem;
        }

        if ($data->exclusivity > 0 and count($appointments) > $data->exclusivity){
            unset($erroritem);
            $erroritem->message = get_string('exclusivityoverload', 'scheduler');
            $erroritem->on = 'exclusivity';
            $errors[] = $erroritem;
        }

        if ($data->teacherid == 0){
            unset($erroritem);
            $erroritem->message = get_string('noteacherforslot', 'scheduler');
            $erroritem->on = 'teacherid';
            $errors[] = $erroritem;
        }
        
        if (count($errors)){
            $action = 'addslot';
            return;
        }

        // Avoid overlapping slots, by asking the user if they'd like to overwrite the existing ones...
        // for other scheduler, we check independently of exclusivity. Any slot here conflicts
        // for this scheduler, we check against exclusivity. Any complete slot here conflicts
        $conflictsRemote = scheduler_get_conflicts($scheduler->id, $data->starttime, $data->starttime + $data->duration * 60, $data->teacherid, 0, SCHEDULER_OTHERS, false);
        $conflictsLocal = scheduler_get_conflicts($scheduler->id, $data->starttime, $data->starttime + $data->duration * 60, $data->teacherid, 0, SCHEDULER_SELF);
        if (!$conflictsRemote) $conflictsRemote = array();
        if (!$conflictsLocal) $conflictsLocal = array();
        $conflicts = $conflictsRemote + $conflictsLocal;
        
        // remove itself from conflicts when updating
        if (!empty($slotid) and array_key_exists($slotid, $conflicts)){
            unset($conflicts[$slotid]);
        }

        if (count($conflicts)) {
            if ($subaction == 'confirmdelete' && confirm_sesskey()) {
                foreach ($conflicts as $conflict) {
                    if ($conflict->id != @$slotid) {
                        delete_records('scheduler_slots', 'id', $conflict->id);
                        delete_records('scheduler_appointment', 'slotid', $conflict->id);
                        scheduler_delete_calendar_events($conflict);
                    }
                }
            } 
            else { 
                echo "<br/><br/>";
                print_simple_box_start('center', '', '');
                    echo get_string('slotwarning', 'scheduler').'<br/><br/>';
                    foreach ($conflicts as $conflict) {
                        $students = scheduler_get_appointed($conflict->id);
                        
                        echo (!empty($students)) ? '<b>' : '' ;
                        echo userdate($conflict->starttime);
                        echo ' [';
                        echo $conflict->duration.' '.get_string('minutes');
                        echo ']<br/>';
                        
                        if ($students){
                            $appointed = array();
                            foreach($students as $aStudent){
                                $appointed[] = fullname($aStudent);
                            }
                            if (count ($appointed)){
                                echo '<span style="font-size : smaller">';
                                echo implode(', ', $appointed);
                                echo '</span>';
                            }
                            unset ($appointed);
                            echo '<br/>';
                        }
                        echo (!empty($students)) ? '</b>' : '' ;
                    }

                    $options = array();
                    $options['what'] = 'addslot';
                    $options['id'] = $cm->id;
                    $options['view'] = $view;
                    $options['slotid'] = $slotid;
                    print_single_button('view.php', $options, get_string('cancel'));

                    $options['what'] = 'doaddupdateslot';
                    $options['subaction'] = 'confirmdelete';
                    $options['sesskey'] = sesskey();
                    $options['year'] = $data->year;
                    $options['month'] = $data->month;
                    $options['day'] = $data->day;
                    $options['hour'] = $data->hour;
                    $options['minute'] = $data->minute;
                    $options['displayyear'] = $data->displayyear;
                    $options['displaymonth'] = $data->displaymonth;
                    $options['displayday'] = $data->displayday;
                    $options['duration'] = $data->duration;
                    $options['teacherid'] = $data->teacherid;
                    $options['exclusivity'] = $data->exclusivity;
                    $options['appointments'] = serialize($appointments);
                    $options['notes'] = $data->notes;
                    $options['reuse'] = $data->reuse;
                    $options['appointmentlocation'] = $data->appointmentlocation;
                    print_single_button('view.php', $options, get_string('deletetheseslots', 'scheduler'));
                print_simple_box_end(); 
                print_footer($course);
                die();  
            }
        }

        // make new slot record
        $slot->schedulerid = $scheduler->id;
        $slot->starttime = $data->starttime;
        $slot->duration = $data->duration;
        if (!empty($data->slotid)){
            $appointed = count(scheduler_get_appointments($data->slotid));
            if ($data->exclusivity > 0 and $appointed > $data->exclusivity){
                unset($erroritem);
                $erroritem->message = get_string('exclusivityoverload', 'scheduler');
                $erroritem->on = 'exclusivity';
                $errors[] = $erroritem;
                return;
            }
            $slot->exclusivity = max($data->exclusivity, $appointed);
        }
        else{
            $slot->exclusivity = $data->exclusivity;
        }
        $slot->timemodified = time();
        if (!empty($data->teacherid)) $slot->teacherid = $data->teacherid;
        $slot->notes = $data->notes;
        $slot->appointmentlocation = $data->appointmentlocation;
        $slot->hideuntil = $data->hideuntil;
        $slot->reuse = $data->reuse;
        if (!$slotid){ // add it
            if (!($slot->id = insert_record('scheduler_slots', $slot))) {
                error('Could not insert slot in database');
            }
            print_heading(get_string('oneslotadded','scheduler'));
        }
        else{ // update it
            $slot->id = $slotid;
            if (!(update_record('scheduler_slots', $slot))) {
                error('Could not update slot in database');
            }
            print_heading(get_string('slotupdated','scheduler'));
        }

        if($appointments){
            delete_records('scheduler_appointment', 'slotid', $slot->id); // cleanup old appointments
            foreach($appointments as $appointment){ // insert updated
                $appointment->slotid = $slot->id; // now we know !!
                $appointment->schedulerid = $scheduler->id; // now we know !!
                insert_record('scheduler_appointment', $appointment);
            }
        }

        scheduler_events_update($slot, $course);
        break;
    }
/************************************ Saving a session with slots *************************************/
    case 'doaddsession':{
        // This creates sessions using the data submitted by the user via the form on add.html
        scheduler_get_session_data($data);

        $fordays = (($data->rangeend - $data->rangestart) / DAYSECS);
        
        $errors = array();

        /// range is negative
        if ($fordays < 0){
            $erroritem->message = get_string('negativerange', 'scheduler');
            $erroritem->on = 'rangeend';
            $errors[] = $erroritem;
        }

        if ($data->teacherid == 0){
            unset($erroritem);
            $erroritem->message = get_string('noteacherforslot', 'scheduler');
            $erroritem->on = 'teacherid';
            $errors[] = $erroritem;
        }

        /// first slot is in the past
        if ($data->rangestart < time() - DAYSECS) {
            unset($erroritem);
            $erroritem->message = get_string('startpast', 'scheduler');
            $erroritem->on = 'rangestart';
            $errors[] = $erroritem;
        }

        // first error trap. Ask to correct that first
        if (count($errors)){
            $action = 'addsession';
            break;
        }
        

        /// make a base slot for generating
        $slot->appointmentlocation = $data->appointmentlocation;
        $slot->exclusivity = $data->exclusivity;
        $slot->reuse = $data->reuse;
        $slot->duration = $data->duration;
        $slot->schedulerid = $scheduler->id;
        $slot->timemodified = time();
        $slot->teacherid = $data->teacherid;

        /// check if overlaps. Check also if some slots are in allowed day range
        $startfrom = $data->rangestart;
        $noslotsallowed = true;
        for ($d = 0; $d <= $fordays; $d ++){
            $eventdate = usergetdate($startfrom + ($d * 86400));
            $dayofweek = date('l', $startfrom + ($d * 86400));
            if ((($dayofweek == 'Monday') && ($data->monday == 1)) ||
                (($dayofweek == 'Tuesday') && ($data->tuesday == 1)) || 
                (($dayofweek == 'Wednesday') && ($data->wednesday == 1)) ||
                (($dayofweek == 'Thursday') && ($data->thursday == 1)) || 
                (($dayofweek == 'Friday') && ($data->friday == 1)) ||
                (($dayofweek == 'Saturday') && ($data->saturday == 1)) ||
                (($dayofweek == 'Sunday') && ($data->sunday == 1))){
                $noslotsallowed = false;
                $data->starttime = $startfrom + ($d * 86400);
                $conflicts = scheduler_get_conflicts($scheduler->id, $data->starttime, $data->starttime + $data->duration * 60, $data->teacherid, false, SCHEDULER_ALL);
                if (!$data->forcewhenoverlap){
                    if ($conflicts){
                        unset($erroritem);
                        $erroritem->message = get_string('overlappings', 'scheduler');
                        $erroritem->on = 'range';
                        $errors[] = $erroritem;
                    }
                }
            }
        }
        
        /// Finally check if some slots are allowed (an error is thrown to ask care to this situation)
        if ($noslotsallowed){
            unset($erroritem);
            $erroritem->message = get_string('allslotsincloseddays', 'scheduler');
            $erroritem->on = 'days';
            $errors[] = $erroritem;
        }

        // second error trap. For last error cases.
        if (count($errors)){
            $action = 'addsession';
            break;
        }

        /// Now create as many slots of $duration as will fit between $starttime and $endtime and that do not conflicts
        $countslots = 0;
        $couldnotcreateslots = '';
        $startfrom = $data->timestart;
        for ($d = 0; $d <= $fordays; $d ++){
            $eventdate = usergetdate($startfrom + ($d * DAYSECS));
            $dayofweek = date('l', $startfrom + ($d * DAYSECS));
            if ((($dayofweek == 'Monday') && ($data->monday == 1)) ||
                (($dayofweek == 'Tuesday') && ($data->tuesday == 1)) ||
                (($dayofweek == 'Wednesday') && ($data->wednesday == 1)) || 
                (($dayofweek == 'Thursday') && ($data->thursday == 1)) ||
                (($dayofweek == 'Friday') && ($data->friday == 1)) ||
                (($dayofweek == 'Saturday') && ($data->saturday == 1)) ||
                (($dayofweek == 'Sunday') && ($data->sunday == 1))){
                $slot->starttime = $startfrom + ($d * DAYSECS);
                $data->timestart = $startfrom + ($d * DAYSECS);
                $data->timeend = make_timestamp(date('Y',$data->timestart), date('m',$data->timestart), date('d',$data->timestart), $data->endhour, $data->endminute);

                // this corrects around midnight bug
                if ($data->timestart > $data->timeend){
                    $data->timeend += DAYSECS;
                }
                if ($data->displayfrom == 'now'){
                    $slot->hideuntil = time();
                } 
                else {
                    $slot->hideuntil = make_timestamp($eventdate['year'], $eventdate['mon'], $eventdate['mday'], 6, 0) - $data->displayfrom;
                }
                if ($data->emailfrom == 'never'){
                    $slot->emaildate = 0;
                } 
                else {
                    $slot->emaildate = make_timestamp($eventdate['year'], $eventdate['mon'], $eventdate['mday'], 0, 0) - $data->emailfrom;
                }
                // echo " generating from " .userdate($slot->starttime)." till ".userdate($data->timeend). " ";
                // echo " generating on " . ($data->timeend - $slot->starttime) / 60;
                while ($slot->starttime <= $data->timeend - $data->duration * 60) {
                    $conflicts = scheduler_get_conflicts($scheduler->id, $data->timestart, $data->timestart + $data->duration * 60, $data->teacherid, false, SCHEDULER_ALL);
                    if ($conflicts) {
                        if (!$data->forcewhenoverlap){
                            print_string('conflictingslots', 'scheduler');
                            echo '<ul>';
                            foreach ($conflicts as $aConflict){
                                $sql = "
                                   SELECT
                                      c.fullname,
                                      c.shortname,
                                      sl.starttime
                                   FROM
                                      {$CFG->prefix}course AS c,
                                      {$CFG->prefix}scheduler AS s,
                                      {$CFG->prefix}scheduler_slots AS sl
                                   WHERE
                                      s.course = c.id AND
                                      sl.schedulerid = s.id AND
                                      sl.id = {$aConflict->id}                                 
                                ";
                                $conflictinfo = get_record_sql($sql);
                                echo '<li> ' . userdate($conflictinfo->starttime) . ' ' . usertime($conflictinfo->starttime) . ' ' . get_string('incourse', 'scheduler') . ': ' . $conflictinfo->shortname . ' - ' . $conflictinfo->fullname . "</li>\n";
                            }
                            echo '</ul><br/>';
                        }
                        else{ // we force, so delete all conflicting before inserting
                            foreach($conflicts as $conflict){
                                scheduler_delete_slot($conflict->id);
                            }
                        }
                    } 
                    else {
                        if (!insert_record('scheduler_slots', $slot, false)) {
                            error('Could not insert slot into database. This is a software error you should report to maintainers.');
                        }
                        $countslots++;
                    }
                    $slot->starttime += $data->duration * 60;
                    $data->timestart += $data->duration * 60;
                }
            }
        }
        print_heading(get_string('slotsadded', 'scheduler', $countslots));
        break;
    }
/************************************ Deleting a slot ***********************************************/
    case 'deleteslot': {
        $slotid = required_param('slotid', PARAM_INT);

        scheduler_delete_slot($slotid);
        break;
    }
/************************************ Deleting multiple slots ***********************************************/
    case 'deleteslots': {
        $slotids = required_param('items', PARAM_RAW);
        $slots = explode(",", $slotids);
        foreach($slots as $aSlotId){
            scheduler_delete_slot($aSlotId);
        }
        break;
    }
/************************************ Students were seen ***************************************************/
    case 'saveseen':{
        // get required param
        $slotid = required_param('slotid', PARAM_INT);
        $seen = optional_param('seen', array(), PARAM_RAW);

        $appointments = scheduler_get_appointments($slotid);
        if (is_array($seen)){
            foreach($appointments as $anAppointment){
                $anAppointment->attended = (in_array($anAppointment->id, $seen)) ? 1 : 0 ;
                $anAppointment->timemodified = time();
                $anAppointment->appointmentnote = str_replace("'", "\\'", $anAppointment->appointmentnote);
                if (!update_record('scheduler_appointment', $anAppointment)) {
                    error('Couldn\'t save data to database. This is a software error that should be signalled to maintainers.');
                }
            }
        }

        $slot = get_record('scheduler_slots', 'id', $slotid);
        scheduler_events_update($slot, $course);
        break;
    }
/************************************ Revoking all appointments to a slot ***************************************/
    case 'revokeall': {
        $slotid = required_param('slotid', PARAM_INT);

        if ($slot = get_record('scheduler_slots', 'id', $slotid)){
            // unassign student to the slot
            $oldstudents = get_records('scheduler_appointment', 'slotid', $slot->id, '', 'id,studentid');
            
            if ($oldstudents){            
                foreach($oldstudents as $oldstudent){
                    scheduler_delete_appointment($oldstudent->id, $slot, $scheduler);
                }
            }

            // delete subsequent event
            scheduler_delete_calendar_events($slot);

            // notify student
            if ($scheduler->allownotifications && $oldstudents){
                foreach($oldstudents as $oldstudent){
                    $student = get_record('user', 'id', $oldstudent->studentid);
                    $teacher = get_record('user', 'id', $slot->teacherid);
                    include_once($CFG->dirroot.'/mod/scheduler/mailtemplatelib.php');
                    $vars = array( 'SITE' => $SITE->shortname,
                                   'SITE_URL' => $CFG->wwwroot,
                                   'COURSE_SHORT' => $COURSE->shortname,
                                   'COURSE' => $COURSE->fullname,
                                   'COURSE_URL' => $CFG->wwwroot.'/course/view.php?id='.$COURSE->id,
                                   'MODULE' => $scheduler->name,
                                   'USER' => fullname($teacher),
                                   'STAFFROLE' => format_string($scheduler->staffrolename),
                                   'DATE' => userdate($slot->starttime,get_string('strftimedate')),   // BUGFIX CONTRIB-937
    	                           'TIME' => userdate($slot->starttime,get_string('strftimetime')),   // BUGFIX end
                                   'DURATION' => $slot->duration );
                    $notification = compile_mail_template('teachercancelled', $vars );
                    $notificationHtml = compile_mail_template('teachercancelled_html', $vars );
                    email_to_user($student, $teacher, get_string('cancelledbyteacher', 'scheduler', $SITE->shortname), $notification, $notificationHtml);
                }
            }
            
            if (!$slot->reuse and $slot->starttime > time() - $scheduler->reuseguardtime){
                delete_records('scheduler_slots', 'id', $slot->id);
            }
        }
        break;
    }

/************************************ Toggling to unlimited group ***************************************/
    case 'allowgroup':{
        $slotid = required_param('slotid', PARAM_INT);
        unset($slot);
        $slot->id = $slotid;
        $slot->exclusivity = 0;
        update_record('scheduler_slots', $slot);
        break;
    }

/************************************ Toggling to single student ******************************************/
    case 'forbidgroup':{
        $slotid = required_param('slotid', PARAM_INT);
        unset($slot);
        $slot->id = $slotid;
        $slot->exclusivity = 1;
        update_record('scheduler_slots', $slot);
        break;
    }
    
/************************************ Toggling reuse on ***************************************/
    case 'reuse':{
        $slotid = required_param('slotid', PARAM_INT);
        unset($slot);
        $slot->id = $slotid;
        $slot->reuse = 1;
        update_record('scheduler_slots', $slot);
        break;
    }

/************************************ Toggling reuse off ***************************************/
    case 'unreuse':{
        $slotid = required_param('slotid', PARAM_INT);
        unset($slot);
        $slot->id = $slotid;
        $slot->reuse = 0;
        update_record('scheduler_slots', $slot);
        break;
    }

/************************************ Deleting all slots ***************************************************/
    case 'deleteall':{
        if ($slots = get_records('scheduler_slots', 'schedulerid', $cm->instance)){
            foreach($slots as $aSlot){
                scheduler_delete_calendar_events($aSlot);
            }
            $slotList = implode(',', array_keys($slots));
            delete_records_select('scheduler_appointment', "slotid IN ($slotList)");
            delete_records('scheduler_slots', 'schedulerid', $cm->instance);
            unset($slots);
        }            
        break;
    }
/************************************ Deleting unused slots *************************************************/
    // MUST STAY HERE, JUST BEFORE deleteallunused
    case 'deleteunused':{
       $teacherClause = " AND s.teacherid = {$USER->id} ";
    }
/************************************ Deleting unused slots (all teachers) ************************************/
    case 'deleteallunused': {
       if (!isset($teacherClause)) $teacherClause = '';
       if (has_capability('mod/scheduler:manageallappointments', $context)){
            $sql = "
                SELECT
                    s.id,
                    s.id
                FROM
                    {$CFG->prefix}scheduler_slots AS s
                LEFT JOIN
                    {$CFG->prefix}scheduler_appointment AS a
                ON
                    s.id = a.slotid
                WHERE
                    a.studentid IS NULL
                    {$teacherClause}
            ";
            $unappointed = get_records_sql($sql);
            $unappointedList = implode(',', array_keys($unappointed));
            delete_records_select('scheduler_slots', "schedulerid = $cm->instance AND id IN ($unappointedList)");
        }
        break;
    }
/************************************ Deleting current teacher's slots ***************************************/
    case 'deleteonlymine': {
        if ($slots = get_records_select('scheduler_slots', "schedulerid = {$cm->instance} AND teacherid = {$USER->id}", '', 'id,id')){
            foreach($slots as $aSlot){
                scheduler_delete_calendar_events($aSlot);
            }
            delete_records('scheduler_slots', 'schedulerid', $cm->instance, 'teacherid', $USER->id);
            $slotList = implode(',', array_keys($slots));
            delete_records_select('scheduler_appointment', "slotid IN ($slotList)");
            unset($slots);
        }
        break;
    }
/************************************ View : New single slot form ****************************************/
	case 'addslot': {
	    print_heading(get_string('addsingleslot', 'scheduler'));
	
	    if (empty($subaction)){
	        $form->what = 'doaddupdateslot';
	        // blank appointment data
	        if (empty($form->appointments)) $form->appointments = array();
	        $form->starttime = time();
	        $form->duration = 15;
	        $form->reuse = 1;
	        $form->exclusivity = 1;
	        $form->hideuntil = $scheduler->timemodified; // supposed being in the past so slot is visible
	        $form->notes = '';
	        $form->teacherid = $USER->id;
	        $form->appointmentlocation = scheduler_get_last_location($scheduler);
	    } elseif($subaction == 'cancel') {
	        scheduler_get_slot_data($form);
	        $form->what = 'doaddupdateslot';
	        $form->appointments = unserialize(stripslashes(required_param('appointmentssaved', PARAM_RAW)));
	    } else {
	        $retcode = include_once "teacherview.subcontroller.php";
	        if ($retcode == -1) return -1; 
	    }
	
	    /// print errors
	    if (!empty($errors)){
	        $errorstr = '';
	        foreach($errors as $anError){
	            $errorstr .= $anError->message;
	        }
	        print_simple_box($errorstr, 'center', '70%', '', 5, 'errorbox');
	    }
	
	    /// print form
	    print_simple_box_start('center', '', '');
	    include_once ('oneslotform.html');
	    print_simple_box_end();
	    echo '<br />';
	    
	    // return code for include
	    return -1;
	}
/************************************ View : Update single slot form ****************************************/
	case 'updateslot': {
	    $slotid = required_param('slotid', PARAM_INT);
	
	    print_heading(get_string('updatesingleslot', 'scheduler'));
	
	    if(empty($subaction)){
	        if(!empty($errors)){ // if some errors, get data from client side
	            scheduler_get_slot_data($form);
	            $form->appointments = unserialize(stripslashes(required_param('appointments', PARAM_RAW)));
	        } else {
	            /// get data from the last inserted
	            $slot = get_record('scheduler_slots', 'id', $slotid);
	            $form = &$slot;
	            // get all appointments for this slot
	            $form->appointments = array();
	            $appointments = get_records('scheduler_appointment', 'slotid', $slotid);
	            // convert appointement keys to studentid
	            if ($appointments){
	                foreach($appointments as $appointment){
	                    $form->appointments[$appointment->studentid] = $appointment;
	                }
	            }
	        }
	    } elseif($subaction == 'cancel') {
	        scheduler_get_slot_data($form);
	        $form->appointments = unserialize(stripslashes(required_param('appointmentssaved', PARAM_RAW)));
	        $form->studentid = required_param('studentid', PARAM_INT);
	        $form->slotid = required_param('slotid', PARAM_INT);
	        $form->what = 'doaddupdateslot';
	    } elseif($subaction != '') {
	        $retcode = include_once "teacherview.subcontroller.php";
	        if ($retcode == -1) return -1; 
	    }
	
	    // print errors and notices
	    if (!empty($errors)){
	        $errorstr = '';
	        foreach($errors as $anError){
	            $errorstr .= $anError->message;
	        }
	        print_simple_box($errorstr, 'center', '70%', '', 5, 'errorbox');
	    }
	
	    /// print form
	    $form->what = 'doaddupdateslot';
	
	    print_simple_box_start('center', '', '');
	    include('oneslotform.html');
	    print_simple_box_end();
	    echo '<br />';
	    
	    // return code for include
	    return -1;
	}
/************************************ Add session multiple slots form ****************************************/
	case 'addsession' : {
	    // if there is some error from controller, display it
	    if (!empty($errors)){
	        $errorstr = '';
	        foreach($errors as $anError){
	            $errorstr .= $anError->message;
	        }
	        print_simple_box($errorstr, 'center', '70%', '', 5, 'errorbox');
	    }
	    
	    if (!empty($errors)){
	        scheduler_get_session_data($data);
	        $form = &$data;
	    } else {
	        $form->rangestart = time();
	        $form->rangeend = time();
	        $form->timestart = time();
	        $form->timeend = time() + HOURSECS;
	        $form->hideuntil = $scheduler->timemodified;
	        $form->duration = $scheduler->defaultslotduration;
	        $form->forcewhenoverlap = 0;
	        $form->teacherid = $USER->id;
	        $form->exclusivity = 1;
	        $form->duration = $scheduler->defaultslotduration;
	        $form->reuse = 1;
	        $form->monday = 1;
	        $form->tuesday = 1;
	        $form->wednesday = 1;
	        $form->thursday = 1;
	        $form->friday = 1;
	        $form->saturday = 0;
	        $form->sunday = 0;
	    }
	
	    print_heading(get_string('addsession', 'scheduler'));
	    print_simple_box_start('center', '', '');
	    include_once('addslotsform.html');
	    print_simple_box_end();
	    echo '<br />';
	    
	    // return code for include
	    return -1;
	}
/************************************ Schedule a student form ***********************************************/
	case 'schedule' : {    
	    if ($subaction == 'dochooseslot'){
	        /// set an advice message
	        unset($erroritem);
	        $erroritem->message = get_string('dontforgetsaveadvice', 'scheduler');
	        $erroritem->on = '';
	        $errors[] = $erroritem;
	
	        $slotid = required_param('slotid', PARAM_INT);
	        $studentid = required_param('studentid', PARAM_INT);
	        if ($slot = get_record('scheduler_slots', 'id', $slotid)){
	            $form = &$slot;
	
	            $form->studentid = $studentid;
	            $form->what = 'doaddupdateslot';
	            $form->slotid = $slotid;
	            $form->availableslots = scheduler_get_available_slots($studentid, $scheduler->id);            
	            $appointment->studentid = $studentid;
	            $appointment->attended = optional_param('attended', 0, PARAM_INT);
	            $appointment->grade = optional_param('grade', 0, PARAM_INT);
	            $appointment->appointmentnote = optional_param('appointmentnote', '', PARAM_TEXT);
	            $appointment->timecreated = time();
	            $appointment->timemodified = time();
	            $appointments = get_records('scheduler_appointment', 'slotid', $slotid);
	            $appointments[$appointment->studentid] = $appointment;
	            $form->appointments = $appointments;
	        } else {
	            $form->studentid = $studentid;
	            $form->what = 'doaddupdateslot';
	            $form->slotid = 0;
	            $form->starttime = time();
	            $form->duration = 15;
	            $form->reuse = 1;
	            $form->exclusivity = 1;
	            $form->hideuntil = $scheduler->timemodified; // supposed being in the past so slot is visible
	            $form->notes = '';
	            $form->teacherid = $USER->id;
	            $form->appointmentlocation = scheduler_get_last_location($scheduler);
	            $form->availableslots = scheduler_get_available_slots($studentid, $scheduler->id);            
	            $form->appointments = unserialize(stripslashes(required_param('appointments', PARAM_RAW)));
	        }
	    } elseif($subaction == 'cancel') {
	        scheduler_get_slot_data($form);
	        $form->appointments = unserialize(stripslashes(required_param('appointments', PARAM_RAW)));
	        $form->studentid = required_param('studentid', PARAM_INT);
	        $form->slotid = required_param('slotid', PARAM_INT);
	        $form->availableslots = scheduler_get_available_slots($form->studentid, $scheduler->id);            
	        $form->what = 'doaddupdateslot';
	    } elseif(empty($subaction)) {
	        if (!empty($errors)){
	            scheduler_get_slot_data($form);
	            $form->availableslots = scheduler_get_available_slots($form->studentid, $scheduler->id);            
	            $form->studentid = required_param('studentid', PARAM_INT);
	            $form->seen = optional_param('seen', 0, PARAM_INT);
	            $form->slotid = optional_param('slotid', -1, PARAM_INT);
	        } else {
	            $form->studentid = required_param('studentid', PARAM_INT);
	            $form->seen = optional_param('seen', 0, PARAM_INT);
	                
	            /// getting available slots
	            $form->availableslots = scheduler_get_available_slots($form->studentid, $scheduler->id);            
	            $form->what = 'doaddupdateslot' ;
	            $form->starttime = time();
	            $form->duration = $scheduler->defaultslotduration;
	            $form->reuse = 1;
	            $form->exclusivity = 1;
	            $form->hideuntil = $scheduler->timemodified; // supposed being in the past so slot is visible
	            $form->notes = '';
	            $form->teacherid = $USER->id;
	            $form->appointmentlocation = scheduler_get_last_location($scheduler);
	            $form->slotid = 0;
	            $appointment->slotid = -1;
	            $appointment->studentid = $form->studentid;
	            $appointment->appointmentnote = '';
	            $appointment->attended = $form->seen;
	            $appointment->grade = '';
	            $appointment->timecreated = time();
	            $appointment->timemodified = time();
	            $form->appointments[$form->studentid] = $appointment;
	        }
	    } elseif($subaction != '') {
	        $retcode = include_once "teacherview.subcontroller.php";
	        if ($retcode == -1) return -1; 
	    }
	
	    // display error or advices
	    if (!empty($errors)){
	        $errorstr = '';
	        foreach($errors as $anError){
	            $errorstr .= $anError->message;
	        }
	        print_simple_box($errorstr, 'center', '70%', '', 5, 'errorbox');
	    }
	
	    // diplay form
	    $form->student = get_record('user', 'id', $form->studentid);
	    $studentname = fullname($form->student, true);
	    print_heading(get_string('scheduleappointment', 'scheduler', $studentname));
	    print_simple_box_start('center', '', '');
	    include('oneslotform.html');
	    print_simple_box_end();
	    echo '<br />';
	    
	    // return code for include
	    return -1;
	}
/************************************ Schedule a whole group in form ***********************************************/
	case 'schedulegroup' : { 
	    if($subaction == 'dochooseslot'){
	        /// set an advice message
	        unset($erroritem);
	        $erroritem->message = get_string('dontforgetsaveadvice', 'scheduler');
	        $erroritem->on = '';
	        $errors[] = $erroritem;
	
	        $slotid = required_param('slotid', PARAM_INT);
	        if ($slot = get_record('scheduler_slots', 'id', $slotid)){
	            $form = &$slot;
	            $form->groupid = required_param('groupid', PARAM_INT);
	            $form->what = 'doaddupdateslot';
	            $form->slotid = $slotid;
	            $form->availableslots = scheduler_get_unappointed_slots($scheduler->id);
	            $appointments = array();
	            $members = groups_get_members($form->groupid);
	
	            // add all group members to the slot, and match exclusivity
	            foreach($members as $member){
	                unset($appointment);
	                // hack for 1.8 / 1.9 compatibility of groups_get_members() call
	                if (is_numeric($member)){
	                    $appointment->studentid = $member;
	                } else {
	                    $appointment->studentid = $member->id;
	                }
	                $appointment->attended = optional_param('attended', 0, PARAM_INT);
	                $appointment->grade = optional_param('grade', 0, PARAM_INT);
	                $appointment->appointmentnote = optional_param('appointmentnote', '', PARAM_TEXT);
	                $appointment->timecreated = time();
	                $appointment->timemodified = time();
	                $appointments[$appointment->studentid] = $appointment;
	            }
	            $form->appointments = $appointments;
	        } else {
	            $form->groupid = $groupid;
	            $form->what = 'doaddupdateslot';
	            $form->slotid = 0;
	            $form->starttime = time();
	            $form->duration = 15;
	            $form->reuse = 1;
	            $form->exclusivity = 1;
	            $form->hideuntil = $scheduler->timemodified; // supposed being in the past so slot is visible
	            $form->notes = '';
	            $form->teacherid = $USER->id;
	            $form->appointmentlocation = scheduler_get_last_location($scheduler);
	            $form->availableslots = scheduler_get_unappointed_slots($scheduler->id);            
	            $form->appointments = unserialize(stripslashes(required_param('appointments', PARAM_RAW)));
	        }
	    } elseif($subaction == 'cancel') {
	        scheduler_get_slot_data($form);
	        $form->appointments = unserialize(stripslashes(required_param('appointments', PARAM_RAW)));
	        $form->studentid = required_param('studentid', PARAM_INT);
	        $form->slotid = required_param('slotid', PARAM_INT);
	        $form->availableslots = scheduler_get_unappointed_slots($scheduler->id);            
	        $form->what = 'doaddupdateslot';
	    } elseif(empty($subaction)) {
	        if (!empty($errors)){
	            scheduler_get_slot_data($form);
	            $form->availableslots = scheduler_get_unappointed_slots($scheduler->id);            
	            $form->groupid = required_param('groupid', PARAM_INT);
	            $form->seen = optional_param('seen', 0, PARAM_INT);
	            $form->slotid = optional_param('slotid', -1, PARAM_INT);
	        } else {
	            $form->groupid = required_param('groupid', PARAM_INT);
	            $form->seen = optional_param('seen', 0, PARAM_INT);
	                
	            /// getting available slots
	            $form->availableslots = scheduler_get_unappointed_slots($scheduler->id);   
	            $form->what = 'doaddupdateslot' ;
	            $form->starttime = time();
	            $form->duration = $scheduler->defaultslotduration;
	            $form->reuse = 1;
	            $form->hideuntil = $scheduler->timemodified; // supposed being in the past so slot is visible
	            $form->notes = '';
	            $form->teacherid = $USER->id;
	            $form->appointmentlocation = scheduler_get_last_location($scheduler);
	            $form->slotid = 0;
	            $members = groups_get_members($form->groupid);
	            $form->exclusivity = count($members);
	            foreach($members as $member){
	                unset($appointment);
	                $appointment->slotid = -1;
	                // hack for 1.8 / 1.9 compatibility of groups_get_members() call
	                if (is_numeric($member)){
	                    $appointment->studentid = $member;
	                } else {
	                    $appointment->studentid = $member->id;
	                }
	                $appointment->appointmentnote = '';
	                $appointment->attended = $form->seen;
	                $appointment->grade = '';
	                $appointment->timecreated = time();
	                $appointment->timemodified = time();
	                $form->appointments[$appointment->studentid] = $appointment;
	            }
	        }
	    } elseif($subaction != '') {
	        $retcode = include_once "teacherview.subcontroller.php";
	        if ($retcode == -1) return -1; 
	    }
	
	    // display error or advices
	    if (!empty($errors)){
	        $errorstr = '';
	        foreach($errors as $anError){
	            $errorstr .= $anError->message;
	        }
	        print_simple_box($errorstr, 'center', '70%', '', 5, 'errorbox');
	    }
	
	    // diplay form
	    $form->group = get_record('groups', 'id', $form->groupid);
	    print_heading(get_string('scheduleappointment', 'scheduler', $form->group->name));
	    print_simple_box_start('center', '', '');
	    include('oneslotform.html');
	    print_simple_box_end();
	    echo '<br />';
	    
	    // return code for include
	    return -1;
	}
}

/*************************************************************************************************************/
?>