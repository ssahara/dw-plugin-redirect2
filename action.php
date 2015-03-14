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

    protected $ConfFile = 'redirect.conf';
    protected $redirectPages = array();
    protected $redirectURLs  = array();

    function __construct() {
        // Look for the redirect file in plugin directory
        $this->ConfFile = dirname(__FILE__) .'/'. $this->ConfFile;

        $lines = @file($this->ConfFile);
        foreach ($lines as $line) {
            if (preg_match('/^#/',$line)) continue;     // 行頭#はコメント行
            $line = str_replace('\\#','#', $line);      // #をエスケープしている場合は戻す
            $line = preg_replace('/\s#.*$/','', $line); // 空白後の#以降はコメントと見なして除去
            $line = trim($line);
            if (empty($line)) continue;

            $token = preg_split('/\s+/', $line, 2);
            if (preg_match('/^%.*%$/', $token[0])) {
                // 正規表現を指定した場合
                $this->redirectURLs[$token[0]] = $token[1];
            } else {
                // ページ名
                $this->redirectPages[$token[0]] = $token[1];
            }
        }
    }



    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER',     $this, '_redirect_simple');
        $controller->register_hook('ACTION_HEADERS_SEND', 'BEFORE', $this, '_redirect_match');
    }


    /*
     * Redirect - based on simple prefix match of the current pagename
     */
    public function _redirect_simple(&$event, $param){
        global $ID, $ACT;

        if ($ACT != 'show') return;

        // return if redirection is temporarily disabled by url paramter
        if (isset($_GET['redirect']) && $_GET['redirect'] == 'no') return;

        if ($this->redirectPages[$ID]) {
            if (preg_match('/^https?:\/\//', $this->redirectPages[$ID])) {
                send_redirect($this->redirectPages[$ID]);
            } else {
                if ($this->getConf('showmsg')) {
                    msg(sprintf($this->getLang('redirected'), hsc($ID)));
                }
                $link = explode('#', $this->redirectPages[$ID], 2);
                send_redirect(wl($link[0] ,'',true) . '#' . rawurlencode($link[1]));
            }
            exit;
        }
    }


    /*
     * RedirectMatch - based on a regular expression match of the current URL
     */
    function _redirect_match(&$event, $param) {
        global $INFO, $ACT, $conf, $ID;

        if ( $INFO['exists'] ) return;
        if ( !($ACT == 'notfound' || $ACT == 'show' || substr($ACT,0,7) == 'export_') ) return;

        // 
        if (strpos($_SERVER["REQUEST_URI"], '?') === false) {
            $checkID = $_SERVER['REQUEST_URI'];
        } else {
            $checkID = substr($_SERVER['REQUEST_URI'], 0, strpos($_SERVER['REQUEST_URI'], '?'));
        }
        if ( substr($checkID, 0, 1) != '/' ) $checkID = '/'.$checkID;

        $url = preg_replace( array_keys($this->redirectURLs), array_values($this->redirectURLs), strtolower($checkID));
        if ( substr($url , -1)  == '/' ) $url .= $conf['start'];
        
        if ( $url == strtolower($checkID) ) return;
        
        # referer must be set - and its not a bot.
        if ( $this->getConf('doLog') && !empty($_SERVER['HTTP_REFERER']) 
          && !preg_match('/(?i)bot/', $_SERVER['HTTP_USER_AGENT'])) {
            dbglog("Redirecting: '{$checkID}' to '{$url}'");
        }

        if ( !empty($_GET) ) {
            unset($_GET['id']);
            $params = '';
            foreach( $_GET as $key => $value ) {
                if ( !empty($params) ) { $params .= '&'; }
                $params .= urlencode($key).'='.urlencode($value);
            }
            if ( !empty($params) ) { $url .= '?'.$params; }
        }

        if ( $url != $_SERVER['REQUEST_URI'] ) {
            send_redirect($url);
            exit;
        }
    }

}
