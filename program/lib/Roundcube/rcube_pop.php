<?php

/*
 +-----------------------------------------------------------------------+
 | This file is part of the Roundcube Webmail client                     |
 | Copyright (C) 2008-2012, The Roundcube Dev Team                       |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   POP Storage Engine                                                  |
 +-----------------------------------------------------------------------+
 | Author: Taiki Sugawara <roundcube@gmail.com>                          |
 +-----------------------------------------------------------------------+
*/

/**
 * Interface class for accessing an POP server
 *
 * @package    Framework
 * @subpackage Storage
 * @author     Taiki Sugawara <buzz.taiki@gmail.com>
 */
class rcube_pop extends rcube_storage
{
    /**
     * Instance of rcube_imap_generic
     *
     * @var rcube_imap_generic
     */
    public $conn;

    /**
     * Instance of rcube_imap_cache
     *
     * @var rcube_imap_cache
     */
    protected $mcache;

    /**
     * Instance of rcube_cache
     *
     * @var rcube_cache
     */
    protected $cache;

    /**
     * Internal (in-memory) cache
     *
     * @var array
     */
    protected $icache = array();

    protected $list_page = 1;
    protected $namespace;
    protected $sort_field = '';
    protected $sort_order = 'DESC';
    protected $struct_charset;
    protected $uid_id_map = array();
    protected $msg_headers = array();
    protected $search_set;
    protected $search_string = '';
    protected $search_charset = '';
    protected $search_sort_field = '';
    protected $search_threads = false;
    protected $search_sorted = false;
    protected $options = array('auth_type' => 'check');
    protected $caching = false;
    protected $messages_caching = false;
    protected $threading = false;
    protected $connected = false;


    /**
     * Object constructor.
     */
    public function __construct()
    {
        $this->conn = new Net_POP3();
    }


    /**
     * Magic getter for backward compat.
     *
     * @deprecated.
     */
    public function __get($name)
    {
        if (isset($this->{$name})) {
            return $this->{$name};
        }
    }


    /**
     * Connect to an POP server
     *
     * @param  string   $host    Host to connect
     * @param  string   $user    Username for POP account
     * @param  string   $pass    Password for POP account
     * @param  integer  $port    Port to connect to
     * @param  string   $use_ssl SSL schema (either ssl or tls) or null if plain connection
     *
     * @return boolean  TRUE on success, FALSE on failure
     */
    public function connect($host, $user, $pass, $port=110, $use_ssl=null)
    {
        $this->log('DO CONNECT');
        if ($use_ssl) {
            rcube::raise_error(array('code' => 403, 'type' => 'pop',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "not yet implemented ssl"), true, false);
            $port = 110;
        }

        $this->options['port'] = $port;

        if ($this->options['debug']) {
            $this->set_debug(true);

            $this->options['ident'] = array(
                'name'    => 'Roundcube',
                'version' => RCUBE_VERSION,
                'php'     => PHP_VERSION,
                'os'      => PHP_OS,
                'command' => $_SERVER['REQUEST_URI'],
            );
        }

        $attempt = 0;
        $connected = false;
        do {
            $data = rcube::get_instance()->plugins->exec_hook('storage_connect',
                array_merge($this->options, array('host' => $host, 'user' => $user,
                    'attempt' => ++$attempt)));

            if (!empty($data['pass'])) {
                $pass = $data['pass'];
            }

            $connected = $this->conn->connect($data['host'], $data['port']);

            if ($connected) {
                $res = $this->conn->login($data['user'], $pass, 'USER');
                if (PEAR::isError($res)) {
                    $this->log(sprintf('could not login: %s', $res));
                    $this->conn->disconnect();
                    $this->connected = false;
                    return false;
                }
            }
        } while(!$connected && $data['retry']);

        $config = array(
            'host'     => $data['host'],
            'user'     => $data['user'],
            'password' => $pass,
            'port'     => $port,
            'ssl'      => $use_ssl,
        );

        $this->options      = array_merge($this->options, $config);
        $this->connected = $connected;
        $this->connect_done = true;

        if ($this->connected) {
            return true;
        }
        // write error log
        else {
            if ($pass && $user) {
                $message = sprintf("Login failed for %s from %s.",
                    $user, rcube_utils::remote_ip());

                rcube::raise_error(array('code' => 403, 'type' => 'pop',
                    'file' => __FILE__, 'line' => __LINE__,
                    'message' => $message), true, false);
            }
        }

        return false;
    }


    /**
     * Close IMAP connection.
     * Usually done on script shutdown
     */
    public function close()
    {
        $this->log('DO CLOSE');
        $this->conn->disconnect();
        $this->connected = false;
        if ($this->mcache) {
            $this->mcache->close();
        }
    }


    /**
     * Check connection state, connect if not connected.
     *
     * @return bool Connection state.
     */
    public function check_connection()
    {
        $this->log(sprintf('DO CHECK_CONNECTION: %s, %s, %s',
        $this->connected,
        $this->connect_done, $this->options['user']));

        // Establish connection if it wasn't done yet
        if (!$this->connect_done && !empty($this->options['user'])) {
            return $this->connect(
                $this->options['host'],
                $this->options['user'],
                $this->options['password'],
                $this->options['port'],
                $this->options['ssl']
            );
        }

        return $this->is_connected();
    }


    /**
     * Checks IMAP connection.
     *
     * @return boolean  TRUE on success, FALSE on failure
     */
    public function is_connected()
    {
        $this->log(sprintf('DO IS_CONNECTED: %s', $this->connected));
        return $this->connected;
    }


    /**
     * Returns code of last error
     *
     * @return int Error code
     */
    public function get_error_code()
    {
        // TODO
        return 0;
    }


    /**
     * Returns text of last error
     *
     * @return string Error string
     */
    public function get_error_str()
    {
        // TODO
        return "";
    }


    /**
     * Returns code of last command response
     *
     * @return int Response code
     */
    public function get_response_code()
    {
        // TODO
        return self::UNKNOWN;
    }


    /**
     * Activate/deactivate debug mode
     *
     * @param boolean $dbg True if IMAP conversation should be logged
     */
    public function set_debug($dbg = true)
    {
        $this->options['debug'] = $dbg;
    }


    /**
     * Set internal folder reference.
     * All operations will be perfomed on this folder.
     *
     * @param  string $folder Folder name
     */
    public function set_folder($folder)
    {
        $this->folder = $folder;
    }


    /**
     * Save a search result for future message listing methods
     *
     * @param  array  $set  Search set, result from rcube_imap::get_search_set():
     *                      0 - searching criteria, string
     *                      1 - search result, rcube_result_index|rcube_result_thread
     *                      2 - searching character set, string
     *                      3 - sorting field, string
     *                      4 - true if sorted, bool
     */
    public function set_search_set($set)
    {
        $set = (array)$set;

        $this->search_string     = $set[0];
        $this->search_set        = $set[1];
        $this->search_charset    = $set[2];
        $this->search_sort_field = $set[3];
        $this->search_sorted     = $set[4];
        $this->search_threads    = is_a($this->search_set, 'rcube_result_thread');
    }


    /**
     * Return the saved search set as hash array
     *
     * @return array Search set
     */
    public function get_search_set()
    {
        if (empty($this->search_set)) {
            return null;
        }

        return array(
            $this->search_string,
            $this->search_set,
            $this->search_charset,
            $this->search_sort_field,
            $this->search_sorted,
        );
    }


    /**
     * Returns the IMAP server's capability.
     *
     * @param   string  $cap Capability name
     *
     * @return  mixed   Capability value or TRUE if supported, FALSE if not
     */
    public function get_capability($cap)
    {
        return false;
    }


    /**
     * Checks the PERMANENTFLAGS capability of the current folder
     * and returns true if the given flag is supported by the IMAP server
     *
     * @param   string  $flag Permanentflag name
     *
     * @return  boolean True if this flag is supported
     */
    public function check_permflag($flag)
    {
        return false;
    }


    /**
     * Returns PERMANENTFLAGS of the specified folder
     *
     * @param  string $folder Folder name
     *
     * @return array Flags
     */
    public function get_permflags($folder)
    {
        return array();
    }


