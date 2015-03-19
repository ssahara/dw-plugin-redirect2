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



    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER',     $this, 'redirect');
        $controller->register_hook('TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'errorDocument404');
    }


    /*
     * Redirection
     */
    public function redirect(&$event, $param){
        global $ACT, $ID, $INFO, $conf;

        if (empty($this->pattern)) return;
        if ( !($ACT == 'show' || substr($ACT,0,7) == 'export_') ) return;

        // return if redirection is temporarily disabled by url paramter
        if (isset($_GET['redirect']) && $_GET['redirect'] == 'no') return;

        /*
         * Redirect based on simple prefix match of the current pagename
         * (Redirect Directives)
         */
        $checkID = $ID;
        do {
            if (isset($this->pattern[$checkID])) {
                if (preg_match('/^https?:\/\//', $this->pattern[$checkID]['destination'])) {
                    http_status($this->pattern[$checkID]['status']);
                    send_redirect($this->pattern[$checkID]['destination']);
                } else {
                    if ($this->getConf('show_msg')) {
                        $title = hsc(useHeading('navigation') ? p_get_first_heading($ID) : $ID);
                        $class = ($INFO['exists']) ? 'wikilink1' : 'wikilink2';
                        msg(sprintf($this->getLang('redirected_from'), '<a href="'.
                            wl($ID, array('redirect' => 'no'), TRUE, '&').'" rel="nofollow"'.
                            ' class="'.$class.'" title="'.$title.'">'.$title.'</a>'), 0);
                    }
                    $link = explode('#', $this->pattern[$checkID]['destination'], 2);
                    $url = wl($link[0] ,'',true);
                    if (!empty($link[1])) $url.= '#'.rawurlencode($link[1]);
                    http_status($this->pattern[$checkID]['status']);
                    send_redirect($url);
                }
                exit;
            }
            
            // check prefix hierarchic namespace replacement
            $checkID = ($checkID !=':') ? getNS(rtrim($checkID,':')).':' : false;
        } while ($checkID != false);

        /*
         * Redirect based on a regular expression match of the current URL
         * (RedirectMatch Directives)
         */
        list($checkID, $rest) = explode('?',$_SERVER['REQUEST_URI'],2);
        if ( substr($checkID, 0, 1) != '/' ) $checkID = '/'.$checkID;

        foreach ($this->pattern as $pattern => $data) {
            if (preg_match('/^%.*%$/', $pattern) !== 1) continue;
            $url = preg_replace( $pattern, $data['destination'], strtolower($checkID), -1, $count);
            if ($count > 0) {
                $status = $data['status'];
                break;
            }
        }
        $url.= (substr($url, -1) == '/') ? $conf['start'] : '';
        $url.= (!empty($rest)) ? '?'.$rest : '';

        if (strcasecmp($url, $_SERVER['REQUEST_URI']) != 0) {
            if ($this->getConf('show_msg')) {
                msg(sprintf($this->getLang('redirected_from'), '<a href="'.
                    $checkID.'?redirect=no'.'" rel="nofollow">'.hsc($checkID).'</a>'), 0);
            }
            http_status($status);
            send_redirect($url);
            exit;
        }
        
    }


    /*
     * ErrorDocument404 - not found response
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


}
