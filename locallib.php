<?php
/**
 * @package    local
 * @subpackage course_validated
 * @copyright  2012-2013 Silecs {@link http://www.silecs.info/societe}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . "/local/up1_metadata/lib.php");
require_once($CFG->dirroot . "/local/roftools/roflib.php");

/**
 * @todo checkboxes multiple selections + dropdown menu
 * @todo master checkbox : all/none
 * @todo sort by clicking on column header ???
 */

/**
 *
 * @global type $DB
 * @param int $approbateurid ; 0 if none
 * @param int $validated = 0, 1, 2 ; 0=not yet validated ; 1=already validated ; 2=both
 * @param bool $permcheck if set, check the permission on a course by course basis for contextual supervalidators
 */
function get_id_courses_to_validate($approbateurid, $validated, $permcheck=false) {
    global $DB;

    $avaliderId = $DB->get_field('custom_info_field', 'id', array('objectname' => 'course', 'shortname' => 'up1avalider'));
    $datevalidId = $DB->get_field('custom_info_field', 'id', array('objectname' => 'course', 'shortname' => 'up1datevalid'));
    $approbateurpropidId = $DB->get_field('custom_info_field', 'id',
        array('objectname' => 'course', 'shortname' => 'up1approbateurpropid'));

    if ( ! ($avaliderId && $datevalidId && $approbateurpropidId) ) {
        throw new coding_exception('Erreur ! manque up1avalider ou up1datevalid ou up1approbateurid');
        // die ('Erreur ! manque up1avalider ou up1datevalid ou up1approbateurid');
        return;
    }
    $sql = "SELECT DISTINCT cd1.objectid FROM {custom_info_data} cd1 "     //cd1 = avalider (bool)
         . "JOIN {custom_info_data} cd2 ON (cd1.objectid=cd2.objectid) " ; //cd2 = datevalid
    if ($approbateurid) {
        $sql .= "JOIN {custom_info_data} cdq ON (cd1.objectid=cdq.objectid) " ; //cdq = approbateurpropid
    }
    $sql .= "WHERE cd1.fieldid=$avaliderId AND cd1.data=1 AND cd2.fieldid=$datevalidId ";
    if ($approbateurid) {
        $sql .= "AND cdq.fieldid=$approbateurpropidId AND cdq.data=$approbateurid " ;
    }
    if ($validated == 0) {
        $sql .= " AND cd2.data = 0 ";
    }
    if ($validated == 1) {
        $sql .= " AND cd2.data > 0 ";
    }
    $tabIdCourse = $DB->get_fieldset_sql($sql);
    $tabchecked = array();

    if ($permcheck) { // on vérifie les permissions de supervalidateur contextuel
        foreach($tabIdCourse as $crsid) {
            $crscontext = context_course::instance($crsid);
            if (has_capability('local/crswizard:supervalidator', $crscontext)) {
                $tabchecked[] = $crsid;
            }
        }
    } else {
        $tabchecked = $tabIdCourse;
    }
    $listeId='';
    if (count($tabchecked)) {
        $listeId = join(', ', $tabchecked);
    }
    return $listeId;
}

/**
 * Build the main table for course_validated
 * @global moodle_database $DB
 * @param integer $approbateurid
 * @param object $context system context
 * @param bool $permcheck if set, check the permissions for contextual supervalidators
 * @return \html_table
 */
