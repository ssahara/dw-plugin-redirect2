<?php
/**
 * Redirect2 - DokuWiki Redirect Manager
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author Satoshi Sahara <sahara.satoshi@gmail.com>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class helper_plugin_redirect2 extends DokuWiki_Plugin {

    public $ConfFile; // path/to/redirection config file
    public $pattern = NULL;

    /**
     * Setup the redirection map from config file
     *
     * syntax of the config file
     *    [status]   ptnSearch   ptnDestination
     *
     *  status:         301 or 302
     *  ptnSearch:      old id pattern of page or media
     *  ptnDestination: new id pattern of page or media
     *
     */
    function __construct() {
        $this->ConfFile = DOKU_CONF.'redirect.conf';

        if ($this->pattern != NULL) return;

        $cache = new cache('##redirect2##','.conf');
        $depends = array('files' => array($this->ConfFile));

        if ($cache->useCache($depends)) {
            $this->pattern = unserialize($cache->retrieveCache(false));
            //error_log('Redirect2 : loaded from cache '.$cache->cache);
        } elseif ($this->_loadConfig()) {
            // cache has expired
            //error_log('Redirect2 : loaded from file '.$this->ConfFile);
            $cache->storeCache(serialize($this->pattern));
        }
    }

    function __destruct() {
        $this->pattern = NULL;
    }

    protected function _loadConfig() {
        if (!file_exists($this->ConfFile)) return false;

        $lines = @file($this->ConfFile);
        if (!$lines) return false;
        foreach ($lines as $line) {
            if (preg_match('/^#/',$line)) continue;
            $line = str_replace('\\#','#', $line);
            $line = preg_replace('/\s#.*$/','', $line);
            $line = trim($line);
            if (empty($line)) continue;

            $token = preg_split('/\s+/', $line, 3);
            if (count($token) == 3) {
                $status = ($token[0] == 301) ? 301 : 302;
                array_shift($token);
            } else $status =302;

            if (count($token) != 2) continue;
            if (strpos($token[0], '%') !== 0) { // not regular expression
                // get clean match pattern, keeping leading and tailing ":"
                $head = (substr($token[0],0,1)==':') ? ':' : '';
                $tail = (substr($token[0],-1) ==':') ? ':' : '';
                $ptn = $head . cleanID($token[0]) . $tail;
            } else {
                $ptn = $token[0];
            }
            $this->pattern[$ptn] = array(
                    'destination' => $token[1], 'status' => $status,
            );
        }
        return ($this->pattern != NULL) ? true : false;
    }

}
