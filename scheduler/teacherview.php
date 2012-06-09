<?php // $Id: teacherview.php,v 1.2 2011-12-26 22:25:07 vf Exp $

/**
* @package mod-scheduler
* @category mod
* @author Gustav Delius, Valery Fremaux > 1.8
*
* This page prints the screen view for the teachers. It realizes all "view" related use cases.
*
* Note for paginations : 
* $from is used for userlist pagination
* $offset is used for slots pagination
*
* TODO : Clarify variable names
*
* @usecase addslot
* @usecase updateslot
* @usecase addsession
* @usecase schedule
* @usecase schedulegroup
* @usecase viewstatistics
* @usecase viewstudent
* @usecase downloads
*/

	if (!defined('MOODLE_INTERNAL')) {
	    die('Direct access to this script is forbidden.');    ///  It must be included from view.php in mod/scheduler
	}
	
	// get additional params
	$from = optional_param('from', 0, PARAM_INT);
	$filter = optional_param('filter', '', PARAM_TEXT);

    if ($action){
        $res = include('teacherview.controller.php');
        if ($res == -1) return -1;
    }


/// print top tabs

$tabrows = array();
$row  = array();

switch ($action){
    case 'viewstatistics':{
        $currenttab = get_string('statistics', 'scheduler');
        break;
    } 
    case 'datelist':{
        $currenttab = get_string('datelist', 'scheduler');
        break;
    } 
    case 'viewstudent':{
        $currenttab = get_string('studentdetails', 'scheduler');
        $row[] = new tabobject($currenttab, '', $currenttab);
        break;
    } 
    case 'downloads':{
        $currenttab = get_string('downloads', 'scheduler');
        break;
    } 
    default: {
        $currenttab = get_string($view, 'scheduler');
    }
}

$tabname = get_string('myappointments', 'scheduler');
$row[] = new tabobject($tabname, "view.php?id={$cm->id}&amp;view=myappointments", $tabname);
if (count_records('scheduler_slots', 'schedulerid', $scheduler->id) > count_records('scheduler_slots', 'schedulerid', $scheduler->id, 'teacherid', $USER->id)) {
    $tabname = get_string('allappointments', 'scheduler');
    $row[] = new tabobject($tabname, "view.php?id={$cm->id}&amp;view=allappointments", $tabname);
} else {
    // we are alone in this scheduler
    if ($view == 'allappointements') {
        $currenttab = get_string('myappointments', 'scheduler');
    }
}
$tabname = get_string('datelist', 'scheduler');
$row[] = new tabobject($tabname, "view.php?id={$cm->id}&amp;what=datelist", $tabname);
$tabname = get_string('statistics', 'scheduler');
$row[] = new tabobject($tabname, "view.php?what=viewstatistics&amp;id={$cm->id}&amp;course={$scheduler->course}&amp;view=overall", $tabname);
$tabname = get_string('downloads', 'scheduler');
$row[] = new tabobject($tabname, "view.php?what=downloads&amp;id={$cm->id}&amp;course={$scheduler->course}", $tabname);
$tabrows[] = $row;
print_tabs($tabrows, $currenttab);

/// print heading
print_heading($scheduler->name);

/// print page
if ($scheduler->description) {
    print_simple_box(format_text($scheduler->description), 'center');
}

if ($view == 'allappointments'){
    $select = "schedulerid = '". $scheduler->id ."'";
} else {
    $select = "schedulerid = '". $scheduler->id ."' AND teacherid = '{$USER->id}'";
    $view = 'myappointments';
}
$sqlcount = count_records_select('scheduler_slots', $select);

if (($offset == '') && ($sqlcount > 25)){
    $offsetcount = count_records_select('scheduler_slots', $select." AND starttime < '".strtotime('now')."'");
    $offset = floor($offsetcount/25);
}

// More compatible way to do it :

$slots = get_records_select('scheduler_slots', $select, 'starttime', '*', $offset * 25, 25);
if ($slots){
    foreach(array_keys($slots) as $slotid){
        $slots[$slotid]->isappointed = count_records('scheduler_appointment', 'slotid', $slotid);
        $slots[$slotid]->isattended = record_exists('scheduler_appointment', 'slotid', $slotid, 'attended', 1);
    }
}