    /**
     * Returns the delimiter that is used by the IMAP server for folder separation
     *
     * @return  string  Delimiter string
     * @access  public
     */
    public function get_hierarchy_delimiter()
    {
        return '/';
    }


    /**
     * Get namespace
     *
     * @param string $name Namespace array index: personal, other, shared, prefix
     *
     * @return  array  Namespace data
     */
    public function get_namespace($name = null)
    {
        $ns = $this->namespace;

        if ($name) {
            return isset($ns[$name]) ? $ns[$name] : null;
        }

        unset($ns['prefix']);
        return $ns;
    }


    /**
     * Sets delimiter and namespaces
     */
    protected function set_env()
    {
        if ($this->delimiter !== null && $this->namespace !== null) {
            return;
        }

        $config = rcube::get_instance()->config;

        if (!$this->check_connection()) {
            return;
        }

        $this->namespace = array(
            'personal' => NULL,
            'other'    => NULL,
            'shared'   => NULL,
        );
    }


    /**
     * Get message count for a specific folder
     *
     * @param  string  $folder  Folder name
     * @param  string  $mode    Mode for count [ALL|THREADS|UNSEEN|RECENT|EXISTS]
     * @param  boolean $force   Force reading from server and update cache
     * @param  boolean $status  Enables storing folder status info (max UID/count),
     *                          required for folder_status()
     *
     * @return int     Number of messages
     */
    public function count($folder='', $mode='ALL', $force=false, $status=true)
    {
        if (!strlen($folder)) {
            $folder = $this->folder;
        }

        return $this->countmessages($folder, $mode, $force, $status);
    }


    /**
     * protected method for getting nr of messages
     *
     * @param string  $folder  Folder name
     * @param string  $mode    Mode for count [ALL|THREADS|UNSEEN|RECENT|EXISTS]
     * @param boolean $force   Force reading from server and update cache
     * @param boolean $status  Enables storing folder status info (max UID/count),
     *                         required for folder_status()
     *
     * @return int Number of messages
     * @see rcube_imap::count()
     */
    protected function countmessages($folder, $mode='ALL', $force=false, $status=true)
    {
        if (!$this->check_connection()) {
            return 0;
        }

        return $this->conn->numMsg();
    }


    /**
     * Public method for listing headers
     *
     * @param   string   $folder     Folder name
     * @param   int      $page       Current page to list
     * @param   string   $sort_field Header field to sort by
     * @param   string   $sort_order Sort order [ASC|DESC]
     * @param   int      $slice      Number of slice items to extract from result array
     *
     * @return  array    Indexed array with message header objects
     */
    public function list_messages($folder='', $page=NULL, $sort_field=NULL, $sort_order=NULL, $slice=0)
    {
        if (!strlen($folder)) {
            $folder = $this->folder;
        }

        return $this->_list_messages($folder, $page, $sort_field, $sort_order, $slice);
    }


    /**
     * protected method for listing message headers
     *
     * @param   string   $folder     Folder name
     * @param   int      $page       Current page to list
     * @param   string   $sort_field Header field to sort by
     * @param   string   $sort_order Sort order [ASC|DESC]
     * @param   int      $slice      Number of slice items to extract from result array
     *
     * @return  array    Indexed array with message header objects
     * @see     rcube_imap::list_messages
     */
    protected function _list_messages($folder='', $page=NULL, $sort_field=NULL, $sort_order=NULL, $slice=0)
    {
        if (!strlen($folder)) {
            return array();
        }

        $this->set_sort_order($sort_field, $sort_order);
        $page = $page ? $page : $this->list_page;

        // use saved message set
        if ($this->search_string && $folder == $this->folder) {
            return $this->list_search_messages($folder, $page, $slice);
        }

        if ($this->threading) {
            return $this->list_thread_messages($folder, $page, $slice);
        }

        // get UIDs of all messages in the folder, sorted
        $index = $this->index($folder, $this->sort_field, $this->sort_order);

        if ($index->is_empty()) {
            return array();
        }

        $from = ($page-1) * $this->page_size;
        $to   = $from + $this->page_size;

        $index->slice($from, $to - $from);

        if ($slice) {
            $index->slice(-$slice, $slice);
        }

        // fetch reqested messages headers
        $a_index = $index->get();
        $a_msg_headers = $this->fetch_headers($folder, $a_index);

        return array_values($a_msg_headers);
    }


    /**
     * protected method for listing message headers using threads
     *
     * @param   string   $folder     Folder name
     * @param   int      $page       Current page to list
     * @param   int      $slice      Number of slice items to extract from result array
     *
     * @return  array    Indexed array with message header objects
     * @see     rcube_imap::list_messages
     */
    protected function list_thread_messages($folder, $page, $slice=0)
    {
        // get all threads (not sorted)
        if ($mcache = $this->get_mcache_engine()) {
            $threads = $mcache->get_thread($folder);
        }
        else {
            $threads = $this->threads($folder);
        }

        return $this->fetch_thread_headers($folder, $threads, $page, $slice);
    }

    /**
     * Method for fetching threads data
     *
     * @param  string $folder Folder name
     *
     * @return rcube_imap_thread Thread data object
     */
    function threads($folder)
    {
        if ($mcache = $this->get_mcache_engine()) {
            // don't store in self's internal cache, cache has it's own internal cache
            return $mcache->get_thread($folder);
        }

        if (!empty($this->icache['threads'])) {
            if ($this->icache['threads']->get_parameters('MAILBOX') == $folder) {
                return $this->icache['threads'];
            }
        }

        // get all threads
        $result = $this->threads_direct($folder);

        // add to internal (fast) cache
        return $this->icache['threads'] = $result;
    }


    /**
     * Method for direct fetching of threads data
     *
     * @param  string $folder Folder name
     *
     * @return rcube_imap_thread Thread data object
     */
    function threads_direct($folder)
    {
        return new rcube_result_thread();
    }


    /**
     * protected method for fetching threaded messages headers
     *
     * @param string              $folder     Folder name
     * @param rcube_result_thread $threads    Threads data object
     * @param int                 $page       List page number
     * @param int                 $slice      Number of threads to slice
     *
     * @return array  Messages headers
     */
    protected function fetch_thread_headers($folder, $threads, $page, $slice=0)
    {
        // Sort thread structure
        $this->sort_threads($threads);

        $from = ($page-1) * $this->page_size;
        $to   = $from + $this->page_size;

        $threads->slice($from, $to - $from);

        if ($slice) {
            $threads->slice(-$slice, $slice);
        }

        // Get UIDs of all messages in all threads
        $a_index = $threads->get();

        // fetch reqested headers from server
        $a_msg_headers = $this->fetch_headers($folder, $a_index);

        unset($a_index);

        // Set depth, has_children and unread_children fields in headers
        $this->set_thread_flags($a_msg_headers, $threads);

        return array_values($a_msg_headers);
    }


    /**
     * protected method for setting threaded messages flags:
     * depth, has_children and unread_children
     *
     * @param  array               $headers  Reference to headers array indexed by message UID
     * @param  rcube_result_thread $threads  Threads data object
     *
     * @return array Message headers array indexed by message UID
     */
    protected function set_thread_flags(&$headers, $threads)
    {
        $parents = array();

        list ($msg_depth, $msg_children) = $threads->get_thread_data();

        foreach ($headers as $uid => $header) {
            $depth = $msg_depth[$uid];
            $parents = array_slice($parents, 0, $depth);

            if (!empty($parents)) {
                $headers[$uid]->parent_uid = end($parents);
                if (empty($header->flags['SEEN']))
                    $headers[$parents[0]]->unread_children++;
            }
            array_push($parents, $uid);

            $headers[$uid]->depth = $depth;
            $headers[$uid]->has_children = $msg_children[$uid];
        }
    }


