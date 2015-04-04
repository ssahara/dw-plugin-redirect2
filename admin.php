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

    protected $LogFile;

    function __construct() {
        global $conf;
        $this->LogFile = $conf['cachedir'].'/redirection.log';
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
        $map = plugin_load('helper', $this->getPluginName());

        if ($_POST['redirdata'] && checkSecurityToken()) {
            if (io_saveFile($map->ConfFile, cleanText($INPUT->str('redirdata')))) {
                msg($this->getLang('saved'), 1);
            }
        }
    }

    /**
     * output appropriate html
     */
    function html() {
        global $lang;
        $map = plugin_load('helper', $this->getPluginName());

        echo $this->locale_xhtml('intro');
        echo '<form action="" method="post" >';
        echo '<input type="hidden" name="do" value="admin" />';
        echo '<input type="hidden" name="page" value="'.$this->getPluginName().'" />';
        echo '<input type="hidden" name="sectok" value="'.getSecurityToken().'" />';
        echo '<textarea class="edit" rows="15" cols="80" style="height: 300px" name="redirdata">';
        echo formtext(io_readFile($map->ConfFile));
        echo '</textarea><br />';
        echo '<input type="submit" value="'.$lang['btn_save'].'" class="button" />';
        echo '</form>';

        if (!$this->getConf('logging')) return;
        $logData = $this->getLogData();

        echo $this->locale_xhtml('loginfo');
        echo '<br />';
        echo '<table class="'.$this->getPluginName().'">';
        echo '<tr><th>Count</th><th>Status</th><th>Page/Media</th><th>Redirect to</th><th>Recent redirection</th></tr>';
        foreach ($logData as $id => $data) {
            echo '<tr>';
            echo '<td>'.$data['count'].'</td>';
            echo '<td>'.$data['status'].'</td>';
            echo '<td>'.hsc($id).'</td>';
            echo '<td>'.urldecode($data['redirect']).'</td>';
            echo '<td>'.$data['last'].'</td>';
            echo '</tr>';
        }
        echo '</table>';

    }


    /**
     * Load redierction data from log file
     */
    protected function getLogData() {
        $logData = array();
        if (!file_exists($this->LogFile)) return $logData;

        $logfile = new SplFileObject($this->LogFile);
        $logfile->setFlags(SplFileObject::READ_CSV);
        $logfile->setCsvControl("\t"); // tsv

        foreach ($logfile as $line) {
            if ($line[0] == NULL) continue;
            list($datetime, $status, $id, $url) = $line;
            if (!isset($logData[$id])) {
                $logData[$id] = array(
                        'count'    => 1,
                        'status'   => $status,
                        'redirect' => $url,
                        'last'     => $datetime,
                );
            } else {
                $logData[$id]['count'] ++;
                if ($datetime > $logData[$id]['last']) {
                    $logData[$id]['last'] = $datetime;
                }
            }
        }
        unset($logfile);
        uasort($logData, array($this, 'compareCounts'));
        return $logData;
    }

    protected function compareCounts($a, $b) {
        return $b['count'] - $a['count'];
    }

}
//Setup VIM: ex: et ts=4 enc=utf-8 :