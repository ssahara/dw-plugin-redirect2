<?php
/**
 * Redirect2 - DokuWiki Redirect Manager
 *
 * based on DokuWiki Plugin pageredirect (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Elan Ruusamäe <glen@delfi.ee>
 * @author  David Lorentsen <zyberdog@quakenet.org>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class syntax_plugin_redirect2 extends DokuWiki_Syntax_Plugin {

    public function getType() { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort() { return 1; }

    /**
     * Connect lookup pattern to lexer.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('~~REDIRECT>.+?~~', $mode, 'plugin_redirect2');
        $this->Lexer->addSpecialPattern('^#(?i:redirect)\b.*(?=\n)', $mode, 'plugin_redirect2');
    }

    /**
     * Handler to prepare matched data for the rendering process
     *
     * @param   string       $match   The text matched by the patterns
     * @param   int          $state   The lexer state for the match
     * @param   int          $pos     The character position of the matched text
     * @param   Doku_Handler $handler Reference to the Doku_Handler object
     * @return  array Return an array with all data you want to use in render
     */
    public function handle($match, $state, $pos, Doku_Handler $handler){
        // extract target page from match pattern
        if ($match[0] == '#') {     // #REDIRECT PAGE
            $page = substr($match, 10);
        } else {                    // ~~REDIRECT>PAGE~~
            $page = substr($match, 11, -2);
        }
        $page = trim($page);

        if (!preg_match('#^(https?)://#i', $page)) {
            $link = html_wikilink($page);
        } else {
            $link = '<a href="'.hsc($page).'" class="urlextern">'.hsc($page).'</a>';
        }

        // prepare message here instead of in render
        $message = '<div class="noteredirect">'.sprintf($this->getLang('redirect_to'), $link).'</div>';

        return array($page, $message);
    }

    /**
     * Handles the actual output creation.
     *
     * @param   $format   string        output format being rendered
     * @param   $renderer Doku_Renderer reference to the current renderer object
     * @param   $data     array         data created by handler()
     * @return  boolean                 rendered correctly?
     */
    public function render($format, Doku_Renderer $renderer, $data) {
        if ($format == 'xhtml') {
            // add prepared note about redirection
            $renderer->doc .= $data[1];
/*
            // hook into the post render event to allow the page to be redirected
            global $EVENT_HANDLER;
            $action = plugin_load('action','redirect2');
            $EVENT_HANDLER->register_hook('TPL_ACT_RENDER','AFTER', $action, 'handle_redirect2_redirect');
*/
            return true;
        }
        if ($format == 'metadata') {
            // add redirection to metadata
            $renderer->meta['relation']['isreplacedby'] = $data[0];
            return true;
        }
        return false;
    }
}