<?php

/*
 +-----------------------------------------------------------------------+
 | steps/mail/search.inc                                                 |
 |                                                                       |
 | This file is part of the Roundcube Webmail client                     |
 | Copyright (C) 2005-2013, The Roundcube Dev Team                       |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Mail messages search action                                         |
 +-----------------------------------------------------------------------+
 | Author: Benjamin Smith <defitro@gmail.com>                            |
 |         Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+
*/

$REMOTE_REQUEST = TRUE;

@set_time_limit(170);  // extend default max_execution_time to ~3 minutes

// reset list_page and old search results
$RCMAIL->storage->set_page(1);
$RCMAIL->storage->set_search_set(NULL);
$_SESSION['page'] = 1;

// using encodeURI with javascript "should" give us
// a correctly encoded query string
$imap_charset = RCUBE_CHARSET;

// get search string
$str     = rcube_utils::get_input_value('_q', rcube_utils::INPUT_GET, true);
$mbox    = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_GET, true);
$filter  = rcube_utils::get_input_value('_filter', rcube_utils::INPUT_GET);
$headers = rcube_utils::get_input_value('_headers', rcube_utils::INPUT_GET);
$scope   = rcube_utils::get_input_value('_scope', rcube_utils::INPUT_GET);
$subject = array();

$filter         = trim($filter);
$search_request = md5($mbox.$filter.$str);

// add list filter string
$search_str = $filter && $filter != 'ALL' ? $filter : '';

$_SESSION['search_filter'] = $filter;

// Check the search string for type of search
if (preg_match("/^from:.*/i", $str)) {
    list(,$srch) = explode(":", $str);
    $subject['from'] = "HEADER FROM";
}
else if (preg_match("/^to:.*/i", $str)) {
    list(,$srch) = explode(":", $str);
    $subject['to'] = "HEADER TO";
}
else if (preg_match("/^cc:.*/i", $str)) {
    list(,$srch) = explode(":", $str);
    $subject['cc'] = "HEADER CC";
}
else if (preg_match("/^bcc:.*/i", $str)) {
    list(,$srch) = explode(":", $str);
    $subject['bcc'] = "HEADER BCC";
}
else if (preg_match("/^subject:.*/i", $str)) {
    list(,$srch) = explode(":", $str);
    $subject['subject'] = "HEADER SUBJECT";
}
else if (preg_match("/^body:.*/i", $str)) {
    list(,$srch) = explode(":", $str);
    $subject['body'] = "BODY";
}
else if (strlen(trim($str))) {
    if ($headers) {
        foreach (explode(',', $headers) as $header) {
            if ($header == 'text') {
                // #1488208: get rid of other headers when searching by "TEXT"
                $subject = array('text' => 'TEXT');
                break;
            }
            else {
                $subject[$header] = ($header != 'body' ? 'HEADER ' : '') . strtoupper($header);
            }
        }

        // save search modifiers for the current folder to user prefs
        $search_mods        = rcmail_search_mods();
        $search_mods[$mbox] = array_fill_keys(array_keys($subject), 1);

        $RCMAIL->user->save_prefs(array('search_mods' => $search_mods));
    }
    else {
        // search in subject by default
        $subject['subject'] = 'HEADER SUBJECT';
    }
}

$search = isset($srch) ? trim($srch) : trim($str);

if (!empty($subject)) {
    $search_str .= str_repeat(' OR', count($subject)-1);
    foreach ($subject as $sub) {
        $search_str .= ' ' . $sub . ' ' . rcube_imap_generic::escape($search);
    }

    // search all, current or subfolders folders
    if ($scope == 'all') {
        $mboxes = $RCMAIL->storage->list_folders_subscribed('', '*', 'mail', null, true);
        natcasesort($mboxes);  // we want natural alphabetic sorting of folders in the result set
    }
    else if ($scope == 'sub') {
        $mboxes = $RCMAIL->storage->list_folders_subscribed($mbox, '*', 'mail');
        if ($mbox != 'INBOX' && $mboxes[0] == 'INBOX')
            array_shift($mboxes);
    }
}

$search_str  = trim($search_str);
$sort_column = rcmail_sort_column();

// execute IMAP search
if ($search_str) {
    $RCMAIL->storage->search($mboxes, $search_str, $imap_charset, $sort_column);
}

// Get the headers
$result_h = $RCMAIL->storage->list_messages($mbox, 1, $sort_column, rcmail_sort_order());
$count    = $RCMAIL->storage->count($mbox, $RCMAIL->storage->get_threading() ? 'THREADS' : 'ALL');

// save search results in session
if (!is_array($_SESSION['search'])) {
    $_SESSION['search'] = array();
}

if ($search_str) {
    $_SESSION['search'] = $RCMAIL->storage->get_search_set();
    $_SESSION['last_text_search'] = $str;
}
$_SESSION['search_request'] = $search_request;
$_SESSION['search_scope'] = $scope;

// Make sure we got the headers
if (!empty($result_h)) {
    rcmail_js_message_list($result_h, false);
    if ($search_str) {
        $OUTPUT->show_message('searchsuccessful', 'confirmation', array('nr' => $RCMAIL->storage->count(NULL, 'ALL')));
    }

    // remember last HIGHESTMODSEQ value (if supported)
    // we need it for flag updates in check-recent
    $data = $RCMAIL->storage->folder_data($mbox_name);
    if (!empty($data['HIGHESTMODSEQ'])) {
        $_SESSION['list_mod_seq'] = $data['HIGHESTMODSEQ'];
    }
}
// handle IMAP errors (e.g. #1486905)
else  if ($err_code = $RCMAIL->storage->get_error_code()) {
    $RCMAIL->display_server_error();
}
else {
    $OUTPUT->show_message('searchnomatch', 'notice');
}

// update message count display
$OUTPUT->set_env('search_request', $search_str ? $search_request : '');
$OUTPUT->set_env('threading', $RCMAIL->storage->get_threading());
$OUTPUT->set_env('messagecount', $count);
$OUTPUT->set_env('pagecount', ceil($count/$RCMAIL->storage->get_pagesize()));
$OUTPUT->set_env('exists', $RCMAIL->storage->count($mbox_name, 'EXISTS'));
$OUTPUT->command('set_rowcount', rcmail_get_messagecount_text($count, 1), $mbox);
$OUTPUT->set_pagetitle($RCMAIL->gettext(array('name' => 'searchfor', 'vars' => array('q' => $str))));
$OUTPUT->send();