function get_table_course_to_validate($approbateurid, $context, $permcheck=false) {
    global $DB;
    $etat = array(
        false => "En attente",
        true => "Approuvé"
    );

    $res = new html_table();
    $res->data = array();
    $count = 0;

    $courseids0 = get_id_courses_to_validate($approbateurid, 0, $permcheck);
    $dbcourses = array();
    if ($courseids0 != '') {
        $sql = "SELECT id, idnumber, shortname, fullname, startdate, visible "
             . " FROM {course} c WHERE id IN ($courseids0) ORDER BY id DESC ";
        $dbcourses = $DB->get_records_sql($sql);
    }
    $courseids1 = get_id_courses_to_validate($approbateurid, 1, $permcheck);
    if ($courseids1 != '') {
        $sql = "SELECT id, idnumber, shortname, fullname, startdate, visible "
             . " FROM {course} c WHERE id IN ($courseids1) ORDER BY id DESC ";
        $dbcourses1 = $DB->get_records_sql($sql);
        $dbcourses = array_merge($dbcourses, $dbcourses1);
    }

    foreach ($dbcourses as $dbcourse) {
        $count++;

        $row = new html_table_row();
        $row->cells[0] = new html_table_cell($count);
        $row->cells[0]->attributes = array('title' => '', 'class' => '');
        $row->cells[1] = $dbcourse->id;
        $url = new moodle_url('/course/view.php', array('id' => $dbcourse->id));
        $nomnorme = up1_meta_get_text($dbcourse->id, 'nomnorme');
        $row->cells[2] = new html_table_cell(html_writer::link($url, $dbcourse->fullname));
        $row->cells[2]->attributes = array('title' => $nomnorme, 'class' => '');
        $validated = up1_meta_get_text($dbcourse->id, 'datevalid') > 0;
        $row->cells[3] = new html_table_cell(action_icons($dbcourse->id, $validated, $dbcourse->visible, $context));
        $row->cells[3]->attributes = array('title' => '', 'class' => '');
        if ( ! $validated ) {
            $row->attributes = array('class' => 'highlight');
        }

        $demandeur = up1_meta_get_user($dbcourse->id, 'demandeurid');
        $url = new moodle_url('/user/profile.php', array('id' => $demandeur['id']));
        $row->cells[4] = new html_table_cell(html_writer::link($url, $demandeur['name']));
        $row->cells[4]->attributes = array('title' => '', 'class' => '');
        $adate = up1_meta_get_date($dbcourse->id, 'datedemande');
        $row->cells[5] = new html_table_cell($adate['date']);
        $row->cells[5]->attributes = array('title' => $adate['datetime'], 'class' => '');

        $approbateurprop = up1_meta_get_user($dbcourse->id, 'approbateurpropid');
        $approbateureff = up1_meta_get_user($dbcourse->id, 'approbateureffid');
        $row->cells[6] = new html_table_cell($approbateurprop['name']);
        $row->cells[7] = new html_table_cell($approbateureff['name']);
        $adate = up1_meta_get_date($dbcourse->id, 'datevalid');
        $row->cells[8] = new html_table_cell( (! empty($adate['date']) ? $adate['date'] : '<b>En attente</b>') );
        $row->cells[8]->attributes = array('title' => $adate['datetime'], 'class' => '');
        $rofname = up1_meta_get_text($dbcourse->id, 'rofname');
        if ( empty($rofname) ) { // rattachement catégories de cours
            $catpath = get_category_path_from_course($dbcourse->id);
            $row->cells[9] = new html_table_cell($catpath . tooltip_rattachements_hors_rof($catpath, $dbcourse->id));
            $row->cells[9]->attributes = array('class' => 'with-tooltip');
        } else { // rattachement ROF
            $roflinks = count(explode(';', up1_meta_get_text($dbcourse->id, 'rofid')));
            $row->cells[9] = new html_table_cell(
                    '(' . $roflinks . ') ' . $rofname . tooltip_rattachements_rof($dbcourse->id)
            );
            $row->cells[9]->attributes = array('class' => 'with-tooltip');
        }

        $res->data[] = $row;
    }

    return $res;
}

/**
 * returns the category "human-readable" path for a given category ($catid)
 * @global type $DB
 * @param int $catid or null
 * @param string separator
 * @return string "human-readable" path
 */
function get_category_path($catid, $separator = ' > ') {
    global $DB;
    $categories = $DB->get_records_menu('course_categories', null, '', 'id,name'); //** @todo persistent ?

    $catpath = $DB->get_field('course_categories', 'path', array('id' => $catid));
    $arraypath = array_filter(explode('/', $catpath));
    $res = "";
    foreach ($arraypath as $catid) {
        $res .= $separator . $categories[$catid];
    }
    return $res;
}

