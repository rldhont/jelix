<?php
/**
* @package     jelix-scripts
* @author      Laurent Jouanneau
* @copyright   2010 Laurent Jouanneau
* @link        http://jelix.org
* @licence     GNU General Public Licence see LICENCE file or http://www.gnu.org/licenses/gpl.html
*/

class closeappCommand extends JelixScriptCommand {

    public  $name = 'closeapp';
    public  $allowed_options = array();
    public  $allowed_parameters = array();

    public  $syntaxhelp = "";
    public  $help = '';

    function __construct(){
        $this->help= array(
            'fr'=>"
    Ferme l'application. Elle ne sera plus accessible depuis le web.
    ",
            'en'=>"
    Close the application. It will not accessible anymore from the web.
    ",
    );
    }

    public function run(){
        jAppManager::close();
    }
}