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
     * Page redirection based on metadata 'relation isreplacedby'
     * that is set by syntax component
     */
    function handleReplacedBy(&$event, $param) {
        global $ID, $ACT, $REV, $INPUT;

        if (($ACT != 'show' && $ACT != '') || $REV) return;

        // return if no redirection data
        $id = p_get_metadata($ID,'relation isreplacedby');
        if (empty($id)) return;

        list($page, $section) = explode('#', $id, 2);
        $section = (isset($section)) ? '#'.$section : '';

        // check whether redirection is temporarily disabled by url paramter
        if (is_null($INPUT->str('redirect', NULL))) {
            // Redirect current page

            // prepare link for internal redirects, keep external targets
            if (!preg_match('#^https?://#i', $page)) {
                $url = wl($page, array('redirect'=>301));                
            }

            // redirect
            $status = 301;
            $this->_show_message($status, 'redirected_from'); // message shown at destination
            http_status($status);
            exit;

        } else {
            // check whether visited again from previous redirection
            if (isset($_SESSION[DOKU_COOKIE]['bc'])) {
                $hist = $_SESSION[DOKU_COOKIE]['bc'];
                end($hist);
                list($key, $value) = each($hist);
                if ($key == $ID) {
                    //$this->_show_message(200, 'redirect_to', cleanID($id));
                }
            }
        }
    }


    /**
     * Redirection of pages based on redirect.conf file
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
                $url = $this->_buildURL( $map->pattern[$checkID]['destination'], $leaf);
                $status = $map->pattern[$checkID]['status'];
                $this->_show_message($status, 'redirected_from'); // message shown at destination
                $this->_logRedirection($status, $ID, $url);
                $_SESSION[DOKU_COOKIE]['redirect'] = $ID;     // 実験的
                http_status($status);
                send_redirect($url);
                exit;
            }
            // check hierarchic namespace replacement
            $checkID = ($checkID == ':') ? false : getNS(rtrim($checkID,':')).':';
        } while ($checkID != false);

        /*
         * Redirect based on a regular expression match against the current page
         * (RedirectMatch Directives)
         */
        $redirect = $this->_RedirectMatch($ID, $map);
        if ($redirect !== false) {
            $url = $this->_buildURL( $redirect['destination'], '');
            $status = $redirect['status'];
            $this->_show_message($status, 'redirected_from'); // message shown at destination
            $this->_logRedirection($status, $ID, $url);
            $_SESSION[DOKU_COOKIE]['redirect'] = $ID;     // 実験的
            http_status($status);
            send_redirect($url);
            exit;
        }
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
                $url = $this->_buildURL($map->pattern[$checkID]['destination'], $leaf);
                $status = $map->pattern[$checkID]['status'];
                $this->_logRedirection($status, $event->data['media'], $url);
                $event->data['status'] = $status;
                $event->data['statusmessage'] = $url;
                return; // Redirect will happen at lib/exe/fetch.php
            }
            // check hierarchic namespace replacement
            $checkID = ($checkID == '::') ? false : ':'.getNS(trim($checkID,':')).':';
        } while ($checkID != false);
        
        /*
         * Redirect based on a regular expression match against the current media
         * (RedirectMatch Directives)
         */
        $checkID = ':'.ltrim($event->data['media'],':');
        $redirect = $this->_RedirectMatch($checkID, $map);
        if ($redirect !== false) {
            $url = $this->_buildURL($redirect['destination'],'');
            $status = $redirect['status'];
            $this->_logRedirection($status, $event->data['media'], $url);
            $event->data['status'] = $status;
            $event->data['statusmessage'] = $url;
            return; // Redirect will happen at lib/exe/fetch.php
        }
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
     * Build URL used in send_redirect()
     * 
     * @param string $id   id of the page or media of destination of redirect
     * @param string $leaf (optional) last token of the original page/media id
     * @return string      url of the destination page/media
     */
    protected function _buildURL( $id, $leaf='') {
        if (preg_match('|^https?:\/\/|', $id)) {
            // external url, append $leaf if the url end with "/"
            $url = $id;
            if (substr($url,-1)=='/') $url.= $leaf;

        } elseif (strpos($id, '.') === false) {
            // page, append $leaf if the id end with ":"
            list($page, $section) = explode('#', $id, 2);
            if (substr($page,-1) == ':') $page.= $leaf;
            $url = wl($page);
            if (!empty($section)) $url.= '#'.rawurlencode($section);
        } else {
            // media, append $leaf if the id end with ":"
           if (substr($id,-1) == ':') $id.= $leaf;
           $url = ml($id);
        }
        return $url;
    }

    /**
     * Logging of redirection
     */
    protected function _logRedirection($status, $id, $url='') {
        if (!$this->getConf('logging')) return;
        $s = date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']);
        if ($status == 404) {
            // $url is referer of the $id page
            $s.= "\t".$status."\t".$id."\t".$url;
        } else {
            // $url is new url to which redirected from the $id page
            $s.= "\t".$status."\t".$id."\t".$url;
        }
        io_saveFile($this->LogFile, $s."\n", true);
    }

}