/**
 * returns the category "human-readable" path for a given course ($crsid) : use the parent category
 * @global type $DB
 * @param int $crsid
 * @param string separator
 * @return string "human-readable" path
 */
function get_category_path_from_course($crsid, $separator = ' > ') {
    global $DB;

    $crscontextpath = $DB->get_field('context', 'path', array('contextlevel'=>CONTEXT_COURSE, 'instanceid'=>$crsid) , MUST_EXIST);
    preg_match('/^(\/\d+)+\/\d+$/', $crscontextpath, $matches);
    $catcontext = substr($matches[1], 1);
    $sql = "SELECT cc.id FROM {course_categories} cc "
         . "JOIN {context} co ON (contextlevel=? AND co.instanceid=cc.id) "
         . "WHERE co.id = ?";
    $catid = $DB->get_field_sql($sql, array(CONTEXT_COURSECAT, $catcontext));
    return get_category_path($catid, $separator);
}


/**
 * build the div tootltip for rattachements ROF
 * @param int $crsid
 * @return string html <div>...</div> block
 */
function tooltip_rattachements_rof($crsid) {
    $pathids = explode(';', up1_meta_get_text($crsid, 'rofpathid'));
    $n = count($pathids);
    $res = '<div class="tooltip-content">' . "\n";
    $res .= $n . " rattachement" . ($n>1 ? 's' : '') . " ROF<br />\n<ol>\n";
    $pathprefix = get_category_path(get_config('local_crswizard','cas2_default_etablissement'));
    foreach ($pathids as $pathid) {
        $patharray = array_filter(explode('/', $pathid));
        $combined = rof_get_combined_path($patharray);
        $res .= "<li>" . $pathprefix . rof_format_path($combined, 'name', true, ' > ') . "</li>\n";
    }
    $res .= "</ol>\n</div>\n";
    return $res;
}

/**
 * build the div tootltip for rattachements HORS ROF
 * @param int $crsid
 * @return string html <div>...</div> block
 */
function tooltip_rattachements_hors_rof($catpath, $crsid) {

    $catids = array_filter(explode(';', up1_meta_get_text($crsid, 'categoriesbis')));
    $n = 1 + count($catids);
    $res = '<div class="tooltip-content">' . "\n";
    $res .= $n . " rattachement" . ($n>1 ? 's' : '') . " HORS ROF<br />\n<ol>\n";
    $res .= "<li><b>" . $catpath . "</b></li>\n";
    foreach ($catids as $catid) {
        $res .= "<li>" . get_category_path($catid) . "</li>\n";
    }
    $res .= "</ol>\n</div>\n";
    return $res;
}


/**
 * Prepare the content for the "action" table cell (icons from permissions)
 * @global type $DB
 * @global type $OUTPUT
 * @param int $crsid
 * @param bool $validated already validated ?
 * @param bool $visible visible (open) ? (otherwise, closed)
 * @param object $context system context
 * @return string
 */