    /**
     * protected method for listing a set of message headers (search results)
     *
     * @param   string   $folder   Folder name
     * @param   int      $page     Current page to list
     * @param   int      $slice    Number of slice items to extract from result array
     *
     * @return  array    Indexed array with message header objects
     */
    protected function list_search_messages($folder, $page, $slice=0)
    {
        if (!strlen($folder) || empty($this->search_set) || $this->search_set->is_empty()) {
            return array();
        }

        // use saved messages from searching
        if ($this->threading) {
            return $this->list_search_thread_messages($folder, $page, $slice);
        }

        // search set is threaded, we need a new one
        if ($this->search_threads) {
            $this->search('', $this->search_string, $this->search_charset, $this->sort_field);
        }

        $index = clone $this->search_set;
        $from  = ($page-1) * $this->page_size;
        $to    = $from + $this->page_size;

        // return empty array if no messages found
        if ($index->is_empty()) {
            return array();
        }

        // quickest method (default sorting)
        if (!$this->search_sort_field && !$this->sort_field) {
            $got_index = true;
        }
        // sorted messages, so we can first slice array and then fetch only wanted headers
        else if ($this->search_sorted) { // SORT searching result
            $got_index = true;
            // reset search set if sorting field has been changed
            if ($this->sort_field && $this->search_sort_field != $this->sort_field) {
                $this->search('', $this->search_string, $this->search_charset, $this->sort_field);

                $index = clone $this->search_set;

                // return empty array if no messages found
                if ($index->is_empty()) {
                    return array();
                }
            }
        }

        if ($got_index) {
            if ($this->sort_order != $index->get_parameters('ORDER')) {
                $index->revert();
            }

            // get messages uids for one page
            $index->slice($from, $to-$from);

            if ($slice) {
                $index->slice(-$slice, $slice);
            }

            // fetch headers
            $a_index       = $index->get();
            $a_msg_headers = $this->fetch_headers($folder, $a_index);

            return array_values($a_msg_headers);
        }

        // SEARCH result, need sorting
        $cnt = $index->count();

        // 300: experimantal value for best result
        if (($cnt > 300 && $cnt > $this->page_size) || !$this->sort_field) {
            // use memory less expensive (and quick) method for big result set
            $index = clone $this->index('', $this->sort_field, $this->sort_order);
            // get messages uids for one page...
            $index->slice($from, min($cnt-$from, $this->page_size));

            if ($slice) {
                $index->slice(-$slice, $slice);
            }

            // ...and fetch headers
            $a_index       = $index->get();
            $a_msg_headers = $this->fetch_headers($folder, $a_index);

            return array_values($a_msg_headers);
        }
        else {
            // for small result set we can fetch all messages headers
            $a_index       = $index->get();
            $a_msg_headers = $this->fetch_headers($folder, $a_index, false);

            // return empty array if no messages found
            if (!is_array($a_msg_headers) || empty($a_msg_headers)) {
                return array();
            }

            if (!$this->check_connection()) {
                return array();
            }

            // if not already sorted
            $a_msg_headers = $this->conn->sortHeaders(
                $a_msg_headers, $this->sort_field, $this->sort_order);

            // only return the requested part of the set
            $slice_length  = min($this->page_size, $cnt - ($to > $cnt ? $from : $to));
            $a_msg_headers = array_slice(array_values($a_msg_headers), $from, $slice_length);

            if ($slice) {
                $a_msg_headers = array_slice($a_msg_headers, -$slice, $slice);
            }

            return $a_msg_headers;
        }
    }


    /**
     * protected method for listing a set of threaded message headers (search results)
     *
     * @param   string   $folder     Folder name
     * @param   int      $page       Current page to list
     * @param   int      $slice      Number of slice items to extract from result array
     *
     * @return  array    Indexed array with message header objects
     * @see rcube_imap::list_search_messages()
     */
    protected function list_search_thread_messages($folder, $page, $slice=0)
    {
        // update search_set if previous data was fetched with disabled threading
        if (!$this->search_threads) {
            if ($this->search_set->is_empty()) {
                return array();
            }
            $this->search('', $this->search_string, $this->search_charset, $this->sort_field);
        }

        return $this->fetch_thread_headers($folder, clone $this->search_set, $page, $slice);
    }


    /**
     * Fetches messages headers (by UID)
     *
     * @param  string  $folder   Folder name
     * @param  array   $msgs     Message UIDs
     * @param  bool    $sort     Enables result sorting by $msgs
     * @param  bool    $force    Disables cache use
     *
     * @return array Messages headers indexed by UID
     */
    function fetch_headers($folder, $msgs, $sort = true, $force = false)
    {
        if (empty($msgs)) {
            return array();
        }

        if (!$force && ($mcache = $this->get_mcache_engine())) {
            $headers = $mcache->get_messages($folder, $msgs);
        }
        else if (!$this->check_connection()) {
            return array();
        }
        else {
            // TODO get_fetch_headers support
            $headers = array_map(function($id) {
                return $this->_message_header($id);
            }, $msgs);
        }

        if (empty($headers)) {
            return array();
        }

        foreach ($headers as $h) {
            $a_msg_headers[$h->uid] = $h;
        }

        if ($sort) {
            // use this class for message sorting
            $sorter = new rcube_message_header_sorter();
            $sorter->set_index($msgs);
            $sorter->sort_headers($a_msg_headers);
        }

        return $a_msg_headers;
    }


    /**
     * Returns current status of a folder (compared to the last time use)
     *
     * We compare the maximum UID to determine the number of
     * new messages because the RECENT flag is not reliable.
     *
     * @param string $folder Folder name
     * @param array  $diff   Difference data
     *
     * @return int Folder status
     */
    public function folder_status($folder = null, &$diff = array())
    {
        if (!strlen($folder)) {
            $folder = $this->folder;
        }
        $old = $this->get_folder_stats($folder);

        // refresh message count -> will update
        $this->countmessages($folder, 'ALL', true);

        $result = 0;

        if (empty($old)) {
            return $result;
        }

        $new = $this->get_folder_stats($folder);

        // got new messages
        if ($new['maxuid'] > $old['maxuid']) {
            $result += 1;
            // get new message UIDs range, that can be used for example
            // to get the data of these messages
            $diff['new'] = ($old['maxuid'] + 1 < $new['maxuid'] ? ($old['maxuid']+1).':' : '') . $new['maxuid'];
        }
        // some messages has been deleted
        if ($new['cnt'] < $old['cnt']) {
            $result += 2;
        }

        // @TODO: optional checking for messages flags changes (?)
        // @TODO: UIDVALIDITY checking

        return $result;
    }


    /**
     * Stores folder statistic data in session
     * @TODO: move to separate DB table (cache?)
     *
     * @param string $folder  Folder name
     * @param string $name    Data name
     * @param mixed  $data    Data value
     */
    protected function set_folder_stats($folder, $name, $data)
    {
        $_SESSION['folders'][$folder][$name] = $data;
    }


    /**
     * Gets folder statistic data
     *
     * @param string $folder Folder name
     *
     * @return array Stats data
     */
    protected function get_folder_stats($folder)
    {
        if ($_SESSION['folders'][$folder]) {
            return (array) $_SESSION['folders'][$folder];
        }

        return array();
    }


    /**
     * Return sorted list of message UIDs
     *
     * @param string $folder     Folder to get index from
     * @param string $sort_field Sort column
     * @param string $sort_order Sort order [ASC, DESC]
     * @param bool   $no_threads Get not threaded index
     * @param bool   $no_search  Get index not limited to search result (optionally)
     *
     * @return rcube_result_index|rcube_result_thread List of messages (UIDs)
     */
    public function index($folder = '', $sort_field = NULL, $sort_order = NULL,
        $no_threads = false, $no_search = false
    ) {
        // TODO sort, cache, search
        return $this->index_direct($folder, $this->sort_field, $this->sort_order);
    }


