<?php
/**
 * Redirect2 - DokuWiki Redirect Manager
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */
 
if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');
 
class action_plugin_redirect2 extends DokuWiki_Action_Plugin {

    protected $LogFile;

    /**
     * Register event handlers
     */
    function register(Doku_Event_Handler $controller) {

        $controller->register_hook('DOKUWIKI_STARTED', 'BEFORE',    $this, 'handleReplacedBy');
        $controller->register_hook('ACTION_HEADERS_SEND', 'BEFORE', $this, 'redirectPage');
        $controller->register_hook('FETCH_MEDIA_STATUS', 'BEFORE',  $this, 'redirectMedia');
        $controller->register_hook('TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'errorDocument404');

    }

    function __construct() {
        global $conf;
        $this->LogFile  = $conf['cachedir'].'/redirection.log';
    }


    /**
     * ErrorDocument404 - not found response
     * show 404 wiki page instead of inc/lang/<iso>/newpage.txt
     * TPL_CONTENT_DISPLAY:BEFORE event handler
     */
     function errorDocument404(&$event, $param) {
        global $ACT, $ID, $INFO;

         if ( $INFO['exists'] || ($ACT != 'show') ) return false;
         $page = $this->getConf('404page');
         if (empty($page)) return false;

         $event->stopPropagation();
         $event->preventDefault();

         $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
         $this->_logRedirection(404, $ID, $referer);
         echo p_wiki_xhtml($this->getConf('404page'), false);
         return true;
     }


    /**
     * Get redirect URL, let redirection ready
     * 
     * @param int $status  redirect status, 301 or 302
     * @param string $dest redirect destination, id or external url
     * @return mixed       url of the destination page/media, or false
     */
    private function getRedirectURL($status = 302, $dest) {
        global $ID;

        // check whether visit again using breadcrums trace
        if ($this->_foundInBreadcrumbs(cleanID($dest))) {
            msg('halted redirection from '.$ID.' to '.$dest.' due to prevent loop.', -1);
            return false;
        } else {
            // logging
            $this->_log_redirection($status, $ID, $dest); 

            // generate url
            if (preg_match('@^(https?://|/)@', $dest)) {
                $url = $id;   // external url
            } else {
                resolve_pageid(getNS($ID), $id, $exists);
                list($ext, $mime) = mimetype($id);
                if ($ext) {  // media
                    $url = ml($id);
                } else {      // page
                    list($page, $section) = explode('#', $id, 2);
                    $url = wl($page);
                    if (!empty($section)) $url.= '#'.rawurlencode($section);

                    // output message, to be shown at destination page (after redirect)
                    $this->_show_message($status, 'redirected_from', $ID);
                }
            }
            return $url;
        }
    }


    /**
     * prevent infinite redirection loop by breadcrumbs (session cookie)
     *
     * @return bool  true if id found in breadcrumbs
     */
    private function _foundInBreadcrumbs ($id) {
        list($page, $section) = explode('#', $id, 2);

        if (isset($_SESSION[DOKU_COOKIE]['bc'])) {
            $hist = $_SESSION[DOKU_COOKIE]['bc'];
            if (array_key_exists($page, $_SESSION[DOKU_COOKIE]['bc'])) {
                error_log('$id= '.$page."\n".'found in $hist= '.var_export($hist, true));
                error_log('redirect must stop! '.$id);
                return true;
            }
        }
        return false;
    }


    /**
     * Page redirection based on metadata 'relation isreplacedby'
     * that is set by syntax component
     * DOKUWIKI_STARTED:BEFORE event handler
     */
    function handleReplacedBy(&$event, $param) {
        global $ID, $ACT, $REV, $INPUT;

        if (($ACT != 'show' && $ACT != '') || $REV) return;

        // return if no redirection data
        $id = p_get_metadata($ID,'relation isreplacedby');
        if (empty($id)) return;

        // check whether redirection is temporarily disabled by url paramter
        if (is_null($INPUT->str('redirect', NULL))) {
            // Redirect current page
            $dest = $id;
            $status = 301;
            $url = $this->getRedirectURL($status, $dest);
            if ($url !== false) {
                http_status($status);
                send_redirect($url);
                exit;
            }
        }
        return;
    }


    /**
     * Redirection of pages based on redirect.conf file
     * ACTION_HEADERS_SEND:BEFORE event handler
     */
    function redirectPage(&$event, $param){
        global $ACT, $ID, $INPUT;

        if( !($ACT == 'show' || (!is_array($ACT) && substr($ACT, 0, 7) == 'export_')) ) return;

        // return if redirection is temporarily disabled by url paramter
        if ($INPUT->str('redirect',NULL) == 'no') return;

        // read redirect map
        $map = $this->loadHelper($this->getPluginName());
        if (empty($map)) return false;

        /*
         * Redirect based on simple prefix match of the current page
         * (Redirect Directives)
         */
        $leaf = noNS($ID); // end token of the pageID
        $checkID = $ID;
        do {
            if (isset($map->pattern[$checkID])) {
                $dest = $map->pattern[$checkID]['destination'];
                list($ns, $section) = explode('#', $dest, 2);
                // add leaf to $dest, considering url fragment
                if (preg_match('@(:|/)$@', $ns)) $ns.= $leaf;
                if (!empty($section)) $ns.= '#'.rawurlencode($section);
                $dest = $ns;

                $status = $map->pattern[$checkID]['status'];
                $url = $this->getRedirectURL($status, $dest);
                if ($url !== false) {
                    http_status($status);
                    send_redirect($url);
                    exit;
                }
            }
            // check hierarchic namespace replacement
            $checkID = ($checkID == ':') ? false : getNS(rtrim($checkID,':')).':';
        } while ($checkID != false);

        /*
         * Redirect based on a regular expression match against the current page
         * (RedirectMatch Directives)
         */
        if ($this->getConf('useRedirectMatch')) {
            $redirect = $this->_RedirectMatch($ID, $map);
            if ($redirect !== false) {
                $dest = $redirect['destination'];
                $status = $redirect['status'];
                $url = $this->getRedirectURL($status, $dest);
                if ($url !== false) {
                    http_status($status);
                    send_redirect($url);
                    exit;
                }
            }
        }
        return true;
    }


    /**
     * Redirect of media based on redirect.conf file
     * FETCH_MEDIA_STATUS event handler
     * @see also https://www.dokuwiki.org/devel:event:fetch_media_status
     */
    function redirectMedia(&$event, $param) {

        // read redirect map
        $map = $this->loadHelper($this->getPluginName());
        if (empty($map)) return false;

        /*
         * Redirect based on simple prefix match of the current media
         * (Redirect Directives)
         */
        $leaf = noNS($event->data['media']); // end token of the mediaID
        // for media, $checkID need to be clean with ':' prefixed
        $checkID = ':'.ltrim($event->data['media'],':');
        do {
            if (isset($map->pattern[$checkID])) {
                $dest = $map->pattern[$checkID]['destination'];
                list($ns, $section) = explode('#', $dest, 2);
                // add leaf to $dest, considering url fragment
                if (preg_match('@(:|/)$@', $ns)) $ns.= $leaf;
                if (!empty($section)) $ns.= '#'.rawurlencode($section);
                $dest = $ns;

                $status = $map->pattern[$checkID]['status'];
                $url = $this->getRedirectURL($status, $dest);
                if ($url !== false) {
                    $event->data['status'] = $status;
                    $event->data['statusmessage'] = $url;
                    return; // Redirect will happen at lib/exe/fetch.php
                }
            }
            // check hierarchic namespace replacement
            $checkID = ($checkID == '::') ? false : ':'.getNS(trim($checkID,':')).':';
        } while ($checkID != false);

        /*
         * Redirect based on a regular expression match against the current media
         * (RedirectMatch Directives)
         */
        if ($this->getConf('useRedirectMatch')) {
            $checkID = ':'.ltrim($event->data['media'],':');
            $redirect = $this->_RedirectMatch($checkID, $map);
            if ($redirect !== false) {
                $dest = $redirect['destination'];
                $status = $redirect['status'];
                $url = $this->getRedirectURL($status, $dest);
                if ($url !== false) {
                    $event->data['status'] = $status;
                    $event->data['statusmessage'] = $url;
                    return; // Redirect will happen at lib/exe/fetch.php
                }
            }
        }
        return true;
    }


    /**
     * Resolve destination page/media id by regular expression match
     * using rediraction pattern map config file
     *
     * @param string $checkID  full and cleaned name of page or media
     *                         for the page, it must be clean id
     *                         for media, it must be clean with ':' prefixed
     * @param array $map       redirect map
     * @return array of status and destination (id), or false if no matched
     */
    protected function _RedirectMatch( $checkID, $map ) {
        foreach ($map->pattern as $pattern => $data) {
            if (preg_match('/^%.*%$/', $pattern) !== 1) continue;
            $destID = preg_replace( $pattern, $data['destination'], $checkID, -1, $count);
            if ($count > 0) {
                $status = $data['status'];
                return array('status' => $status, 'destination' => $destID);
                break;
            }
        }
        return false;
    }


    /**
     * Show message to inform user redirection
     * 
     * @param int    $status   http status of the redirection
     * @param string $format   key name for message string
     * @param string $id
     */
    protected function _show_message($status, $format, $id=NULL) {
        global $ID, $INFO, $INPUT;

        $show = ( ($INFO['isadmin'] && ($this->getConf('msg_target') >= 0))
               || ($INFO['ismanager'] && ($this->getConf('msg_target') >= 1))
               || ($INPUT->server->has('REMOTE_USER') && ($this->getConf('msg_target') >= 2))
               || ($this->getConf('msg_target') >= 3) );
        if (!$show) return;

        switch ($format) {
            case 'redirected_from':
                if ( ($this->getConf('show_msg') == 0) ||
                    (($this->getConf('show_msg') == 1) && ($status != 301)) ) {
                    break; // no need to show message
                }
                if (is_null($id)) $id = $ID;
                $title = hsc(p_get_metadata($id, 'title'));
                if (empty($title)) {
                    $title = hsc(useHeading('navigation') ? p_get_first_heading($id) : $id);
                }
                $class = ($INFO['exists']) ? 'wikilink1' : 'wikilink2';
                msg(sprintf($this->getLang('redirected_from'), '<a href="'.
                    wl($id, array('redirect' => 'no'), TRUE, '&').'" rel="nofollow"'.
                    ' class="'.$class.'" title="'.$title.'">'.$title.'</a>'), 0);
                break;
            case 'redirect_to':
                if ($this->getConf('show_msg') == 0) break;
                if (empty($id)) {
                    $referer = $INPUT->server->str('HTTP_REFERER');
                    msg(sprintf($this->getLang('redirect_to'), '<a href="'.
                        hsc($referer).'">'.urldecode($referer).'</a>'), 0);
                } else {
                    $title = hsc(p_get_metadata($id, 'title'));
                    if (empty($title)) {
                        $title = hsc(useHeading('navigation') ? p_get_first_heading($id) : $id);
                    }
                    $class = ($INFO['exists']) ? 'wikilink1' : 'wikilink2';
                    msg(sprintf($this->getLang('redirect_to'), '<a href="'.
                        wl($id).'" rel="nofollow"'.
                        ' class="'.$class.'" title="'.$title.'">'.$title.'</a>'), 0);
                }
                break;
        }
    }


    /**
     * Logging of redirection
     */
    protected function _log_redirection($status, $orig, $dest='') {
        if (!$this->getConf('logging')) return;

        $s = date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']);
        if ($status == 404) {
            // $url is referer of the $id page
            $s.= "\t".$status."\t".$orig."\t".$dest;
        } else {
            // $url is new url to which redirected from the $id page
            $s.= "\t".$status."\t".$orig."\t".$dest;
        }
        io_saveFile($this->LogFile, $s."\n", true);
    }

}