$straddsession = get_string('addsession', 'scheduler');
$straddsingleslot = get_string('addsingleslot', 'scheduler');
$strdownloadexcel = get_string('downloadexcel', 'scheduler');

/// some slots already exist
if ($slots){
    // print instructions and button for creating slots
    print_simple_box_start('center', '', '');
    print_string('addslot', 'scheduler');

    // print add session button
    $strdeleteallslots = get_string('deleteallslots', 'scheduler');
    $strdeleteallunusedslots = get_string('deleteallunusedslots', 'scheduler');
    $strdeleteunusedslots = get_string('deleteunusedslots', 'scheduler');
    $strdeletemyslots = get_string('deletemyslots', 'scheduler');
    $strstudents = get_string('students', 'scheduler');
    $displaydeletebuttons = 1;
    echo '<center>';
    include "commands.html";
    echo '</center>';        
    print_simple_box_end();
    
    // prepare slots table
    if ($view == 'myappointments'){
        $table->head  = array ('', $strdate, $strstart, $strend, $strstudents, $straction);
        $table->align = array ('CENTER', 'LEFT', 'LEFT', 'CENTER', 'CENTER', 'CENTER', 'LEFT', 'CENTER');
        $table->width = '80%';
    } else {
        $table->head  = array ('', $strdate, $strstart, $strend, $strstudents, format_string($scheduler->staffrolename), $straction);
        $table->align = array ('CENTER', 'LEFT', 'LEFT', 'CENTER', 'CENTER', 'CENTER', 'LEFT', 'LEFT', 'CENTER');
        $table->width = '80%';
    }
    $offsetdatemem = '';
    foreach($slots as $slot) {
        if (!$slot->isappointed && $slot->starttime + (60 * $slot->duration) < time()) {
            // This slot is in the past and has not been chosen by any student, so delete
            delete_records('scheduler_slots', 'id', $slot->id);
            continue;
        }

        /// Parameter $local in scheduler_userdate and scheduler_usertime added by power-web.at
        /// When local Time or Date is needed the $local Param must be set to 1 
        $offsetdate = scheduler_userdate($slot->starttime,1);
        $offsettime = scheduler_usertime($slot->starttime,1);
        $endtime = scheduler_usertime($slot->starttime + ($slot->duration * 60),1);

        /// make a slot select box 
        if ($USER->id == $slot->teacherid || has_capability('mod/scheduler:manageallappointments', $context)){
            $selectcheck = "<input type=\"checkbox\" id=\"sel_{$slot->id}\" name=\"sel_{$slot->id}\" onclick=\"document.forms['deleteslotsform'].items.value = toggleListState(document.forms['deleteslotsform'].items.value, 'sel_{$slot->id}', '{$slot->id}');\" />";
        } else {
            $selectcheck = '';
        }

        // slot is appointed
        $studentArray = array();
        if ($slot->isappointed) {
            $appointedstudents = get_records('scheduler_appointment', 'slotid', $slot->id);
            $studentArray[] = "<form name=\"appointementseen_{$slot->id}\" method=\"post\" action=\"view.php\">";
            $studentArray[] = "<input type=\"hidden\" name=\"id\" value=\"".$cm->id."\" />";
            $studentArray[] = "<input type=\"hidden\" name=\"slotid\" value=\"".$slot->id."\" />";
            $studentArray[] = "<input type=\"hidden\" name=\"what\" value=\"saveseen\" />";
            $studentArray[] = "<input type=\"hidden\" name=\"view\" value=\"".$view."\" />";
            foreach($appointedstudents as $appstudent){
                 $student = get_record('user', 'id', $appstudent->studentid);
                 $picture = print_user_picture($appstudent->studentid, $course->id, $student->picture, 0, true, true);
                 $name = "<a href=\"view.php?what=viewstudent&amp;id={$cm->id}&amp;studentid={$student->id}&amp;course={$scheduler->course}&amp;order=DESC\">".fullname($student).'</a>';

                 /// formatting grade
                 $grade = scheduler_format_grade($scheduler, $appstudent->grade, true);
 
                 if ($USER->id == $slot->teacherid || has_capability('mod/scheduler:manageallappointments', $context)){
                      $checked = ($appstudent->attended) ? 'checked="checked"' : '' ;
                      $checkbox = "<input type=\"checkbox\" name=\"seen[]\" value=\"{$appstudent->id}\" {$checked} />";
                } else {
                    // same thing but no link
                    if ($appstudent->attended == 1) {
                        $checkbox .= '<img src="pix/ticked.gif" border="0">';
                    } else {
                        $checkbox .= '<img src="pix/unticked.gif" border="0">';
                    }
                }
                $studentArray[] = "$checkbox $picture $name $grade<br/>";
            }
            $studentArray[] = "<a href=\"javascript:document.forms['appointementseen_{$slot->id}'].submit();\">".get_string('saveseen','scheduler').'</a>';
            $studentArray[] = "</form>";
        } else {
            // slot is free
            $picture = '';
            $name = '';
            $checkbox = '';
        }

        $actions = '<span style="font-size: x-small;">';
        if ($USER->id == $slot->teacherid || has_capability('mod/scheduler:manageallappointments', $context)){

            $strdelete = get_string('delete');
            $stredit = get_string('move','scheduler');
            $strattended = get_string('attended','scheduler');
            $strnonexclusive = get_string('isnonexclusive', 'scheduler');
            $strallowgroup = get_string('allowgroup', 'scheduler');
            $strforbidgroup = get_string('forbidgroup', 'scheduler');
            $strrevoke = get_string('revoke', 'scheduler');
            $strreused = get_string('setreused', 'scheduler');
            $strunreused = get_string('setunreused', 'scheduler');

            $actions .= "<a href=\"view.php?what=deleteslot&amp;id={$cm->id}&amp;slotid={$slot->id}&amp;view={$view}&amp;from={$from}\" title=\"{$strdelete}\"><img src=\"{$CFG->pixpath}/t/delete.gif\" alt=\"{$strdelete}\" /></a>";
            $actions .= "&nbsp;<a href=\"view.php?what=updateslot&amp;id={$cm->id}&amp;slotid={$slot->id}&amp;view={$view}&amp;from={$from}\" title=\"{$stredit}\"><img src=\"{$CFG->pixpath}/t/edit.gif\" alt=\"{$stredit}\" /></a>";
            if ($slot->isattended){
                $actions .= "&nbsp;<img src=\"{$CFG->pixpath}/c/group.gif\" title=\"{$strattended}\" />";
            } else {
                if ($slot->isappointed > 1){
                    $actions .= "&nbsp;<img src=\"{$CFG->pixpath}/c/group.gif\" title=\"{$strnonexclusive}\" />";
                } else {
                    if ($slot->exclusivity == 1){
                        $actions .= "&nbsp;<a href=\"view.php?what=allowgroup&amp;id={$cm->id}&amp;slotid={$slot->id}&amp;view={$view}&amp;from={$from}\" title=\"{$strallowgroup}\"><img src=\"{$CFG->pixpath}/t/groupn.gif\" alt=\"{$strallowgroup}\" /></a>";
                    } else {
                        $actions .= "&nbsp;<a href=\"view.php?what=forbidgroup&amp;id={$cm->id}&amp;slotid={$slot->id}&amp;view={$view}&amp;from={$from}\" title=\"{$strforbidgroup}\"><img src=\"{$CFG->pixpath}/t/groupv.gif\" alt=\"{$strforbidgroup}\" /></a>";
                    }
                }
            }
            if ($slot->isappointed){
                $actions .= "&nbsp;<a href=\"view.php?what=revokeall&amp;id={$cm->id}&amp;slotid={$slot->id}&amp;view={$view}&amp;from={$from}\" title=\"{$strrevoke}\"><img src=\"{$CFG->pixpath}/s/no.gif\" alt=\"{$strrevoke}\" /></a>";
            }
        } else {
            // just signal group status
            if ($slot->isattended){
                $actions .= "&nbsp;<img src=\"{$CFG->pixpath}/c/group.gif\" title=\"{$strattended}\" />";
            } else {
                if ($slot->isappointed > 1){
                    $actions .= "&nbsp;<img src=\"{$CFG->pixpath}/c/group.gif\" title=\"{$strnonexclusive}\" />";
                } else {
                    if ($slot->exclusivity == 1){
                        $actions .= "&nbsp;<img src=\"{$CFG->pixpath}/t/groupn.gif\" title=\"{$strallowgroup}\" />";
                    } else {
                        $actions .= "&nbsp;<img src=\"{$CFG->pixpath}/t/groupv.gif\" title=\"{$strforbidgroup}\" />";
                    }
                }
            }
        }
        if ($slot->exclusivity > 1){
            $actions .= ' ('.$slot->exclusivity.')';
        }
        if ($slot->reuse){
            $actions .= "&nbsp;<a href=\"view.php?what=unreuse&amp;id={$cm->id}&amp;slotid={$slot->id}&amp;view={$view}&amp;from={$from}\" title=\"{$strunreused}\" ><img src=\"pix/volatile_shadow.gif\" alt=\"{$strunreused}\" border=\"0\" /></a>";
        } else {
            $actions .= "&nbsp;<a href=\"view.php?what=reuse&amp;id={$cm->id}&amp;slotid={$slot->id}&amp;view={$view}&amp;from={$from}\" title=\"{$strreused}\" ><img src=\"pix/volatile.gif\" alt=\"{$strreused}\" border=\"0\" /></a>";
        }
        $actions .= '</span>';
        if($view == 'myappointments'){
            $table->data[] = array ($selectcheck, ($offsetdate == $offsetdatemem) ? '' : $offsetdate, $offsettime, $endtime, implode("\n",$studentArray), $actions);
        } else {
            $teacherlink = "<a href=\"$CFG->wwwroot/user/view.php?id={$slot->teacherid}\">".fullname(get_record('user', 'id', $slot->teacherid))."</a>";
            $table->data[] = array ($selectcheck, ($offsetdate == $offsetdatemem) ? '' : $offsetdate, $offsettime, $endtime, implode("\n",$studentArray), $teacherlink, $actions);
        }
        $offsetdatemem = $offsetdate;
    }

    // print slots table
    print_heading(get_string('slots' ,'scheduler'));
    print_table($table);
?>
<center>
<table width="80%"> 
    <tr>
        <td align="left">
            <script src="<?php echo "{$CFG->wwwroot}/mod/scheduler/scripts/listlib.js" ?>"></script>
            <form name="deleteslotsform" style="display : inline">
            <input type="hidden" name="id" value="<?php p($cm->id) ?>" />
            <input type="hidden" name="view" value="<?php echo $view ?>" />
            <input type="hidden" name="what" value="deleteslots" />
            <input type="hidden" name="items" value="" />
            </form>
            <a href="javascript:document.forms['deleteslotsform'].submit()"><?php print_string('deleteselection','scheduler') ?></a>
            <br />
        </td>
    </tr>
</table>

<?php
    if ($sqlcount > 25){
        echo "Page : ";
        $pagescount = ceil($sqlcount/25);
        for ($n = 0; $n < $pagescount; $n ++){
            if ($n == $offset){
                echo ($n+1).' ';
            } else {
                echo "<a href=\"view.php?id={$cm->id}&amp;view={$view}&amp;offset={$n}&from={$from}\">".($n+1)."</a> ";
            }
        }
    }

    echo '</center>';

    // Instruction for teacher to click Seen box after appointment
    echo '<br /><center>' . get_string('markseen', 'scheduler') . '</center>';

} else if ($action != 'addsession') { 
    /// There are no slots, should the teacher be asked to make some
    print_simple_box_start('center', '', '');
    print_string('welcomenewteacher', 'scheduler');
    echo '<center>';
    $displaydeletebuttons = 0;
    include "commands.html";
    echo '</center>';
    print_simple_box_end();
}

