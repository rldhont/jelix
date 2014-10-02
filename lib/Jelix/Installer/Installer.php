<?php
/**
* @package     jelix
* @subpackage  installer
* @author      Laurent Jouanneau
* @copyright   2008-2014 Laurent Jouanneau
* @link        http://www.jelix.org
* @licence     GNU Lesser General Public Licence see LICENCE file or http://www.gnu.org/licenses/lgpl.html
*/

require_once(JELIX_LIB_PATH.'installer/jInstallerEntryPoint.class.php');
require(JELIX_LIB_PATH.'installer/jInstallerMessageProvider.class.php');


/**
 * simple text reporter
 */
class textInstallReporter implements jIInstallReporter {
    /**
     * @var string error, notice or warning
     */
    protected $level;

    function __construct($level= 'notice') {
       $this->level = $level;
    }

    function start() {
        if ($this->level == 'notice')
            echo "Installation start..\n";
    }

    /**
     * displays a message
     * @param string $message the message to display
     * @param string $type the type of the message : 'error', 'notice', 'warning', ''
     */
    function message($message, $type='') {
        if (($type == 'error' && $this->level != '')
            || ($type == 'warning' && $this->level != 'notice' && $this->level != '')
            || (($type == 'notice' || $type =='') && $this->level == 'notice'))
        echo ($type != ''?'['.$type.'] ':'').$message."\n";
    }

    /**
     * called when the installation is finished
     * @param array $results an array which contains, for each type of message,
     * the number of messages
     */
    function end($results) {
        if ($this->level == 'notice')
            echo "Installation ended.\n";
    }
}

/**
 * a reporter which reports... nothing
 */
class ghostInstallReporter implements jIInstallReporter {

    function start() {
    }

    /**
     * displays a message
     * @param string $message the message to display
     * @param string $type the type of the message : 'error', 'notice', 'warning', ''
     */
    function message($message, $type='') {
    }

    /**
     * called when the installation is finished
     * @param array $results an array which contains, for each type of message,
     * the number of messages
     */
    function end($results) {
    }
}




/**
 * main class for the installation
 *
 * It load all entry points configurations. Each configurations has its own
 * activated modules. jInstaller then construct a tree dependencies for these
 * activated modules, and launch their installation and the installation
 * of their dependencies.
 * An installation can be an initial installation, or just an upgrade
 * if the module is already installed.
 * @internal The object which drives the installation of a component
 * (module, plugin...) is an object which inherits from jInstallerComponentBase.
 * This object calls load a file from the directory of the component. this
 * file should contain a class which should inherits from jInstallerModule
 * or jInstallerPlugin. this class should implements processes to install
 * the component.
 */
class jInstaller {

    /** value for the installation status of a component: "uninstalled" status */
    const STATUS_UNINSTALLED = 0;

    /** value for the installation status of a component: "installed" status */
    const STATUS_INSTALLED = 1;

    /**
     * value for the access level of a component: "forbidden" level.
     * a module which have this level won't be installed
     */
    const ACCESS_FORBIDDEN = 0;

    /**
     * value for the access level of a component: "private" level.
     * a module which have this level won't be accessible directly
     * from the web, but only from other modules
     */
    const ACCESS_PRIVATE = 1;

    /**
     * value for the access level of a component: "public" level.
     * the module is accessible from the web
     */
    const ACCESS_PUBLIC = 2;

    const FLAG_INSTALL_MODULE = 1;

    const FLAG_UPGRADE_MODULE = 2;

    const FLAG_ALL = 3;

    const FLAG_MIGRATION_11X = 66; // 64 (migration) + 2 (FLAG_UPGRADE_MODULE)

    /**
     *  @var jIniFileModifier it represents the installer.ini.php file.
     */
    public $installerIni = null;

    /**
     * list of entry point and their properties
     * @var jInstallerEntryPoint[]  keys are entry point id.
     */
    protected $entryPoints = array();

    /**
     * list of entry point identifiant (provided by the configuration compiler).
     * identifiant of the entry point is the path+filename of the entry point
     * without the php extension
     * @var array   key=entry point name, value=url id
     */
    protected $epId = array();

    /**
     * list of all modules of the application
     * @var \Jelix\Installer\ModuleInstallLauncher[]   key=path of the module
     */
    protected $allModules = array();

    /**
     * the object responsible of the results output
     * @var jIInstallReporter
     */
    public $reporter;

