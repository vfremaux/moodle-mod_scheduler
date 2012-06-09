<?PHP // $Id: version.php,v 1.2 2011-12-26 22:25:08 vf Exp $

/**
* @package mod-scheduler
* @category mod
* @author Valery Fremaux > 1.8
*/

/////////////////////////////////////////////////////////////////////////////////
///  Code fragment to define the version of scheduler
///  This fragment is called by moodle_needs_upgrading() and /admin/index.php
/////////////////////////////////////////////////////////////////////////////////

$module->version  = 2011122500;  // The current module version (Date: YYYYMMDDXX)
$module->requires = 2004082300;  // Requires this Moodle version
$module->cron     = 60;           // Period for cron to check this module (secs)

?>