    /**
     * Return sorted list of message UIDs ignoring current search settings.
     * Doesn't uses cache by default.
     *
     * @param string         $folder     Folder to get index from
     * @param string         $sort_field Sort column
     * @param string         $sort_order Sort order [ASC, DESC]
     * @param rcube_result_* $search     Optional messages set to limit the result
     *
     * @return rcube_result_index Sorted list of message UIDs
     */
    public function index_direct($folder, $sort_field = null, $sort_order = null, $search = null)
    {
        if (!$this->check_connection()) {
            return new rcube_result_index();
        }

        // TODO sort
        $msg_ids = array_map(function($x) {
            return $x['msg_id'];
        }, $this->conn->getListing());

        $data = sprintf("* SEARCH %s", implode(rcube_result_index::SEPARATOR_ELEMENT, $msg_ids));
        $index = new rcube_result_index($folder, $data);

        return $index;
    }

    /**
     * Invoke search request to IMAP server
     *
     * @param  string  $folder     Folder name to search in
     * @param  string  $str        Search criteria
     * @param  string  $charset    Search charset
     * @param  string  $sort_field Header field to sort by
     *
     * @todo: Search criteria should be provided in non-IMAP format, eg. array
     */
    public function search($folder='', $str='ALL', $charset=NULL, $sort_field=NULL)
    {
        return 'ALL';
    }


    /**
     * Direct (real and simple) SEARCH request (without result sorting and caching).
     *
     * @param  string  $mailbox Mailbox name to search in
     * @param  string  $str     Search string
     *
     * @return rcube_result_index  Search result (UIDs)
     */
    public function search_once($folder = null, $str = 'ALL')
    {
        return 'ALL';
    }


    /**
     * Refresh saved search set
     *
     * @return array Current search set
     */
    public function refresh_search()
    {
        if (!empty($this->search_string)) {
            $this->search('', $this->search_string, $this->search_charset, $this->search_sort_field);
        }

        return $this->get_search_set();
    }


    /**
     * Return message headers object of a specific message
     *
     * @param int     $id       Message UID
     * @param string  $folder   Folder to read from
     * @param bool    $force    True to skip cache
     *
     * @return rcube_message_header Message headers
     */
    public function get_message_headers($uid, $folder = null, $force = false)
    {
        if (!strlen($folder)) {
            $folder = $this->folder;
        }

        // get cached headers
        if (!$force && $uid && ($mcache = $this->get_mcache_engine())) {
            return $mcache->get_message($folder, $uid);
        }
        if (!$this->check_connection()) {
            return null;
        }
        return $this->_message_header($uid);
    }


    /**
     * Fetch message headers and body structure from the IMAP server and build
     * an object structure similar to the one generated by PEAR::Mail_mimeDecode
     *
     * @param int     $uid      Message UID to fetch
     * @param string  $folder   Folder to read from
     *
     * @return object rcube_message_header Message data
     */
    public function get_message($uid, $folder = null)
    {
        if (!strlen($folder)) {
            $folder = $this->folder;
        }

        // Check internal cache
        if (!empty($this->icache['message'])) {
            if (($headers = $this->icache['message']) && $headers->uid == $uid) {
                return $headers;
            }
        }

        if (!$this->check_connection()) {
            return null;
        }
        return $this->_mime_message($uid);
    }

    /**
     * Fetch message body of a specific message from the server
     *
     * @param int                Message UID
     * @param string             Part number
     * @param rcube_message_part Part object created by get_structure()
     * @param mixed              True to print part, resource to write part contents in
     * @param resource           File pointer to save the message part
     * @param boolean            Disables charset conversion
     * @param int                Only read this number of bytes
     * @param boolean            Enables formatting of text/* parts bodies
     *
     * @return string Message/part body if not printed
     */
    public function get_message_part($uid, $part=1, $o_part=NULL, $print=NULL, $fp=NULL, $skip_charset_conv=false, $max_bytes=0, $formatted=true)
    {
        if (!$this->check_connection()) {
            return null;
        }

        // get part data if not provided
        if (!is_object($o_part)) {
            $this->log('DO GET_MESSAGE_PART');
            return null;

            $structure = $this->conn->getStructure($this->folder, $uid, true);
            $part_data = rcube_imap_generic::getStructurePartData($structure, $part);

            $o_part = new rcube_message_part;
            $o_part->ctype_primary = $part_data['type'];
            $o_part->encoding      = $part_data['encoding'];
            $o_part->charset       = $part_data['charset'];
            $o_part->size          = $part_data['size'];
        }

        if ($o_part && $o_part->size) {
            $formatted = $formatted && $o_part->ctype_primary == 'text';
            $body = $this->conn->handlePartBody($this->folder, $uid, true,
                $part ? $part : 'TEXT', $o_part->encoding, $print, $fp, $formatted, $max_bytes);
        }

        if ($fp || $print) {
            return true;
        }

        // convert charset (if text or message part)
        if ($body && preg_match('/^(text|message)$/', $o_part->ctype_primary)) {
            // Remove NULL characters if any (#1486189)
            if (strpos($body, "\x00") !== false) {
                $body = str_replace("\x00", '', $body);
            }

            if (!$skip_charset_conv) {
                if (!$o_part->charset || strtoupper($o_part->charset) == 'US-ASCII') {
                    // try to extract charset information from HTML meta tag (#1488125)
                    if ($o_part->ctype_secondary == 'html' && preg_match('/<meta[^>]+charset=([a-z0-9-_]+)/i', $body, $m)) {
                        $o_part->charset = strtoupper($m[1]);
                    }
                    else {
                        $o_part->charset = $this->default_charset;
                    }
                }
                $body = rcube_charset::convert($body, $o_part->charset);
            }
        }

        return $body;
    }


    /**
     * Returns the whole message source as string (or saves to a file)
     *
     * @param int      $uid Message UID
     * @param resource $fp  File pointer to save the message
     *
     * @return string Message source string
     */
    public function get_raw_body($uid, $fp=null)
    {
        if (!$this->check_connection()) {
            return null;
        }

        return $this->conn->handlePartBody($this->folder, $uid,
            true, null, null, false, $fp);
    }


    /**
     * Returns the message headers as string
     *
     * @param int $uid  Message UID
     *
     * @return string Message headers string
     */
    public function get_raw_headers($uid)
    {
        if (!$this->check_connection()) {
            return null;
        }

        return $this->conn->fetchPartHeader($this->folder, $uid, true);
    }


    /**
     * Sends the whole message source to stdout
     *
     * @param int  $uid       Message UID
     * @param bool $formatted Enables line-ending formatting
     */
    public function print_raw_body($uid, $formatted = true)
    {
        if (!$this->check_connection()) {
            return;
        }

        $this->conn->handlePartBody($this->folder, $uid, true, null, null, true, null, $formatted);
    }


    /**
     * Set message flag to one or several messages
     *
     * @param mixed   $uids       Message UIDs as array or comma-separated string, or '*'
     * @param string  $flag       Flag to set: SEEN, UNDELETED, DELETED, RECENT, ANSWERED, DRAFT, MDNSENT
     * @param string  $folder    Folder name
     * @param boolean $skip_cache True to skip message cache clean up
     *
     * @return boolean  Operation status
     */
    public function set_flag($uids, $flag, $folder=null, $skip_cache=false)
    {
        return false;
    }


    /**
     * Append a mail message (source) to a specific folder
     *
     * @param string       $folder  Target folder
     * @param string|array $message The message source string or filename
     *                              or array (of strings and file pointers)
     * @param string       $headers Headers string if $message contains only the body
     * @param boolean      $is_file True if $message is a filename
     * @param array        $flags   Message flags
     * @param mixed        $date    Message internal date
     * @param bool         $binary  Enables BINARY append
     *
     * @return int|bool Appended message UID or True on success, False on error
     */
    public function save_message($folder, &$message, $headers='', $is_file=false, $flags = array(), $date = null, $binary = false)
    {
        // TODO
        return false;
    }


    /**
     * Move a message from one folder to another
     *
     * @param mixed  $uids      Message UIDs as array or comma-separated string, or '*'
     * @param string $to_mbox   Target folder
     * @param string $from_mbox Source folder
     *
     * @return boolean True on success, False on error
     */
    public function move_message($uids, $to_mbox, $from_mbox='')
    {
        // TODO
        return false;
    }


