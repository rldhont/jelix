<?php
/**
* see Jelix/Core/Selector/SelectorInterface.php for documentation about selectors. 
* @package     jelix
* @subpackage  core_selector
* @author      Laurent Jouanneau
* @contributor Thibault Piront (nuKs)
* @copyright   2005-2012 Laurent Jouanneau
* @copyright   2007 Thibault Piront
* @link        http://www.jelix.org
* @licence    GNU Lesser General Public Licence see LICENCE file or http://www.gnu.org/licenses/lgpl.html
*/

/**
 * Generic Action selector
 *
 * main syntax: "module~action@requestType". module should be a valid module name or # (#=says to get
 * the module of the current request). action should be an action name (controller:method or controller_method).
 * all part are optional, but it should have one part at least.
 * @package    jelix
 * @subpackage core_selector
 */
class jSelectorAct extends jSelectorActFast {

    protected $forUrl = false;

    /**
     * @param string $sel  the selector
     * @param boolean $enableRequestPart true if the selector can contain the request part
     * @param boolean $toRetrieveUrl  true if the goal to have this selector is to generate an url
     */
    function __construct($sel, $enableRequestPart = false, $toRetrieveUrl = false){
        $coord = jApp::coord();
        $this->forUrl = $toRetrieveUrl;

        // jSelectorAct is called by the significant url engine parser, before
        // jcoordinator set its properties, so we set a value to avoid a
        // parameter error on jelix_scan_action_sel. the value doesn't matter
        // since the significant parser call jSelectorAct only for 404 page
        if ($coord->actionName === null)
            $coord->actionName = 'default:index';

        if ($this->_scan_act_sel($sel, $coord->actionName)) {

            if ($this->module == '#') {
                $this->module = $coord->moduleName;
            }
            elseif ($this->module =='') {
                $this->module = jApp::getCurrentModule ();
            }

            if ($this->request == '' || !$enableRequestPart)
                $this->request = $coord->request->type;

            $this->_createPath();
        }else{
            throw new jExceptionSelector('jelix~errors.selector.invalid.syntax', array($sel,$this->type));
        }
    }

    protected function _scan_act_sel($selStr, $actionName) {
        if (preg_match("/^(?:([a-zA-Z0-9_\.]+|\#)~)?([a-zA-Z0-9_:]+|\#)?(?:@([a-zA-Z0-9_]+))?$/", $selStr, $m)) {
            $m = array_pad($m, 4, '');
            $this->module = $m[1];
            if ($m[2] == '#')
                $this->resource = $actionName;
            else
                $this->resource = $m[2];
            $r = explode(':',$this->resource);
            if (count($r) == 1){
                $this->controller = 'default';
                $this->method = $r[0]==''?'index':$r[0];
            }
            else {
                $this->controller = $r[0]=='' ? 'default':$r[0];
                $this->method = $r[1]==''?'index':$r[1];
            }
            $this->resource = $this->controller.':'.$this->method;
            $this->request = $m[3];
            return true;
        }
        return false;
    }

    protected function _createPath(){
        $conf = jApp::config();
        if (isset($conf->_modulesPathList[$this->module])) {
            $p = $conf->_modulesPathList[$this->module];
        } else if ($this->forUrl && isset($conf->_externalModulesPathList[$this->module])) {
            $p = $conf->_externalModulesPathList[$this->module];
        }
        else
            throw new jExceptionSelector('jelix~errors.selector.module.unknown', $this->toString());

        $this->_path = $p.'controllers/'.$this->controller.'.'.$this->request.'.php';
    }
}