    /**
     * @var JInstallerMessageProvider
     */
    public $messages;

    /** @var integer the number of errors appeared during the installation */
    public $nbError = 0;

    /** @var integer the number of ok messages appeared during the installation */
    public $nbOk = 0;

    /** @var integer the number of warnings appeared during the installation */
    public $nbWarning = 0;

    /** @var integer the number of notices appeared during the installation */
    public $nbNotice = 0;

    /**
     * the defaultconfig.ini.php content
     * @var jIniFileModifier
     */
    public $mainConfig;

    /**
     * initialize the installation
     *
     * it reads configurations files of all entry points, and prepare object for
     * each module, needed to install/upgrade modules.
     * @param jIInstallReporter $reporter  object which is responsible to process messages (display, storage or other..)
     * @param string $lang  the language code for messages
     */
    function __construct ($reporter, $lang='') {
        $this->reporter = $reporter;
        $this->messages = new jInstallerMessageProvider($lang);
        $this->mainConfig = new jIniFileModifier(jApp::mainConfigFile());
        $this->installerIni = $this->getInstallerIni();
        $appInfos = new \Jelix\Core\Infos\AppInfos();
        $this->readEntryPointsData($appInfos);
        $this->installerIni->save();
    }

    /**
     * @internal mainly for tests
     * @return jIniFileModifier the modifier for the installer.ini.php file
     */
    protected function getInstallerIni() {
        if (!file_exists(jApp::configPath('installer.ini.php')))
            if (false === @file_put_contents(jApp::configPath('installer.ini.php'), ";<?php die(''); ?>
; for security reasons , don't remove or modify the first line
; don't modify this file if you don't know what you do. it is generated automatically by jInstaller

"))
                throw new Exception('impossible to create var/config/installer.ini.php');
        return new jIniFileModifier(jApp::configPath('installer.ini.php'));
    }

    /**
     * read the list of entrypoint from the project.xml file
     * and read all modules data used by each entry point
     * @param \Jelix\Core\Infos\AppInfos $appInfos
     */
    protected function readEntryPointsData(\Jelix\Core\Infos\AppInfos $appInfos) {

        $configFileList = array();

        // read all entry points data
        foreach ($appInfos->entrypoints as $file => $entrypoint) {

            $configFile = $entrypoint['config'];
            if (isset($entrypoint['type'])) {
                $type = $entrypoint['type'];
            }
            else
                $type = "classic";

            // ignore entry point which have the same config file of an other one
            // FIXME: what about installer.ini ?
            if (isset($configFileList[$configFile]))
                continue;

            $configFileList[$configFile] = true;

            // we create an object corresponding to the entry point
            $ep = $this->getEntryPointObject($configFile, $file, $type);
            $epId = $ep->getEpId();

            $this->epId[$file] = $epId;
            $this->entryPoints[$epId] = $ep;

            $installerIni = $this->installerIni;
            $allModules = &$this->allModules;
            $that = $this;

            $ep->createInstallLaunchers(function ($moduleStatus, $moduleInfos) use($that, $epId){
               $name = $moduleInfos->name;
               $path = $moduleInfos->getPath();
               $that->installerIni->setValue($name.'.installed', $moduleStatus->isInstalled, $epId);
               $that->installerIni->setValue($name.'.version', $moduleStatus->version, $epId);

               if (!isset($that->allModules[$path])) {
                   $that->allModules[$path] = new \Jelix\Installer\ModuleInstallLauncher($moduleInfos, $that);
               }

               return $that->allModules[$path];
            });
        }
    }

    /**
     * @internal for tests
     * @return jInstallerEntryPoint
     */
    protected function getEntryPointObject($configFile, $file, $type) {
        return new jInstallerEntryPoint($this->mainConfig, $configFile, $file, $type);
    }

    /**
     * @param string $epId an entry point id
     * @return jInstallerEntryPoint the corresponding entry point object
     */
    public function getEntryPoint($epId) {
        return $this->entryPoints[$epId];
    }

    /**
     * change the module version in readed informations, to simulate an update
     * when we call installApplication or an other method.
     * internal use !!
     * @param string $moduleName the name of the module
     * @param string $version the new version
     */
    public function forceModuleVersion($moduleName, $version) {
        foreach($this->entryPoints as $epId=>$ep) {
            $launcher = $ep->getLauncher($moduleName);
            if ($launcher) {
               $launcher->setInstalledVersion($epId, $version);
            }
        }
    }

    /**
     * set parameters for the installer of a module
     * @param string $moduleName the name of the module
     * @param array $parameters  parameters
     * @param string $entrypoint  the entry point name for which parameters will be applied when installing the module.
     *                     if null, parameters are valid for all entry points
     */
    public function setModuleParameters($moduleName, $parameters, $entrypoint = null) {
        if ($entrypoint !== null) {
            if (!isset($this->epId[$entrypoint])) {
                throw new Exception("Unknown entrypoint name");
            }
            $epId = $this->epId[$entrypoint];
            if (!isset($this->entryPoints[$epId])) {
                throw new Exception("Unknown entrypoint name");
            }

            $launcher = $this->entryPoints[$epId]->getLauncher($moduleName);
            if ($launcher) {
               $launcher->setInstallParameters($epId, $parameters);
            }
        }
        else {
            foreach($this->entryPoints as $epId=>$ep) {
               $launcher = $ep->getLauncher($moduleName);
               if ($launcher) {
                  $launcher->setInstallParameters($epId, $parameters);
               }
            }
        }
    }

    /**
     * install and upgrade if needed, all modules for each
     * entry point. Only modules which have an access property > 0
     * are installed. Errors appeared during the installation are passed
     * to the reporter.
     * @param int $flags flags indicating if we should install, and/or upgrade
     *                   modules or only modify config files. internal use.
     *                   see FLAG_* constants
     * @return boolean true if succeed, false if there are some errors
     */
    public function installApplication($flags = false) {

        if ($flags === false)
            $flags = self::FLAG_ALL;

        $this->startMessage();
        $result = true;

        foreach($this->entryPoints as $epId=>$ep) {
            $modules = array();
            foreach($ep->getLaunchers() as $name => $module) {
                $access = $module->getAccessLevel($epId);
                if ($access != 1 && $access != 2)
                    continue;
                $modules[$name] = $module;
            }
            $result = $result & $this->_installModules($modules, $ep, true, $flags);
            if (!$result)
                break;
        }

        $this->installerIni->save();
        $this->endMessage();
        return $result;
    }

    /**
     * install and upgrade if needed, all modules for the given
     * entry point. Only modules which have an access property > 0
     * are installed. Errors appeared during the installation are passed
     * to the reporter.
     * @param string $entrypoint  the entrypoint name as it appears in project.xml
     * @return boolean true if succeed, false if there are some errors
     */
    public function installEntryPoint($entrypoint) {

        $this->startMessage();

        if (!isset($this->epId[$entrypoint])) {
            throw new Exception("unknown entry point");
        }

        $epId = $this->epId[$entrypoint];
        $ep = $this->entrypoints[$epId];

        $modules = array();
        foreach($ep->getLaunchers() as $name => $module) {
            $access = $module->getAccessLevel($epId);
            if ($access != 1 && $access != 2)
                continue;
            $modules[$name] = $module;
        }
        $result = $this->_installModules($modules, $ep, true);

        $this->installerIni->save();
        $this->endMessage();
        return $result;
    }

    /**
     * install given modules even if they don't have an access property > 0
     * @param array $modulesList array of module names
     * @param string $entrypoint  the entrypoint name as it appears in project.xml
     *               or null if modules should be installed for all entry points
     * @return boolean true if the installation is ok
     */
    public function installModules($modulesList, $entrypoint = null) {

        $this->startMessage();
        $entryPointList = array();
        if ($entrypoint == null) {
            $entryPointList = $this->entryPoints;
        }
        else if (isset($this->epId[$entrypoint])) {
            $entryPointList = array($this->entryPoints[$this->epId[$entrypoint]]);
        }
        else {
            throw new Exception("unknown entry point");
        }

        foreach ($entryPointList as $epId=>$ep) {

            $allModules = &$ep->getLaunchers();

            $modules = array();
            // always install jelix
            array_unshift($modulesList, 'jelix');
            foreach ($modulesList as $name) {
                if (!isset($allModules[$name])) {
                    $this->error('module.unknown', $name);
                }
                else
                    $modules[] = $allModules[$name];
            }

            $result = $this->_installModules($modules, $ep, false);
            if (!$result)
                break;
            $this->installerIni->save();
        }

        $this->endMessage();
        return $result;
    }

    /**
     * core of the installation
     * @param \Jelix\Installer\ModuleInstallLauncher[] $modules
     * @param jInstallerEntryPoint $ep  the entrypoint
     * @param boolean $installWholeApp true if the installation is done during app installation
     * @param integer $flags to know what to do
     * @return boolean true if the installation is ok
     */
    protected function _installModules(&$modules, $ep, $installWholeApp, $flags=3) {

        $epId = $ep->getEpId();
        $this->notice('install.entrypoint.start', $epId);
        jApp::setConfig($ep->config);

        if ($ep->config->disableInstallers)
            $this->notice('install.entrypoint.installers.disabled');

        // first, check dependencies of the component, to have the list of component
        // we should really install.
        $orderedModules = $ep->getOrderedDependencies($modules, $this);

        if ($orderedModules === false) {
            $this->error('install.bad.dependencies');
            $this->ok('install.entrypoint.bad.end', $epId);
            return false;
        }

        $this->ok('install.dependencies.ok');

        // ----------- pre install
        // put also available installers into $componentsToInstall for
        // the next step
        $componentsToInstall = array();
        $result = true;
        foreach($orderedModules as $item) {
            list($component, $toInstall) = $item;
            try {
                if ($flags == self::FLAG_MIGRATION_11X) {
                    $this->installerIni->setValue($component->getName().'.installed',
                                                   1, $epId);
                    $this->installerIni->setValue($component->getName().'.version',
                                                   $component->getSourceVersion(), $epId);

                    if ($ep->config->disableInstallers) {
                        $upgraders = array();
                    }
                    else {
                        $upgraders = $component->getUpgraders($ep);
                        foreach($upgraders as $upgrader) {
                            $upgrader->preInstall();
                        }
                    }

                    $componentsToInstall[] = array($upgraders, $component, false);

                }
                else if ($toInstall) {
                    if ($ep->config->disableInstallers)
                        $installer = null;
                    else
                        $installer = $component->getInstaller($ep, $installWholeApp);
                    $componentsToInstall[] = array($installer, $component, $toInstall);
                    if ($flags & self::FLAG_INSTALL_MODULE && $installer)
                        $installer->preInstall();
                }
                else {
                    if ($ep->config->disableInstallers) {
                        $upgraders = array();
                    }
                    else {
                        $upgraders = $component->getUpgraders($ep);
                    }

                    if ($flags & self::FLAG_UPGRADE_MODULE && count($upgraders)) {
                        foreach($upgraders as $upgrader) {
                            $upgrader->preInstall();
                        }
                    }
                    $componentsToInstall[] = array($upgraders, $component, $toInstall);
                }
            } catch (jInstallerException $e) {
                $result = false;
                $this->error ($e->getLocaleKey(), $e->getLocaleParameters());
            } catch (Exception $e) {
                $result = false;
                $this->error ('install.module.error', array($component->getName(), $e->getMessage()));
            }
        }

        if (!$result) {
            $this->warning('install.entrypoint.bad.end', $epId);
            return false;
        }

        $installedModules = array();

        // -----  installation process
        try {
            foreach($componentsToInstall as $item) {
                list($installer, $component, $toInstall) = $item;
                if ($toInstall) {
                    if ($installer && ($flags & self::FLAG_INSTALL_MODULE))
                        $installer->install();
                    $this->installerIni->setValue($component->getName().'.installed',
                                                   1, $epId);
                    $this->installerIni->setValue($component->getName().'.version',
                                                   $component->getSourceVersion(), $epId);
                    $this->installerIni->setValue($component->getName().'.version.date',
                                                   $component->getSourceDate(), $epId);
                    $this->installerIni->setValue($component->getName().'.firstversion',
                                                   $component->getSourceVersion(), $epId);
                    $this->installerIni->setValue($component->getName().'.firstversion.date',
                                                   $component->getSourceDate(), $epId);
                    $this->ok('install.module.installed', $component->getName());
                    $installedModules[] = array($installer, $component, true);
                }
                else {
                    $lastversion = '';
                    foreach($installer as $upgrader) {
                        if ($flags & self::FLAG_UPGRADE_MODULE)
                            $upgrader->install();
                        // we set the version of the upgrade, so if an error occurs in
                        // the next upgrader, we won't have to re-run this current upgrader
                        // during a future update
                        $this->installerIni->setValue($component->getName().'.version',
                                                      $upgrader->version, $epId);
                        $this->installerIni->setValue($component->getName().'.version.date',
                                                      $upgrader->date, $epId);
                        $this->ok('install.module.upgraded',
                                  array($component->getName(), $upgrader->version));
                        $lastversion = $upgrader->version;
                    }
                    // we set the version to the component version, because the version
                    // of the last upgrader could not correspond to the component version.
                    if ($lastversion != $component->getSourceVersion()) {
                        $this->installerIni->setValue($component->getName().'.version',
                                                      $component->getSourceVersion(), $epId);
                        $this->installerIni->setValue($component->getName().'.version.date',
                                                      $component->getSourceDate(), $epId);
                        $this->ok('install.module.upgraded',
                                  array($component->getName(), $component->getSourceVersion()));
                    }
                    $installedModules[] = array($installer, $component, false);
                }
                // we always save the configuration, so it invalidates the cache
                $ep->configIni->save();
                // we re-load configuration file for each module because
                // previous module installer could have modify it.
                $compiler =  new \Jelix\Core\Config\Compiler($ep->configFile,
                                                             $ep->scriptName,
                                                             $ep->isCliScript);
                $ep->config = $compiler->read(true);

                jApp::setConfig($ep->config);
            }
        } catch (jInstallerException $e) {
            $result = false;
            $this->error ($e->getLocaleKey(), $e->getLocaleParameters());
        } catch (Exception $e) {
            $result = false;
            $this->error ('install.module.error', array($component->getName(), $e->getMessage()));
        }

        if (!$result) {
            $this->warning('install.entrypoint.bad.end', $epId);
            return false;
        }

        // post install
        foreach($installedModules as $item) {
            try {
                list($installer, $component, $toInstall) = $item;

                if ($toInstall) {
                    if ($installer && ($flags & self::FLAG_INSTALL_MODULE)) {
                        $installer->postInstall();
                        $component->installFinished($ep);
                    }
                }
                else if ($flags & self::FLAG_UPGRADE_MODULE){
                    foreach($installer as $upgrader) {
                        $upgrader->postInstall();
                        $component->upgradeFinished($ep, $upgrader);
                    }
                }

                // we always save the configuration, so it invalidates the cache
                $ep->configIni->save();
                // we re-load configuration file for each module because
                // previous module installer could have modify it.
                $compiler =  new \Jelix\Core\Config\Compiler($ep->configFile,
                                                             $ep->scriptName,
                                                             $ep->isCliScript);
                $ep->config = $compiler->read(true);
                jApp::setConfig($ep->config);
            } catch (jInstallerException $e) {
                $result = false;
                $this->error ($e->getLocaleKey(), $e->getLocaleParameters());
            } catch (Exception $e) {
                $result = false;
                $this->error ('install.module.error', array($component->getName(), $e->getMessage()));
            }
        }

        $this->ok('install.entrypoint.end', $epId);

        return $result;
    }

    protected function startMessage () {
        $this->nbError = 0;
        $this->nbOk = 0;
        $this->nbWarning = 0;
        $this->nbNotice = 0;
        $this->reporter->start();
    }

    protected function endMessage() {
        $this->reporter->end(array('error'=>$this->nbError, 'warning'=>$this->nbWarning, 'ok'=>$this->nbOk,'notice'=>$this->nbNotice));
    }

    protected function error($msg, $params=null, $fullString=false){
        if($this->reporter) {
            if (!$fullString)
                $msg = $this->messages->get($msg,$params);
            $this->reporter->message($msg, 'error');
        }
        $this->nbError ++;
    }

    protected function ok($msg, $params=null, $fullString=false){
        if($this->reporter) {
            if (!$fullString)
                $msg = $this->messages->get($msg,$params);
            $this->reporter->message($msg, '');
        }
        $this->nbOk ++;
    }

    protected function warning($msg, $params=null, $fullString=false){
        if($this->reporter) {
            if (!$fullString)
                $msg = $this->messages->get($msg,$params);
            $this->reporter->message($msg, 'warning');
        }
        $this->nbWarning ++;
    }

    protected function notice($msg, $params=null, $fullString=false){
        if($this->reporter) {
            if (!$fullString)
                $msg = $this->messages->get($msg,$params);
            $this->reporter->message($msg, 'notice');
        }
        $this->nbNotice ++;
    }

}