    /**
     * Copy a message from one folder to another
     *
     * @param mixed  $uids      Message UIDs as array or comma-separated string, or '*'
     * @param string $to_mbox   Target folder
     * @param string $from_mbox Source folder
     *
     * @return boolean True on success, False on error
     */
    public function copy_message($uids, $to_mbox, $from_mbox='')
    {
        // TODO
        return false;
    }


    /**
     * Mark messages as deleted and expunge them
     *
     * @param mixed  $uids    Message UIDs as array or comma-separated string, or '*'
     * @param string $folder  Source folder
     *
     * @return boolean True on success, False on error
     */
    public function delete_message($uids, $folder='')
    {
        // TODO
        return false;
    }


    /**
     * Send IMAP expunge command and clear cache
     *
     * @param mixed   $uids        Message UIDs as array or comma-separated string, or '*'
     * @param string  $folder      Folder name
     * @param boolean $clear_cache False if cache should not be cleared
     *
     * @return boolean True on success, False on failure
     */
    public function expunge_message($uids, $folder = null, $clear_cache = true)
    {
        // TODO
        return false;
    }


    /* --------------------------------
     *        folder managment
     * --------------------------------*/

    /**
     * Public method for listing subscribed folders.
     *
     * @param   string  $root      Optional root folder
     * @param   string  $name      Optional name pattern
     * @param   string  $filter    Optional filter
     * @param   string  $rights    Optional ACL requirements
     * @param   bool    $skip_sort Enable to return unsorted list (for better performance)
     *
     * @return  array   List of folders
     */
    public function list_folders_subscribed($root='', $name='*', $filter=null, $rights=null, $skip_sort=false)
    {
        $cache_key = $root.':'.$name;
        if (!empty($filter)) {
            $cache_key .= ':'.(is_string($filter) ? $filter : serialize($filter));
        }
        $cache_key .= ':'.$rights;
        $cache_key = 'mailboxes.'.md5($cache_key);

        // get cached folder list
        $a_mboxes = $this->get_cache($cache_key);
        if (is_array($a_mboxes)) {
            return $a_mboxes;
        }

        // Give plugins a chance to provide a list of folders
        $data = rcube::get_instance()->plugins->exec_hook('storage_folders',
            array('root' => $root, 'name' => $name, 'filter' => $filter, 'mode' => 'LSUB'));

        if (isset($data['folders'])) {
            $a_mboxes = $data['folders'];
        }
        else {
            $a_mboxes = $this->list_folders_subscribed_direct($root, $name);
        }

        if (!is_array($a_mboxes)) {
            return array();
        }

        // filter folders list according to rights requirements
        if ($rights && $this->get_capability('ACL')) {
            $a_mboxes = $this->filter_rights($a_mboxes, $rights);
        }

        // INBOX should always be available
        if ((!$filter || $filter == 'mail') && !in_array('INBOX', $a_mboxes)) {
            array_unshift($a_mboxes, 'INBOX');
        }

        // sort folders (always sort for cache)
        if (!$skip_sort || $this->cache) {
            $a_mboxes = $this->sort_folder_list($a_mboxes);
        }

        // write folders list to cache
        $this->update_cache($cache_key, $a_mboxes);

        return $a_mboxes;
    }


    /**
     * Method for direct folders listing (LSUB)
     *
     * @param   string  $root   Optional root folder
     * @param   string  $name   Optional name pattern
     *
     * @return  array   List of subscribed folders
     * @see     rcube_imap::list_folders_subscribed()
     */
    public function list_folders_subscribed_direct($root='', $name='*')
    {
        return $this->list_folders($root, $name);
    }


    /**
     * Get a list of all folders available on the server
     *
     * @param string  $root      IMAP root dir
     * @param string  $name      Optional name pattern
     * @param mixed   $filter    Optional filter
     * @param string  $rights    Optional ACL requirements
     * @param bool    $skip_sort Enable to return unsorted list (for better performance)
     *
     * @return array Indexed array with folder names
     */
    public function list_folders($root='', $name='*', $filter=null, $rights=null, $skip_sort=false)
    {
        return array('INBOX');
    }


    /**
     * Method for direct folders listing (LIST)
     *
     * @param   string  $root   Optional root folder
     * @param   string  $name   Optional name pattern
     *
     * @return  array   List of folders
     * @see     rcube_imap::list_folders()
     */
    public function list_folders_direct($root='', $name='*')
    {
        return $this->list_folders($root, $name);
    }

    /**
     * Filter the given list of folders according to access rights
     *
     * For performance reasons we assume user has full rights
     * on all personal folders.
     */
    protected function filter_rights($a_folders, $rights)
    {
        $regex = '/('.$rights.')/';

        foreach ($a_folders as $idx => $folder) {
            if ($this->folder_namespace($folder) == 'personal') {
                continue;
            }

            $myrights = join('', (array)$this->my_rights($folder));

            if ($myrights !== null && !preg_match($regex, $myrights)) {
                unset($a_folders[$idx]);
            }
        }

        return $a_folders;
    }


    /**
     * Get mailbox quota information
     * added by Nuny
     *
     * @return mixed Quota info or False if not supported
     */
    public function get_quota()
    {
        if ($this->get_capability('QUOTA') && $this->check_connection()) {
            return $this->conn->getQuota();
        }

        return false;
    }


    /**
     * Get folder size (size of all messages in a folder)
     *
     * @param string $folder Folder name
     *
     * @return int Folder size in bytes, False on error
     */
    public function folder_size($folder)
    {
        if (!$this->check_connection()) {
            return 0;
        }

        // @TODO: could we try to use QUOTA here?
        $result = $this->conn->fetchHeaderIndex($folder, '1:*', 'SIZE', false);

        if (is_array($result)) {
            $result = array_sum($result);
        }

        return $result;
    }


    /**
     * Subscribe to a specific folder(s)
     *
     * @param array $folders Folder name(s)
     *
     * @return boolean True on success
     */
    public function subscribe($folders)
    {
        // let this common function do the main work
        return $this->change_subscription($folders, 'subscribe');
    }


    /**
     * Unsubscribe folder(s)
     *
     * @param array $a_mboxes Folder name(s)
     *
     * @return boolean True on success
     */
    public function unsubscribe($folders)
    {
        // let this common function do the main work
        return $this->change_subscription($folders, 'unsubscribe');
    }


    /**
     * Create a new folder on the server and register it in local cache
     *
     * @param string  $folder    New folder name
     * @param boolean $subscribe True if the new folder should be subscribed
     *
     * @return boolean True on success
     */
    public function create_folder($folder, $subscribe=false)
    {
        if (!$this->check_connection()) {
            return false;
        }

        $result = $this->conn->createFolder($folder);

        // try to subscribe it
        if ($result) {
            // clear cache
            $this->clear_cache('mailboxes', true);

            if ($subscribe) {
                $this->subscribe($folder);
            }
        }

        return $result;
    }


    /**
     * Set a new name to an existing folder
     *
     * @param string $folder   Folder to rename
     * @param string $new_name New folder name
     *
     * @return boolean True on success
     */
    public function rename_folder($folder, $new_name)
    {
        if (!strlen($new_name)) {
            return false;
        }

        if (!$this->check_connection()) {
            return false;
        }

        $delm = $this->get_hierarchy_delimiter();

        // get list of subscribed folders
        if ((strpos($folder, '%') === false) && (strpos($folder, '*') === false)) {
            $a_subscribed = $this->list_folders_subscribed('', $folder . $delm . '*');
            $subscribed   = $this->folder_exists($folder, true);
        }
        else {
            $a_subscribed = $this->list_folders_subscribed();
            $subscribed   = in_array($folder, $a_subscribed);
        }

        $result = $this->conn->renameFolder($folder, $new_name);

        if ($result) {
            // unsubscribe the old folder, subscribe the new one
            if ($subscribed) {
                $this->conn->unsubscribe($folder);
                $this->conn->subscribe($new_name);
            }

            // check if folder children are subscribed
            foreach ($a_subscribed as $c_subscribed) {
                if (strpos($c_subscribed, $folder.$delm) === 0) {
                    $this->conn->unsubscribe($c_subscribed);
                    $this->conn->subscribe(preg_replace('/^'.preg_quote($folder, '/').'/',
                        $new_name, $c_subscribed));

                    // clear cache
                    $this->clear_message_cache($c_subscribed);
                }
            }

            // clear cache
            $this->clear_message_cache($folder);
            $this->clear_cache('mailboxes', true);
        }

        return $result;
    }