function action_icons($crsid, $validated, $visible, $context) {
    global $DB, $OUTPUT;
    $res = '';
    $coursecontext = context_course::instance($crsid);
    $baseurl = new moodle_url('/local/course_validated/index.php');
    //$res .= html_writer::start_tag('div', array('class'=>'action'));

    // Approuver la demande
    if ( ! $validated ) {
        $url = new moodle_url($baseurl, array('validate' => $crsid));
        $res .= $OUTPUT->action_icon($url, new pix_icon('i/tick_green_small', 'Approuver la demande d\'ouverture'));
    }
    // Modif. paramètres cours
    if (has_capability('moodle/course:update', $coursecontext)) {
		$url = new moodle_url('/course/edit.php', array('id' => $crsid));
		$res .= $OUTPUT->action_icon($url, new pix_icon('t/edit', 'Modifier les paramètres de l\'espace de cours'));
		$res .= '&nbsp;';
    }
    // Supprimer le cours
    if (can_delete_course($crsid)) {
		$url = new moodle_url('/course/delete.php', array('id' => $crsid));
        $res .= $OUTPUT->action_icon($url, new pix_icon('t/delete', 'Supprimer l\'espace de cours'));
        $res .= '&nbsp;';
    }
    // Ouvrir / fermer le cours
    if (has_capability('moodle/course:visibility', $coursecontext)) {
		if ($visible) {
			$url = new moodle_url($baseurl, array('hide' => $crsid));
            $res .= $OUTPUT->action_icon($url, new pix_icon('t/hide', 'Ne pas ouvrir l\'espace de cours aux étudiants'));
        } else {
			$url = new moodle_url($baseurl, array('show' => $crsid));
            $res .= $OUTPUT->action_icon($url, new pix_icon('t/show', 'Ouvrir l\'espace de cours aux étudiants'));
        }
        $res .= '&nbsp;';
    }

    if (has_capability('local/crswizard:supervalidator', $context)) {
        // Tableau de bord
        $url = new moodle_url('/local/courseboard/view.php', array('id' => $crsid));
        $res .= $OUTPUT->action_icon($url, new pix_icon('i/settings', 'Afficher le tableau de bord'));
    } else { // approbateur lambda
        // Synopsis
        $url = new moodle_url('/report/up1synopsis/index.php', array('id' => $crsid));
        $res .= $OUTPUT->action_icon($url, new pix_icon('i/info', 'Afficher le synopsis du cours'));
        // Demande d'assistance
        $helpuser = get_config('local_crswizard', 'helpdesk_user');
        if ( isset($helpuser) ) {
            $userid = $DB->get_field('user', 'id', array('username' => $helpuser));
            if ($userid) {
                $url = new moodle_url('/message/index.php', array('id' => $userid));
                $res .= ' ' . $OUTPUT->action_icon($url, new pix_icon('a/help', 'Demander de l\'assistance'));
            }
        }
    }

	//$res .= html_writer::end_tag('div');
    return $res;
}


function get_table_course_header() {
    $headings = array('', 'crs.id', get_string('fullnamecourse'), 'Actions', 'Demandeur', 'Date demande',
        'Approbateur proposé', 'Approbateur effectif', 'Date approbation', 'Rattachement(s) de l\'espace');
    $row = array();
    foreach ($headings as $h) {
        $cell = new html_table_cell($h);
        $cell->header = true;
        $row[] = $cell;
    }
    return array($row);
}


// ACTION functions

/**
 * Set a course visible or invisible
 * copied from course/category.php l. 132-150
 * @param int $show course id to become visible
 * @param int $hide course id
 */

function show_or_hide($show, $hide) {
	global $DB;
	if (!empty($hide)) {
		$course = $DB->get_record('course', array('id' => $hide));
        $visible = 0;
        $action = 'hide';
    } else {
		$course = $DB->get_record('course', array('id' => $show));
        $visible = 1;
        $action = 'show';
	}

    if ($course) {
		$coursecontext = context_course::instance($course->id);
        require_capability('moodle/course:visibility', $coursecontext);
        // Set the visibility of the course. we set the old flag when user manually changes visibility of course.
        $DB->update_record('course', array('id' => $course->id, 'visible' => $visible, 'visibleold' => $visible, 'timemodified' => time()));
    }
}


/**
 * validate a course (set the custom data up1validatedate to time()
 * @param int $crsid id of course to validate
 * @return boolean
 */
function validate_course($crsid) {
	global $DB, $USER;

	$iddate = up1_meta_get_id($crsid, 'datevalid');
    $idwho = up1_meta_get_id($crsid, 'approbateureffid');

	if ( ! ($iddate && $idwho)) {
        throw new coding_exception('Erreur ! manque up1datevalid ou up1approbateureffid pour le cours ' . $crsid);
        return false;
    }
    $DB->update_record('custom_info_data', array('id' => $iddate, 'data' => time()));
    $DB->update_record('custom_info_data', array('id' => $idwho, 'data' => $USER->id));
    send_notification_validation($crsid);
    return true;
}