/// print table of outstanding appointer (students) 
?>
<center>
<table width="90%">
    <tr valign="top">
        <td width="50%">
<?php
print_heading(get_string('schedulestudents', 'scheduler'));

if ($cm->groupmembersonly){
    $groups = groups_get_all_groups($COURSE->id, 0, $cm->groupingid);
    $usergroups = array_keys($groups);
} else {
    $groups = get_groups($COURSE->id);
    $usergroups = '';
}

// get all ids
$studentids = get_users_by_capability ($context, 'mod/scheduler:appoint', 'u.id', 'u.lastname,u.firstname', '', '', $usergroups);
// get count of which are expected to appoint
scheduler_filter_appointed($scheduler, $studentids);
$allstudents = count($studentids);
$limit = ($allstudents > 50) ? 20 : '' ;
$allfilteredstudents = scheduler_get_filtered_appointable_count($studentids, $filter);
$students = scheduler_get_filtered_appointable_list($studentids, $filter, $from, $limit);

if (!$students) {
    $nostudentstr = get_string('noexistingstudents');
    if ($COURSE->id == SITEID){
        $nostudentstr .= '<br/>'.get_string('howtoaddstudents','scheduler');
    }
    notify($nostudentstr);
} else {
    $mtable->head  = array ('', $strname, $stremail, $strseen, $straction);
    $mtable->align = array ('CENTER','LEFT','LEFT','CENTER','CENTER');
    $mtable->width = array('', '', '', '', '');
    $mtable->data = array();
    // In $mailto the mailing list for reminder emails is built up
    $mailto = '<a href="mailto:';
    $date = usergetdate(time());

    foreach ($students as $student) {
        if (scheduler_has_slot($student->id, $scheduler, true, $scheduler->schedulermode == 'onetime')) {
        	continue;
        }

        $picture = print_user_picture($student->id, $course->id, $student->picture, false, true);
        $name = "<a href=\"../../user/view.php?id={$student->id}&amp;course={$scheduler->course}\">";
        $student->lastname = strtoupper($student->lastname);
        $name .= fullname($student);
        $name .= '</a>';
        $email = obfuscate_mailto($student->email);
        if (scheduler_has_slot($student->id, $scheduler, true, false) == 0){
            // student has never scheduled
            $mailto .= $student->email.', ';
        }
        $checkbox = "<a href=\"view.php?what=schedule&amp;id={$cm->id}&amp;studentid={$student->id}&amp;view={$view}&amp;seen=1\">";
        $checkbox .= '<img src="pix/unticked.gif" border="0" />';
        $checkbox .= '</a>';
        $actions = '<span style="font-size: x-small;">';
        $actions .= "<a href=\"view.php?what=schedule&amp;id={$cm->id}&amp;studentid={$student->id}&amp;view={$view}\">";
        $actions .= get_string('schedule', 'scheduler');
        $actions .= '</a></span>';
        $mtable->data[] = array($picture, $name, $email, $checkbox, $actions);
    }

    // dont print if allowed to book multiple appointments
    // There are students who still have to make appointments
    if (($num = count($mtable->data)) > 0) { 

        // Print number of students who still have to make an appointment
        print_heading(get_string('missingstudents', 'scheduler', $allstudents), 'center', 3);

        // Print links to print invitation or reminder emails
        $strinvitation = get_string('invitation', 'scheduler');
        $strreminder = get_string('reminder', 'scheduler');
        $mailto = rtrim($mailto, ', ');

        $subject = $strinvitation.': '.$scheduler->name;
        $body = $strinvitation.': '.$scheduler->name."\n\n";
        $body .= get_string('invitationtext', 'scheduler');
        $body .= "{$CFG->wwwroot}/mod/scheduler/view.php?id={$cm->id}";
        echo '<center>'.get_string('composeemail', 'scheduler').
            $mailto.'?subject='.htmlentities(rawurlencode($subject)).
            '&amp;body='.htmlentities(rawurlencode($body)).
            '"> '.$strinvitation.'</a> ';
        $maillist = ' &mdash; ';

        $subject = $strreminder . ': ' . $scheduler->name;
        $body = $strreminder . ': ' . $scheduler->name . "\n\n";
        $body .= get_string('remindertext', 'scheduler');
        $body .= "{$CFG->wwwroot}/mod/scheduler/view.php?id={$cm->id}";
        echo $mailto.'?subject='.htmlentities(rawurlencode($subject)).
            '&amp;body='.htmlentities(rawurlencode($body)).
            '"> '.$strreminder.'</a></center><br />';

        // print table of students who still have to make appointments
        
        print_string('filter', 'scheduler').' ';
        echo '<form name="studentfilter" style="display:inline">';
        echo '<input type="hidden" name="id" value="'.s($cm->id).'" />';
        echo '<input type="hidden" name="view" value="'.s($view).'" />';
        echo '<input type="hidden" name="what" value="'.s($action).'" />';
        echo '<input type="hidden" name="from" value="'.s($from).'" />';
        echo '<input type="hidden" name="offset" value="'.s($offset).'" />';
        echo '<input type="text" name="filter" value="'.s($filter).'" size="20" />';
        echo '<input type="submit" name="go_filter" value="'.get_string('dofilter', 'scheduler').'" />';
        echo '</form> ';
        
        if ($limit) echo get_string('pages', 'scheduler').' ';        
        if ($limit) scheduler_print_pagination($cm->id, $view, $offset, $from, $filter, $limit, $allfilteredstudents);
        
        print_table($mtable);

        if ($limit) scheduler_print_pagination($cm->id, $view, $offset, $from, $filter, $limit, $allfilteredstudents);
    } else {
        print_string('filter', 'scheduler').' ';
        echo '<form name="studentfilter" style="display:inline">';
        echo '<input type="hidden" name="id" value="'.s($cm->id).'" />';
        echo '<input type="hidden" name="view" value="'.s($view).'" />';
        echo '<input type="hidden" name="what" value="'.s($action).'" />';
        echo '<input type="hidden" name="from" value="'.s($from).'" />';
        echo '<input type="hidden" name="offset" value="'.s($offset).'" />';
        echo '<input type="text" name="filter" value="'.s($filter).'" size="20" />';
        echo '<input type="submit" name="go_filter" value="'.get_string('dofilter', 'scheduler').'" />';
        echo '</form>';

        notify(get_string('nostudents', 'scheduler'));
    }
}
?>
        </td>
