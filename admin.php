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

        if ($INPUT->post->str('redirdata') && checkSecurityToken()) {
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
        echo '<br />';
        echo '</form>';

        if (!$this->getConf('logging')) return;
        $logData = $this->getLogData();

        echo '<br />';
        echo $this->locale_xhtml('loginfo');
        echo '<table class="'.$this->getPluginName().'">';
        echo '<thead><tr>';
        echo '<th>Count</th>';
        echo '<th>Status</th>';
        echo '<th>Page/Media</th>';
        echo '<th>Redirect to | Referer</th>';
        echo '<th>Recent redirection</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($logData as $id => $data) {
            echo '<tr>';
            echo '<td>'.$data['count'].'</td>';
            echo '<td>'.$data['status'].'</td>';
            echo '<td>'.$this->html_atag($id).'</td>';
            echo '<td>'.$this->html_atag($data['dest']).'</td>';
            echo '<td>'.$data['last'].'</td>';
            echo '</tr>';
        }
        echo '</tbody>';
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
            list($datetime, $caller, $status, $orig, $dest) = $line;
            if (!isset($logData[$orig])) {
                $logData[$orig] = array(
                        'count'  => 1,
                        'status' => $status,
                        'dest'   => $dest,
                        'last'   => $datetime,
                );
            } else {
                $logData[$orig]['count'] ++;
                if ($datetime > $logData[$orig]['last']) {
                    $logData[$orig]['last'] = $datetime;
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


    private function getLinkType($id) {
        if (preg_match('@^(https?://|/)@', $id)) {
           $type = 'external';
        } else {
            resolve_pageid(':', $id, $exists); // absolute id
            list($ext, $mime) = mimetype($id);
            if (substr($mime, 0, 5) == 'image') {
                $type = 'image';
            } elseif ($ext) {   // media
                $type = 'media';
            } else {            // page
                $type = 'page';
            }
        }
        return $type;
    }

    private function html_atag($id) {
        $linkType = $this->getLinkType($id);
        $more = array('redirect' => 'no');
        switch ($linkType) {
            case 'media':
                $html = '<a href="'.ml($id, $more).'">';
                $html.= hsc($id).'</a>';
                break;
            case 'page':
                resolve_pageid(':', $id, $exists); // absolute id
                $class = ($exists) ? 'wikilink1' : 'wikilink2';
                $html = '<a href="'.wl($id, $more).'"'.
                        ' class="'.$class.'">'.hsc($id).'</a>';
                break;
            default:
                $html = hsc($id);
        }
        return $html;
    }

}
//Setup VIM: ex: et ts=4 enc=utf-8 :