    /**
     * Remove folder from server
     *
     * @param string $folder Folder name
     *
     * @return boolean True on success
     */
    function delete_folder($folder)
    {
        $delm = $this->get_hierarchy_delimiter();

        if (!$this->check_connection()) {
            return false;
        }

        // get list of folders
        if ((strpos($folder, '%') === false) && (strpos($folder, '*') === false)) {
            $sub_mboxes = $this->list_folders('', $folder . $delm . '*');
        }
        else {
            $sub_mboxes = $this->list_folders();
        }

        // send delete command to server
        $result = $this->conn->deleteFolder($folder);

        if ($result) {
            // unsubscribe folder
            $this->conn->unsubscribe($folder);

            foreach ($sub_mboxes as $c_mbox) {
                if (strpos($c_mbox, $folder.$delm) === 0) {
                    $this->conn->unsubscribe($c_mbox);
                    if ($this->conn->deleteFolder($c_mbox)) {
                        $this->clear_message_cache($c_mbox);
                    }
                }
            }

            // clear folder-related cache
            $this->clear_message_cache($folder);
            $this->clear_cache('mailboxes', true);
        }

        return $result;
    }


    /**
     * Create all folders specified as default
     */
    public function create_default_folders()
    {
        // create default folders if they do not exist
        foreach ($this->default_folders as $folder) {
            if (!$this->folder_exists($folder)) {
                $this->create_folder($folder, true);
            }
            else if (!$this->folder_exists($folder, true)) {
                $this->subscribe($folder);
            }
        }
    }


    /**
     * Checks if folder exists and is subscribed
     *
     * @param string   $folder       Folder name
     * @param boolean  $subscription Enable subscription checking
     *
     * @return boolean TRUE or FALSE
     */
    public function folder_exists($folder, $subscription=false)
    {
        if ($folder == 'INBOX') {
            return true;
        }

        return false;
    }


    /**
     * Returns the namespace where the folder is in
     *
     * @param string $folder Folder name
     *
     * @return string One of 'personal', 'other' or 'shared'
     */
    public function folder_namespace($folder)
    {
        if ($folder == 'INBOX') {
            return 'personal';
        }

        foreach ($this->namespace as $type => $namespace) {
            if (is_array($namespace)) {
                foreach ($namespace as $ns) {
                    if ($len = strlen($ns[0])) {
                        if (($len > 1 && $folder == substr($ns[0], 0, -1))
                            || strpos($folder, $ns[0]) === 0
                        ) {
                            return $type;
                        }
                    }
                }
            }
        }

        return 'personal';
    }


    /**
     * Modify folder name according to namespace.
     * For output it removes prefix of the personal namespace if it's possible.
     * For input it adds the prefix. Use it before creating a folder in root
     * of the folders tree.
     *
     * @param string $folder Folder name
     * @param string $mode    Mode name (out/in)
     *
     * @return string Folder name
     */
    public function mod_folder($folder, $mode = 'out')
    {
        if (!strlen($folder)) {
            return $folder;
        }

        $prefix     = $this->namespace['prefix']; // see set_env()
        $prefix_len = strlen($prefix);

        if (!$prefix_len) {
            return $folder;
        }

        // remove prefix for output
        if ($mode == 'out') {
            if (substr($folder, 0, $prefix_len) === $prefix) {
                return substr($folder, $prefix_len);
            }
        }
        // add prefix for input (e.g. folder creation)
        else {
            return $prefix . $folder;
        }

        return $folder;
    }


    /**
     * Gets folder attributes from LIST response, e.g. \Noselect, \Noinferiors
     *
     * @param string $folder Folder name
     * @param bool   $force   Set to True if attributes should be refreshed
     *
     * @return array Options list
     */
    public function folder_attributes($folder, $force=false)
    {
        return array();
    }


    /**
     * Gets connection (and current folder) data: UIDVALIDITY, EXISTS, RECENT,
     * PERMANENTFLAGS, UIDNEXT, UNSEEN
     *
     * @param string $folder Folder name
     *
     * @return array Data
     */
    public function folder_data($folder)
    {
        return array();
    }


