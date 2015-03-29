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

    protected $ConfFile; // path/to/redirection config file
    protected $LogFile;
    protected $pattern = array();

    /**
     * Register event handlers
     */
    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER',     $this, 'redirectPage');
        $controller->register_hook('FETCH_MEDIA_STATUS', 'BEFORE',  $this, 'redirectMedia');
        $controller->register_hook('TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'errorDocument404');
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

         echo p_wiki_xhtml($this->getConf('404page'), false);
         return true;
     }


    /**
     * Setup the redirection map from config file
     *
     * config file のフォーマット
     *
     *  [status]   ptnSearch   ptnDestination
     *
     * A) 正規表現を使用しないリダイレクト指定では...
     *   1) ページを指定する場合は、":"で始めてはならない。
     *      ":"で始まる場合は メディアファイルの指定と見なすため。
     *   2) メディアファイルを指定する場合は必ず":"で始めること。
     *
     * B) 正規表現を使用したリダイレクト指定では...
     *   1) Search パターンは "%" で囲むこと
     *   2) メディアファイルは、拡張子を含むように指定すること
     *
     */
    function __construct() {
        global $conf;
        $this->LogFile  = $conf['cachedir'].'/redirection.log';
        $this->ConfFile = DOKU_CONF.'redirect.conf';

        $lines = @file($this->ConfFile);
        if (!$lines) return;
        foreach ($lines as $line) {
            if (preg_match('/^#/',$line)) continue;
            $line = str_replace('\\#','#', $line);
            $line = preg_replace('/\s#.*$/','', $line);
            $line = trim($line);
            if (empty($line)) continue;

            $token = preg_split('/\s+/', $line, 3);
            if (count($token) == 3) {
                $this->pattern[$token[1]] = array(
                        'destination' => $token[2], 'status' => $token[0],
                );
            } elseif (count($token) == 2) {
                $this->pattern[$token[0]] = array(
                        'destination' => $token[1], 'status' => 302,
                );
            }
        }
    }


    /**
     * Redirection of pages
     */
    function redirectPage(&$event, $param){
        global $ACT, $ID, $INPUT;

        if (empty($this->pattern)) return;
        if( !($ACT == 'show' || (!is_array($ACT) && substr($ACT, 0, 7) == 'export_')) ) return;

        // return if redirection is temporarily disabled by url paramter
        if ($INPUT->str('redirect',NULL) == 'no') {
            if ($this->getConf('show_msg')) {
                msg(sprintf($this->getLang('redirect_to'), '<a href="'.
                    hsc($_SERVER['HTTP_REFERER']).'">'.urldecode($_SERVER['HTTP_REFERER']).
                    '</a>'), 0);
            }
            return;
        }

        /*
         * Redirect based on simple prefix match of the current page
         * (Redirect Directives)
         */
        $leaf = noNS($ID); // end token of the pageID
        $checkID = $ID;
        do {
            if (isset($this->pattern[$checkID])) {
                $url = $this->_buildURL( $this->pattern[$checkID]['destination'], $leaf);
                if ($this->getConf('show_msg')) $this->_show_message();
                if ($this->getConf('logging')) $this->_logRedirection($ID, $url);
                http_status($this->pattern[$checkID]['status']);
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
        $redirect = $this->_RedirectMatch($ID);
        if ($redirect !== false) {
            $url = $this->_buildURL( $redirect['destination'], '');
            if ($this->getConf('show_msg')) $this->_show_message();
            if ($this->getConf('logging')) $this->_logRedirection($ID, $url);
            http_status($redirect['status']);
            send_redirect($url);
            exit;
        }
    }


    /**
     * Redirect of media
     * FETCH_MEDIA_STATUS event handler
     * @see also https://www.dokuwiki.org/devel:event:fetch_media_status
     */
    function redirectMedia(&$event, $param) {
        $checkID = $event->data['media'];

        /*
         * Redirect based on simple prefix match of the current media
         * (Redirect Directives)
         */
        $leaf = noNS($checkID); // end token of the mediaID
        // for media, $checkID need to be clean with ':' prefixed
        $checkID = (substr($checkID,0,1)!=':') ? ':'.$checkID : $checkID;
        do {
            if (isset($this->pattern[$checkID])) {
                $url = $this->_buildURL( $this->pattern[$checkID]['destination'], $leaf);
                if ($this->getConf('logging')) $this->_logRedirection($event->data['media'], $url);
                $event->data['status'] = $this->pattern[$checkID]['status'];
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
        $redirect = $this->_RedirectMatch($event->data['media']);
        if ($redirect !== false) {
            $url = $this->_buildURL( $redirect['destination'],'');
            if ($this->getConf('logging')) $this->_logRedirection($event->data['media'], $url);
            $event->data['status'] = $redirect['status'];
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
     * @return array of status and destination (id), or false if no matched
     */
    protected function _RedirectMatch( $checkID ) {
        foreach ($this->pattern as $pattern => $data) {
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
     */
    protected function _show_message() {
        global $ID, $INFO;
        $title = hsc(p_get_metadata($ID, 'title'));
        if (empty($title)) {
            $title = hsc(useHeading('navigation') ? p_get_first_heading($ID) : $ID);
        }
        $class = ($INFO['exists']) ? 'wikilink1' : 'wikilink2';
        msg(sprintf($this->getLang('redirected_from'), '<a href="'.
                    wl($ID, array('redirect' => 'no'), TRUE, '&').'" rel="nofollow"'.
                    ' class="'.$class.'" title="'.$title.'">'.$title.'</a>'), 0);
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
    protected function _logRedirection($id, $url) {
        $t = date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']);
        $s = $t."\t".$id."\t".$url;
        io_saveFile($this->LogFile, $s."\n", true);
    }

}
