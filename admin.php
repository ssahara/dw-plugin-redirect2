<?php
/**
 * Redirect2 - DokuWiki Redirect Manager
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'admin.php');

class admin_plugin_redirect2 extends DokuWiki_Admin_Plugin {

    protected $ConfFile; // path/to/redirection config file
    protected $LogFile;
    protected $LogData;

    function __construct() {
        global $conf;

        $this->ConfFile = DOKU_CONF.'redirect.conf';
        $this->LogFile = $conf['cachedir'].'/redirection.log';
        $this->LogData = array();
    }

    /**
     * Access for managers allowed
     */
    function forAdminOnly(){ return false; }

    /**
     * return sort order for position in admin menu
     */
    function getMenuSort() { return 140; }

    /**
     * return prompt for admin menu
     */
    function getMenuText($language) { return $this->getLang('name'); }

    /**
     * handle user request
     */
    function handle() {
        global $INPUT;
        if ($_POST['redirdata'] && checkSecurityToken()) {
            if (io_saveFile($this->ConfFile, cleanText($INPUT->str('redirdata')))) {
                msg($this->getLang('saved'), 1);
            }
        }
    }

    /**
     * output appropriate html
     */
    function html() {
        global $lang;
        echo $this->locale_xhtml('intro');
        echo '<form action="" method="post" >';
        echo '<input type="hidden" name="do" value="admin" />';
        echo '<input type="hidden" name="page" value="'.$this->getPluginName().'" />';
        echo '<input type="hidden" name="sectok" value="'.getSecurityToken().'" />';
        echo '<textarea class="edit" rows="15" cols="80" style="height: 300px" name="redirdata">';
        echo formtext(io_readFile($this->ConfFile));
        echo '</textarea><br />';
        echo '<input type="submit" value="'.$lang['btn_save'].'" class="button" />';
        echo '</form>';

        if (!$this->getConf('logging')) return;
        $this->loadLogData();

        echo '<br />';
        echo '<br />';
        echo '<table class="'.$this->getPluginName().'">';
        echo '<tr><th>Count</th><th>Page/Media</th><th>Redirect to</th><th>Recent redirection</th></tr>';
        foreach ($this->LogData as $id => $data) {
            echo '<tr>';
            echo '<td>'.$data['count'].'</td>';
            echo '<td>'.$id.'</td>';
            echo '<td>'.urldecode($data['redirect']).'</td>';
            echo '<td>'.$data['last'].'</td>';
            echo '</tr>';
        }
        echo '</table>';

    }


    /**
     * Load log file
     */
    function loadLogData() {
        if (!file_exists($this->LogFile)) return;
        
        $logfile = new SplFileObject($this->LogFile);
        $logfile->setFlags(SplFileObject::READ_CSV);
        $logfile->setCsvControl("\t"); // tsv

        foreach ($logfile as $line) {
            if ($line[0] == NULL) continue;
            list($datetime, $id, $url) = $line;
            if (!isset($this->LogData[$id])) {
                $this->LogData[$id] = array('count' => 1, 'redirect' => $url, 'last' => $datetime);
            } else {
                $this->LogData[$id]['count'] ++;
                if ($datetime > $this->LogData[$id]['last']) 
                    $this->LogData[$id]['last'] = $datetime;
            }
        }
        unset($logfile);
        uasort($this->LogData, array($this, 'compareCounts'));
    }

    protected function compareCounts($a, $b) {
        return $b['count'] - $a['count'];
    }

}
//Setup VIM: ex: et ts=4 enc=utf-8 :