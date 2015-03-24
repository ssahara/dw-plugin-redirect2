<?php
/**
 * Redirect2 - DokuWiki Redirect Manager
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */
 
if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');
 
class action_plugin_redirect2 extends DokuWiki_Action_Plugin {

    protected $ConfFile; // path/to/redirection config file
    protected $pattern = array();

    function __construct() {
        $this->ConfFile = DOKU_CONF.'redirect.conf';

        $lines = @file($this->ConfFile);
        if (!$lines) return;
        foreach ($lines as $line) {
            if (preg_match('/^#/',$line)) continue;     // 行頭#はコメント行
            $line = str_replace('\\#','#', $line);      // #をエスケープしている場合は戻す
            $line = preg_replace('/\s#.*$/','', $line); // 空白後の#以降はコメントと見なして除去
            $line = trim($line);
            if (empty($line)) continue;

            $token = preg_split('/\s+/', $line, 3);
            if (count($token) == 3) { // status  %regex%  url
                $this->pattern[$token[1]] = array(
                        'destination' => $token[2], 'status' => $token[0],
                );
            } elseif (count($token) == 2) { // %regex%  url
                $this->pattern[$token[0]] = array(
                        'destination' => $token[1], 'status' => 302,
                );
            }
        }
    }


    /**
     * Register event handlers
     */
    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER',     $this, 'redirectPage');
        $controller->register_hook('TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'errorDocument404');
        $controller->register_hook('FETCH_MEDIA_STATUS', 'BEFORE',  $this, 'redirectMedia');
    }


    /*
     * Redirection of pages
     */
    function redirectPage(&$event, $param){
        global $ACT, $ID, $INPUT;

        if (empty($this->pattern)) return;
        if( !($ACT == 'show' || (!is_array($ACT) && substr($ACT, 0, 7) == 'export_')) ) return;

        // return if redirection is temporarily disabled by url paramter
        if ($INPUT->str('redirect',null) == 'no') return;

        /*
         * Redirect based on simple prefix match of the current page
         * (Redirect Directives)
         * ページをConfファイルで指定する場合は、":"で始めてはならない。
         * ":"で始まる場合は メディアファイルの指定と見なすため。
         */
        $leaf = noNS($ID); // end token of the pageID
        $checkID = $ID;
        do {
            if (isset($this->pattern[$checkID])) {
                if ($this->getConf('show_msg')) $this->_show_message();
                $url = $this->_buildURL( $this->pattern[$checkID]['destination'], $leaf);
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
            if ($this->getConf('show_msg')) $this->_show_message();
            $url = $this->_buildURL( $redirect['destination'], '');
            http_status($redirect['status']);
            send_redirect($url);
            exit;
        }
    }


    /*
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


    /*
     * Redirect of media
     * FETCH_MEDIA_STATUS event handler
     * @see also https://www.dokuwiki.org/devel:event:fetch_media_status
     *
     * メディアファイルをConfファイルで指定する場合は必ず":"で始めること。
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
            $event->data['status'] = $redirect['status'];
            $event->data['statusmessage'] = $url;
            // Redirect will happen at lib/exe/fetch.php
        }
    }


    /*
     * Resolve destination page/media id by regular expression match
     * using rediraction pattern map config file
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

    /*
     * Show message to inform user redirection
     *
     * タイトル取得に page title プラグインを考慮するように改造予定
     */
    protected function _show_message() {
        global $ID, $INFO;
        $title = hsc(useHeading('navigation') ? p_get_first_heading($ID) : $ID);
        $class = ($INFO['exists']) ? 'wikilink1' : 'wikilink2';
        msg(sprintf($this->getLang('redirected_from'), '<a href="'.
                    wl($ID, array('redirect' => 'no'), TRUE, '&').'" rel="nofollow"'.
                    ' class="'.$class.'" title="'.$title.'">'.$title.'</a>'), 0);
    }

    /*
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
            // page, 
            list($page, $section) = explode('#', $id, 2);
            if (substr($page,-1) == ':') $page.= $leaf;
            $url = wl($page, '', true);
            if (!empty($section)) $url.= '#'.rawurlencode($section);
        } else {
            // media, append $leaf if the id end with ":"
           if (substr($id,-1) == ':') $id.= $leaf;
           $url = ml($id, '', true);
        }
        return $url;
    }

}