/**
 * send an internal message when a course has been validated
 * @param int $crsid id of validated course
 * @return boolean
 */
function send_notification_validation($crsid) {
    global $DB;

    $msg = message_notification_validation($crsid);
    $summary = $DB->get_field('crswizard_summary', 'txt', array('courseid' => $crsid)); //récapitulatif
    $DB->delete_records('crswizard_summary', array('courseid' => $crsid));

    // max. 3 recipients : demandeur, approbateurprop, helpdesk_user
    $demandeur = up1_meta_get_user($crsid, 'demandeurid', false);
    $recipients = array($demandeur['id']);
    $approb = up1_meta_get_user($crsid, 'approbateurpropid', false);
    if ($approb['id']) {
        $recipients[] = $approb['id'];
    }
    $helpuser = get_config('local_crswizard', 'helpdesk_user');
    if (isset($helpuser)) {
        $userid = $DB->get_field('user', 'id', array('username' => $helpuser));
        if ($userid) {
            $recipients[] = $userid;
        }
    }
    $eventdata = new stdClass();
    $eventdata->component = 'moodle';
    $eventdata->name = 'courserequested';
    $eventdata->userfrom = 2;
    $eventdata->subject = $msg['subject'];
    $eventdata->fullmessageformat = FORMAT_PLAIN;   // text format
    $eventdata->fullmessage = $msg['body'] . "\n" . ($summary ? $summary : '');
    $eventdata->fullmessagehtml = '';   //$messagehtml;
    $eventdata->smallmessage = $msg['body'] . "\n" . ($summary ? $summary : ''); // USED BY DEFAULT !
    foreach ($recipients as $recipient) {
        $eventdata->userto = $recipient;
        $res = message_send($eventdata);
    }
    return true;
}

/**
 * compose the validation notification message
 * @param int $crsid
 * @return array $res = array('subject' => '...', 'body' => '...')
 */
function message_notification_validation ($crsid) {
    global $CFG;
    $site  = get_site();


    $res['subject'] =
        '[' . $site->shortname . "] "
        . "Validation espace n° " . $crsid . " : "
        . up1_meta_get_text($crsid, 'nomnorme', false);

    $demandeur = up1_meta_get_user($crsid, 'demandeurid');
    $approbateur = up1_meta_get_user($crsid, 'approbateureffid');
    $res['body'] =
    "Bonjour, \n\n"
    . "L’espace de cours " . up1_meta_get_text($crsid, 'nomnorme', false)
    . ", créé par " . $demandeur['name'] . " sur la plateforme " . $CFG->wwwroot
    . ", a été validé par " . $approbateur['name'] . "."
    . "\n\n"
    . "IMPORTANT : Pour rendre cet espace accessible aux étudiants, il est nécessaire "
    . "que l'une des personnes disposant de droits de contribution clique sur le bouton "
    . '"Ouvrir le cours".'
    . "\n\n"
    . "Vous trouverez à cette adresse " . $CFG->wwwroot . "/guide "
    . "un ensemble de ressources d'aide et de conseil sur les principales fonctionnalités disponibles."
    . "\n\n"
    . "N'hésitez pas à contacter l'un des membres de l'équipe du service TICE :\n"
    . "- si vous souhaitez participer à l’une des sessions de prise en mains régulièrement organisées ;\n"
    . "- si vous rencontrez une difficulté ou si vous constatez une anomalie de fonctionnement.\n"
    . "\n\n"
    . "Conservez ce message. Le récapitulatif technique présenté ci-après "
    . "peut vous être utile, notamment pour dialoguer avec l'équipe d'assistance."
    . "\n\n"
    . "Cordialement,\n"
    . "\n"
    . "L’assistance EPI\n"
    . "\n"
    . "Service TICE - Pôle Ingénieries pédagogique et de formation\n"
    . "Université Paris 1 Panthéon-Sorbonne\n"
    . "Courriel : assistance-epi@univ-paris1.fr\n" ;

    return $res;
}