    /**
     * Returns extended information about the folder
     *
     * @param string $folder Folder name
     *
     * @return array Data
     */
    public function folder_info($folder)
    {
        if ($this->icache['options'] && $this->icache['options']['name'] == $folder) {
            return $this->icache['options'];
        }

        // get cached metadata
        $cache_key = 'mailboxes.folder-info.' . $folder;
        $cached = $this->get_cache($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $acl       = $this->get_capability('ACL');
        $namespace = $this->get_namespace();
        $options   = array();

        // check if the folder is a namespace prefix
        if (!empty($namespace)) {
            $mbox = $folder . $this->delimiter;
            foreach ($namespace as $ns) {
                if (!empty($ns)) {
                    foreach ($ns as $item) {
                        if ($item[0] === $mbox) {
                            $options['is_root'] = true;
                            break 2;
                        }
                    }
                }
            }
        }
        // check if the folder is other user virtual-root
        if (!$options['is_root'] && !empty($namespace) && !empty($namespace['other'])) {
            $parts = explode($this->delimiter, $folder);
            if (count($parts) == 2) {
                $mbox = $parts[0] . $this->delimiter;
                foreach ($namespace['other'] as $item) {
                    if ($item[0] === $mbox) {
                        $options['is_root'] = true;
                        break;
                    }
                }
            }
        }

        $options['name']       = $folder;
        $options['attributes'] = $this->folder_attributes($folder, true);
        $options['namespace']  = $this->folder_namespace($folder);
        $options['special']    = in_array($folder, $this->default_folders);

        // Set 'noselect' flag
        if (is_array($options['attributes'])) {
            foreach ($options['attributes'] as $attrib) {
                $attrib = strtolower($attrib);
                if ($attrib == '\noselect' || $attrib == '\nonexistent') {
                    $options['noselect'] = true;
                }
            }
        }
        else {
            $options['noselect'] = true;
        }

        // Get folder rights (MYRIGHTS)
        if ($acl && ($rights = $this->my_rights($folder))) {
            $options['rights'] = $rights;
        }

        // Set 'norename' flag
        if (!empty($options['rights'])) {
            $options['norename'] = !in_array('x', $options['rights']) && !in_array('d', $options['rights']);

            if (!$options['noselect']) {
                $options['noselect'] = !in_array('r', $options['rights']);
            }
        }
        else {
            $options['norename'] = $options['is_root'] || $options['namespace'] != 'personal';
        }

        // update caches
        $this->icache['options'] = $options;
        $this->update_cache($cache_key, $options);

        return $options;
    }


    /**
     * Synchronizes messages cache.
     *
     * @param string $folder Folder name
     */
    public function folder_sync($folder)
    {
        if ($mcache = $this->get_mcache_engine()) {
            $mcache->synchronize($folder);
        }
    }


    /**
     * Get message header names for rcube_imap_generic::fetchHeader(s)
     *
     * @return string Space-separated list of header names
     */
    protected function get_fetch_headers()
    {
        if (!empty($this->options['fetch_headers'])) {
            $headers = explode(' ', $this->options['fetch_headers']);
        }
        else {
            $headers = array();
        }

        if ($this->messages_caching || $this->options['all_headers']) {
            $headers = array_merge($headers, $this->all_headers);
        }

        return $headers;
    }


    /* -----------------------------------------
     *   ACL and METADATA/ANNOTATEMORE methods
     * ----------------------------------------*/

    /**
     * Changes the ACL on the specified folder (SETACL)
     *
     * @param string $folder  Folder name
     * @param string $user    User name
     * @param string $acl     ACL string
     *
     * @return boolean True on success, False on failure
     * @since 0.5-beta
     */
    public function set_acl($folder, $user, $acl)
    {
        return false;
    }


    /**
     * Removes any <identifier,rights> pair for the
     * specified user from the ACL for the specified
     * folder (DELETEACL)
     *
     * @param string $folder  Folder name
     * @param string $user    User name
     *
     * @return boolean True on success, False on failure
     * @since 0.5-beta
     */
    public function delete_acl($folder, $user)
    {
        return false;
    }


    /**
     * Returns the access control list for folder (GETACL)
     *
     * @param string $folder Folder name
     *
     * @return array User-rights array on success, NULL on error
     * @since 0.5-beta
     */
    public function get_acl($folder)
    {
        return null;
    }


    /**
     * Returns information about what rights can be granted to the
     * user (identifier) in the ACL for the folder (LISTRIGHTS)
     *
     * @param string $folder  Folder name
     * @param string $user    User name
     *
     * @return array List of user rights
     * @since 0.5-beta
     */
    public function list_rights($folder, $user)
    {
        return null;
    }


    /**
     * Returns the set of rights that the current user has to
     * folder (MYRIGHTS)
     *
     * @param string $folder Folder name
     *
     * @return array MYRIGHTS response on success, NULL on error
     * @since 0.5-beta
     */
    public function my_rights($folder)
    {
        return null;
    }


    /**
     * Sets IMAP metadata/annotations (SETMETADATA/SETANNOTATION)
     *
     * @param string $folder  Folder name (empty for server metadata)
     * @param array  $entries Entry-value array (use NULL value as NIL)
     *
     * @return boolean True on success, False on failure
     * @since 0.5-beta
     */
    public function set_metadata($folder, $entries)
    {
        return false;
    }


    /**
     * Unsets IMAP metadata/annotations (SETMETADATA/SETANNOTATION)
     *
     * @param string $folder  Folder name (empty for server metadata)
     * @param array  $entries Entry names array
     *
     * @return boolean True on success, False on failure
     * @since 0.5-beta
     */
    public function delete_metadata($folder, $entries)
    {
        return false;
    }


    /**
     * Returns IMAP metadata/annotations (GETMETADATA/GETANNOTATION)
     *
     * @param string $folder  Folder name (empty for server metadata)
     * @param array  $entries Entries
     * @param array  $options Command options (with MAXSIZE and DEPTH keys)
     *
     * @return array Metadata entry-value hash array on success, NULL on error
     * @since 0.5-beta
     */
    public function get_metadata($folder, $entries, $options=array())
    {
        return array();
    }


    /**
     * Converts the METADATA extension entry name into the correct
     * entry-attrib names for older ANNOTATEMORE version.
     *
     * @param string $entry Entry name
     *
     * @return array Entry-attribute list, NULL if not supported (?)
     */
    protected function md2annotate($entry)
    {
        return null;
    }


    /* --------------------------------
     *   internal caching methods
     * --------------------------------*/

    /**
     * Enable or disable indexes caching
     *
     * @param string $type Cache type (@see rcube::get_cache)
     */
    public function set_caching($type)
    {
        if ($type) {
            $this->caching = $type;
        }
        else {
            if ($this->cache) {
                $this->cache->close();
            }
            $this->cache   = null;
            $this->caching = false;
        }
    }

    /**
     * Getter for IMAP cache object
     */
    protected function get_cache_engine()
    {
        if ($this->caching && !$this->cache) {
            $rcube = rcube::get_instance();
            $ttl   = $rcube->config->get('imap_cache_ttl', '10d');
            $this->cache = $rcube->get_cache('IMAP', $this->caching, $ttl);
        }

        return $this->cache;
    }

    /**
     * Returns cached value
     *
     * @param string $key Cache key
     *
     * @return mixed
     */
    public function get_cache($key)
    {
        if ($cache = $this->get_cache_engine()) {
            return $cache->get($key);
        }
    }

    /**
     * Update cache
     *
     * @param string $key  Cache key
     * @param mixed  $data Data
     */
    public function update_cache($key, $data)
    {
        if ($cache = $this->get_cache_engine()) {
            $cache->set($key, $data);
        }
    }

    /**
     * Clears the cache.
     *
     * @param string  $key         Cache key name or pattern
     * @param boolean $prefix_mode Enable it to clear all keys starting
     *                             with prefix specified in $key
     */
    public function clear_cache($key = null, $prefix_mode = false)
    {
        if ($cache = $this->get_cache_engine()) {
            $cache->remove($key, $prefix_mode);
        }
    }


    /* --------------------------------
     *   message caching methods
     * --------------------------------*/

    /**
     * Enable or disable messages caching
     *
     * @param boolean $set  Flag
     * @param int     $mode Cache mode
     */
    public function set_messages_caching($set, $mode = null)
    {
        if ($set) {
            $this->messages_caching = true;

            if ($mode && ($cache = $this->get_mcache_engine())) {
                $cache->set_mode($mode);
            }
        }
        else {
            if ($this->mcache) {
                $this->mcache->close();
            }
            $this->mcache = null;
            $this->messages_caching = false;
        }
    }


    /**
     * Getter for messages cache object
     */
    protected function get_mcache_engine()
    {
        if ($this->messages_caching && !$this->mcache) {
            $rcube = rcube::get_instance();
            if (($dbh = $rcube->get_dbh()) && ($userid = $rcube->get_user_id())) {
                $ttl       = $rcube->config->get('messages_cache_ttl', '10d');
                $threshold = $rcube->config->get('messages_cache_threshold', 50);
                $this->mcache = new rcube_imap_cache(
                    $dbh, $this, $userid, $this->options['skip_deleted'], $ttl, $threshold);
            }
        }

        return $this->mcache;
    }


    /**
     * Clears the messages cache.
     *
     * @param string $folder Folder name
     * @param array  $uids   Optional message UIDs to remove from cache
     */
    protected function clear_message_cache($folder = null, $uids = null)
    {
        if ($mcache = $this->get_mcache_engine()) {
            $mcache->clear($folder, $uids);
        }
    }


    /**
     * Delete outdated cache entries
     */
    function cache_gc()
    {
        rcube_imap_cache::gc();
    }


    /* --------------------------------
     *         protected methods
     * --------------------------------*/

    /**
     * Validate the given input and save to local properties
     *
     * @param string $sort_field Sort column
     * @param string $sort_order Sort order
     */
    protected function set_sort_order($sort_field, $sort_order)
    {
        if ($sort_field != null) {
            $this->sort_field = asciiwords($sort_field);
        }
        if ($sort_order != null) {
            $this->sort_order = strtoupper($sort_order) == 'DESC' ? 'DESC' : 'ASC';
        }
    }


    /**
     * Sort folders first by default folders and then in alphabethical order
     *
     * @param array $a_folders    Folders list
     * @param bool  $skip_default Skip default folders handling
     *
     * @return array Sorted list
     */
    public function sort_folder_list($a_folders, $skip_default = false)
    {
        $a_out = $a_defaults = $folders = array();

        $delimiter = $this->get_hierarchy_delimiter();

        // find default folders and skip folders starting with '.'
        foreach ($a_folders as $folder) {
            if ($folder[0] == '.') {
                continue;
            }

            if (!$skip_default && ($p = array_search($folder, $this->default_folders)) !== false && !$a_defaults[$p]) {
                $a_defaults[$p] = $folder;
            }
            else {
                $folders[$folder] = rcube_charset::convert($folder, 'UTF7-IMAP');
            }
        }

        // sort folders and place defaults on the top
        asort($folders, SORT_LOCALE_STRING);
        ksort($a_defaults);
        $folders = array_merge($a_defaults, array_keys($folders));

        // finally we must rebuild the list to move
        // subfolders of default folders to their place...
        // ...also do this for the rest of folders because
        // asort() is not properly sorting case sensitive names
        while (list($key, $folder) = each($folders)) {
            // set the type of folder name variable (#1485527)
            $a_out[] = (string) $folder;
            unset($folders[$key]);
            $this->rsort($folder, $delimiter, $folders, $a_out);
        }

        return $a_out;
    }


    /**
     * Recursive method for sorting folders
     */
    protected function rsort($folder, $delimiter, &$list, &$out)
    {
        while (list($key, $name) = each($list)) {
            if (strpos($name, $folder.$delimiter) === 0) {
                // set the type of folder name variable (#1485527)
                $out[] = (string) $name;
                unset($list[$key]);
                $this->rsort($name, $delimiter, $list, $out);
            }
        }
        reset($list);
    }


    /**
     * Find UID of the specified message sequence ID
     *
     * @param int    $id       Message (sequence) ID
     * @param string $folder   Folder name
     *
     * @return int Message UID
     */
    public function id2uid($id, $folder = null)
    {
        if (!strlen($folder)) {
            $folder = $this->folder;
        }

        if ($uid = array_search($id, (array)$this->uid_id_map[$folder])) {
            return $uid;
        }

        if (!$this->check_connection()) {
            return null;
        }

        $uid = $this->conn->ID2UID($folder, $id);

        $this->uid_id_map[$folder][$uid] = $id;

        return $uid;
    }


    /**
     * Subscribe/unsubscribe a list of folders and update local cache
     */
    protected function change_subscription($folders, $mode)
    {
        return false;
    }


    /**
     * Increde/decrese messagecount for a specific folder
     */
    protected function set_messagecount($folder, $mode, $increment)
    {
        if (!is_numeric($increment)) {
            return false;
        }

        $mode = strtoupper($mode);
        $a_folder_cache = $this->get_cache('messagecount');

        if (!is_array($a_folder_cache[$folder]) || !isset($a_folder_cache[$folder][$mode])) {
            return false;
        }

        // add incremental value to messagecount
        $a_folder_cache[$folder][$mode] += $increment;

        // there's something wrong, delete from cache
        if ($a_folder_cache[$folder][$mode] < 0) {
            unset($a_folder_cache[$folder][$mode]);
        }

        // write back to cache
        $this->update_cache('messagecount', $a_folder_cache);

        return true;
    }


    /**
     * Remove messagecount of a specific folder from cache
     */
    protected function clear_messagecount($folder, $mode=null)
    {
        $a_folder_cache = $this->get_cache('messagecount');

        if (is_array($a_folder_cache[$folder])) {
            if ($mode) {
                unset($a_folder_cache[$folder][$mode]);
            }
            else {
                unset($a_folder_cache[$folder]);
            }
            $this->update_cache('messagecount', $a_folder_cache);
        }
    }


    /**
     * Converts date string/object into IMAP date/time format
     */
    protected function date_format($date)
    {
        if (empty($date)) {
            return null;
        }

        if (!is_object($date) || !is_a($date, 'DateTime')) {
            try {
                $timestamp = rcube_utils::strtotime($date);
                $date      = new DateTime("@".$timestamp);
            }
            catch (Exception $e) {
                return null;
            }
        }

        return $date->format('d-M-Y H:i:s O');
    }


    /**
     * Deprecated methods (to be removed)
     */

    public function decode_address_list($input, $max = null, $decode = true, $fallback = null)
    {
        return rcube_mime::decode_address_list($input, $max, $decode, $fallback);
    }

    public function decode_header($input, $fallback = null)
    {
        return rcube_mime::decode_mime_string((string)$input, $fallback);
    }

    public static function decode_mime_string($input, $fallback = null)
    {
        return rcube_mime::decode_mime_string($input, $fallback);
    }

    public function mime_decode($input, $encoding = '7bit')
    {
        return rcube_mime::decode($input, $encoding);
    }

    public static function explode_header_string($separator, $str, $remove_comments = false)
    {
        return rcube_mime::explode_header_string($separator, $str, $remove_comments);
    }

    public function select_mailbox($mailbox)
    {
        // do nothing
    }

    public function set_mailbox($folder)
    {
        $this->set_folder($folder);
    }

    public function get_mailbox_name()
    {
        return $this->get_folder();
    }

    public function list_headers($folder='', $page=NULL, $sort_field=NULL, $sort_order=NULL, $slice=0)
    {
        return $this->list_messages($folder, $page, $sort_field, $sort_order, $slice);
    }

    public function get_headers($uid, $folder = null, $force = false)
    {
        return $this->get_message_headers($uid, $folder, $force);
    }

    public function mailbox_status($folder = null)
    {
        return $this->folder_status($folder);
    }

    public function message_index($folder = '', $sort_field = NULL, $sort_order = NULL)
    {
        return $this->index($folder, $sort_field, $sort_order);
    }

    public function message_index_direct($folder, $sort_field = null, $sort_order = null)
    {
        return $this->index_direct($folder, $sort_field, $sort_order);
    }

    public function list_mailboxes($root='', $name='*', $filter=null, $rights=null, $skip_sort=false)
    {
        return $this->list_folders_subscribed($root, $name, $filter, $rights, $skip_sort);
    }

    public function list_unsubscribed($root='', $name='*', $filter=null, $rights=null, $skip_sort=false)
    {
        return $this->list_folders($root, $name, $filter, $rights, $skip_sort);
    }

    public function get_mailbox_size($folder)
    {
        return $this->folder_size($folder);
    }

    public function create_mailbox($folder, $subscribe=false)
    {
        return $this->create_folder($folder, $subscribe);
    }

    public function rename_mailbox($folder, $new_name)
    {
        return $this->rename_folder($folder, $new_name);
    }

    function delete_mailbox($folder)
    {
        return $this->delete_folder($folder);
    }

    function clear_mailbox($folder = null)
    {
        return $this->clear_folder($folder);
    }

    public function mailbox_exists($folder, $subscription=false)
    {
        return $this->folder_exists($folder, $subscription);
    }

    public function mailbox_namespace($folder)
    {
        return $this->folder_namespace($folder);
    }

    public function mod_mailbox($folder, $mode = 'out')
    {
        return $this->mod_folder($folder, $mode);
    }

    public function mailbox_attributes($folder, $force=false)
    {
        return $this->folder_attributes($folder, $force);
    }

    public function mailbox_data($folder)
    {
        return $this->folder_data($folder);
    }

    public function mailbox_info($folder)
    {
        return $this->folder_info($folder);
    }

    public function mailbox_sync($folder)
    {
        return $this->folder_sync($folder);
    }

    public function expunge($folder='', $clear_cache=true)
    {
        return $this->expunge_folder($folder, $clear_cache);
    }

    private function log($message) {
        rcube::write_log('errors', $message);
    }

    private function _message_header($msg_id) {
        $raw = $this->conn->getParsedHeaders($msg_id);
        $h = new rcube_message_header();
        $h->id = $msg_id;
        // TODO use uidl (but a uidl is not number)
        $h->uid = $msg_id;
        $h->subject = $raw['Subject'];
        $h->messageID = $raw['Message-ID'];
        $h->from = $raw['From'];
        $h->to = $raw['To'];
        $h->date = $raw['Date'];
        $h->timestamp = rcube_imap_generic::strToTime($raw['Date']);

        return $h;
    }

    private function _mime_message($msg_id) {
        $this->log('MESSAGE_HEADER: %s', $msg_id);
        $raw_msg = $this->conn->getMsg($msg_id);

        $struct = rcube_mime::parse_message($raw_msg);
        $headers = $struct->headers;

        $msg = new rcube_message_header();
        $msg->id = $msg_id;
        // TODO use uidl (but a uidl is not number)
        $msg->uid = $msg_id;
        $msg->subject = $headers['subject'];
        $msg->messageID = $headers['message-id'];
        $msg->from = $headers['from'];
        $msg->to = $headers['to'];
        $msg->date = $headers['date'];
        $msg->timestamp = rcube_imap_generic::strToTime($headers['date']);

        $ctype_params = array();
        $msg->bodystructure = array(
            $struct->ctype_primary,
            $struct->ctype_secondary,
            array('charset', $struct->charset),
        );

        $msg->structure = $struct;
        $this->struct_charset = $struct->charset;
        return $this->icache['message'] = $msg;
    }
}