<?php
if ($groupmode){
?>
        <td width="50%">
<?php

/// print table of outstanding appointer (groups) 

    print_heading(get_string('schedulegroups', 'scheduler'));
    
    if (empty($groups)){
        notify(get_string('nogroups', 'scheduler'));
    } else {
        $mtable->head  = array ('', $strname, $straction);
        $mtable->align = array ('CENTER','LEFT','CENTER');
        $mtable->width = array('', '', '');
        $mtable->data = array();
        foreach($groups as $group){
            $members = get_group_users($group->id, 'lastname', '', 'u.id, lastname, firstname, email, picture');
            if (empty($members)) continue;
            if (!scheduler_has_slot(implode(',', array_keys($members)), $scheduler, true, $scheduler->schedulermode == 'onetime')) {
                $actions = '<span style="font-size: x-small;">';
                $actions .= "<a href=\"view.php?what=schedulegroup&amp;id={$cm->id}&amp;groupid={$group->id}&amp;view={$view}\">";
                $actions .= get_string('schedule', 'scheduler');
                $actions .= '</a></span>';
                $groupmembers = array();
                foreach($members as $member){
                    $groupmembers[] = fullname($member);
                }
                $groupcrew = '['. implode(", ", $groupmembers) . ']';
                $mtable->data[] = array('', $groups[$group->id]->name.' '.$groupcrew, $actions);
            }
        }
        // print table of students who still have to make appointments
        if (!empty($mtable->data)){
            print_table($mtable);
        } else {
            notify(get_string('nogroups', 'scheduler'));
        }
    }
?>
        </td>
<?php
}
?>
    </tr>
</table>
</center>

<?php

	echo '<br/><center>';
	$opts['id'] = $course->id;
	print_single_button($CFG->wwwroot.'/course/view.php', $opts, get_string('return', 'scheduler'));
	echo '</center>';

?>