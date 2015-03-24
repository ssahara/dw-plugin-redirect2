<?php
/**
 * Redirect2 - DokuWiki Redirect manager
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
        global $ACT, $ID, $INFO, $INPUT, $conf;

        if (empty($this->pattern)) return;
        if( !($ACT == 'show' || (!is_array($ACT) && substr($ACT, 0, 7) == 'export_')) ) return;

        // return if redirection is temporarily disabled by url paramter
        if ($INPUT->str('redirect',null) == 'no') return;

        /*
         * Redirect based on simple prefix match of the current pagename
         * (Redirect Directives)
         * ページをConfファイルで指定する場合は、":"で始めてはならない。
         * ":"で始まる場合は メディアファイルの指定と見なすため。
         */
        $leaf = noNS($ID); // end token of the pageID
        $checkID = $ID;
        do {
            if (isset($this->pattern[$checkID])) {
                if (preg_match('/^https?:\/\//', $this->pattern[$checkID]['destination'])) {
                    $url = $this->pattern[$checkID]['destination'];
                    // リダイレクト先の末尾が"/"の場合、$leafを付加する。
                    if (substr($url,-1)=='/') $url.= $leaf;
                    http_status($this->pattern[$checkID]['status']);
                    send_redirect($url);
                } else {
                    if ($this->getConf('show_msg')) {
                        $title = hsc(useHeading('navigation') ? p_get_first_heading($ID) : $ID);
                        $class = ($INFO['exists']) ? 'wikilink1' : 'wikilink2';
                        msg(sprintf($this->getLang('redirected_from'), '<a href="'.
                            wl($ID, array('redirect' => 'no'), TRUE, '&').'" rel="nofollow"'.
                            ' class="'.$class.'" title="'.$title.'">'.$title.'</a>'), 0);
                    }
                    list($page, $section) = explode('#', $this->pattern[$checkID]['destination'], 2);
                    // リダイレクト先の末尾が":"の場合、$leafを付加する。
                    if (substr($page,-1) == ':') $page.= $leaf;
                    $url = wl($page, '', true);
                    if (!empty($section)) $url.= '#'.rawurlencode($section);
                    http_status($this->pattern[$checkID]['status']);
                    send_redirect($url);
                }
                exit;
            }
            
            // check prefix hierarchic namespace replacement
            // ルート名前空間もリダイレクト可能なはず
            $checkID = ($checkID == ':') ? false : getNS(rtrim($checkID,':')).':';
        } while ($checkID != false);

        /*
         * Redirect based on a regular expression match against the current pagename
         * (RedirectMatch Directives)
         */
        $checkID = $ID;
        foreach ($this->pattern as $pattern => $data) {
            if (preg_match('/^%.*%$/', $pattern) !== 1) continue; // 正規表現以外はスルーする
            $checkID = preg_replace( $pattern, $data['destination'], $checkID, -1, $count);
            if ($count > 0) {
                $status = $data['status'];
                break;
            }
        }
        if ($checkID == $ID) return;

        if ($this->getConf('show_msg')) {
                        $title = hsc(useHeading('navigation') ? p_get_first_heading($ID) : $ID);
                        $class = ($INFO['exists']) ? 'wikilink1' : 'wikilink2';
                        msg(sprintf($this->getLang('redirected_from'), '<a href="'.
                            wl($ID, array('redirect' => 'no'), TRUE, '&').'" rel="nofollow"'.
                            ' class="'.$class.'" title="'.$title.'">'.$title.'</a>'), 0);
        }
        list($page, $section) = explode('#', $checkID, 2);
        $url = wl($page, '', true);
        if (!empty($section)) $url.= '#'.rawurlencode($section);
        http_status($status);
        send_redirect($url);
        exit;
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
     * Media Redirect
     * FETCH_MEDIA_STATUS event handler
     * https://www.dokuwiki.org/devel:event:fetch_media_status
     *
     * メディアファイルをConfファイルで指定する場合は必ず":"で始めること。
     */
    function redirectMedia(&$event, $param) {
        $checkID = $event->data['media'];
        $leaf = noNS($checkID); // end token of the mediaID
        $checkID = (substr($checkID,0,1)!=':') ? ':'.$checkID : $checkID;
        do {
            if (isset($this->pattern[$checkID])) {
                if (preg_match('/^https?:\/\//', $this->pattern[$checkID]['destination'])) {
                    $url = $this->pattern[$checkID]['destination'];
                    // リダイレクト先の末尾が"/"の場合、$leafを付加する。
                    if (substr($url,-1)=='/') $url.= $leaf;
                    $event->data['status'] = $this->pattern[$checkID]['status'];
                    $event->data['statusmessage'] = $url;
                } else {
                    $newID = $this->pattern[$checkID]['destination'];
                    // リダイレクト先の末尾が":"の場合、$leafを付加する。
                    if (substr($newID,-1) == ':') $newID.= $leaf;
                    error_log('mediaRedirect: '.$checkID.' ->'. $newID);
                    $url = ml($newID,'',true);
                    $event->data['status'] = $this->pattern[$checkID]['status'];
                    $event->data['statusmessage'] = $url; 
                }
                break; // Redirect will happen at lib/exe/fetch.php
            }
            // check prefix hierarchic namespace replacement
            // ルート名前空間のメディア全体は"::"始まりで指定する
            $checkID = ($checkID == '::') ? false : ':'.getNS(trim($checkID,':')).':';
        } while ($checkID != false);
        
        // 正規表現ベースでのメディアファイルのリダイレクト
        // Confファイルでの指定は、ページとメディア共通で良いか？
        // 少なくとも、":"始まりの検索パターンを指定するのは面倒。
        // メディアファイルは必ず拡張子がある（ページは拡張子なし）。
    }
}
