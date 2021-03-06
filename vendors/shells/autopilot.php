<?php
/**
 * A shell for monitoring a folder and automatically checking if changes pass test cases/sanity/syntax checks
 *
 * PHP version 5
 *
 * Copyright (c) 2009, Rodrigo Moyle
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @filesource
 * @copyright     Copyright (c) 2009, Rodrigo Moyle
 * @link          blog.rodrigorm.com.br
 * @package       autotest
 * @subpackage    autotest.vendors.shells
 * @since         v 1.0 (22-Jul-2009)
 * @license       http://www.opensource.org/licenses/mit-license.php The MIT License
 */

App::import('Vendor', 'Autotest.Notify');

/**
 * Hooks class
 *
 * @uses
 * @package       autotest
 * @subpackage    autotest.vendors.shells
 */
class Hooks {
	const all_good    = 'all_good';
	const initialize  = 'initialize';
	const interrupt   = 'interrupt';
	const quit        = 'quit';
	const ran_command = 'ran_command';
	const reset       = 'reset';
	const run_command = 'run_command';
	const waiting     = 'waiting';
	const green       = 'green';
	const red         = 'red';
}

/**
 * AutopilotShell class
 *
 * @uses          Shell
 * @package       autotest
 * @subpackage    autotest.vendors.shells
 */
class AutopilotShell extends Shell {

/**
 * lastMTime property
 *
 * @var mixed null
 * @access public
 */
	public $lastMTime = null;

/**
 * filesToTest property
 *
 * @var array
 * @access public
 */
	public $filesToTest = array();

/**
 * results property
 *
 * @var mixed null
 * @access public
 */
	public $results = null;

/**
 * Enter Description Here
 */
	static $hooks = array();

/**
 * fails property
 *
 * @var array
 * @access public
 */
	public $fails = array();

/**
 * settings property
 *
 * @var array
 * @access public
 */
	public $settings = array(
		'interval' => 0.05, // 0.05 minutes = every 3s
		'debug' => false,
		'excludePattern' => '@(index\.php|[\\\/](config|locale|tmp|webroot)[\\\/])@',
		'notify' => null,
		'checkAllOnStart' => true,
		'pidDir' => '/var/run/autopilot/',
		'mode' => null
	);

/**
 * initialize method
 *
 * check for the existance of a config/autopilot.php file - if found merge
 * $config with settings
 *
 * Of the autopilot mode has ben set to false - do nothing further
 *
 * @return void
 * @access public
 */
	function initialize() {
		if (file_exists('config' . DS . 'autopilot.php')) {
			include('config' . DS . 'autopilot.php');
			if (!empty($config)) {
				$this->settings = am($this->settings, $config);
			}
		} elseif (file_exists(APP . 'config' . DS . 'autopilot.php')) {
			include(APP . 'config' . DS . 'autopilot.php');
			if (!empty($config)) {
				$this->settings = am($this->settings, $config);
			}
		}
		if ($this->settings['mode'] === false) {
			$this->out('Autopilot has been disabled');
			$this->_stop();
		}
	}

/**
 * main method
 *
 * Check for the existance of a tmp/autopilot_running file. If it exists - and is less than an hour
 * old do not do anything to prevent the possibility of running autotest several times in parallel
 * on the same files. If the file is more than an hour old delete it and continue.
 * Delete the tmp/autopilot_stop file if it exists and being autopilot test/checking process.
 *
 * @return void
 * @access public
 */
	function main() {
		if (!$this->_registerPid()) {
			$this->out('Unable to register Pid');
			$this->_stop();
		}
		if (file_exists($this->params['working'] . DS . '.autotest')) {
			include($this->params['working'] . DS . '.autotest');
		}
		if (!empty($this->params['notify'])) {
			$this->settings['notify'] = $this->params['notify'];
		}
		if (!empty($this->params['mode'])) {
			$this->settings['mode'] = $this->params['mode'];
		}
		$suffix = '';
		if (!empty($this->settings['mode'])) {
			$suffix = ' (' . $this->settings['mode'] . ' mode)';
		}

		Notify::$method = $this->settings['notify'];
		$this->addHooks();
		Notify::message('Autopilot Starting', 'in ' . APP_DIR . $suffix, 0, false);
		$this->buildPaths();
		$this->run();
	}

/**
 * addHooks method
 *
 * @return void
 * @access public
 */
	function addHooks() {
		AutopilotShell::addHook(Hooks::green, array('Notify', 'green'));
		AutopilotShell::addHook(Hooks::red, array('Notify', 'red'));
		AutopilotShell::addHook(Hooks::all_good, array('Notify', 'allGood'));
	}

/**
 * buildPaths method
 *
 * @return void
 * @access public
 */
	function buildPaths() {
		$this->paths = array('console' => array_pop(Configure::corePaths('cake')) . 'console' . DS . 'cake');
	}

/**
 * run method
 *
 * Touch the tmp/autopilot_runnint file to prevent multiple instances of autopilot being launched
 * for the same project
 * Check for the existance of a tmp/autopilot_stop file to cease processing
 *
 * @return void
 * @access public
 */
	function run() {
		$this->_hook(Hooks::initialize);
		do {
			touch(TMP . 'autopilot_running');
			$this->_getToGreen();
			$this->_rerunAllTests();
			$this->_waitForChanges();
		} while (!file_exists(TMP . 'autopilot_stop'));
		$this->_hook(Hooks::quit);
		unlink(TMP . 'autopilot_running');
	}

/**
 * stop method
 *
 * Create a tmp/autopilot_stop file - which is checked for during execution to halt processing
 *
 * @return void
 * @access public
 */
	function stop() {
		touch(TMP . 'autopilot_stop');
	}

/**
 * getToGreen method
 *
 * @return void
 * @access protected
 */
	function _getToGreen() {
		do {
			$this->_runTests();
			if (!$this->_allGood()) {
				$this->_waitForChanges();
			}
		} while (!$this->_allGood() && !file_exists(TMP . 'autopilot_stop'));
	}

/**
 * runTests method
 *
 * @return void
 * @access protected
 */
	function _runTests() {
		$this->_hook(Hooks::run_command);
		if (!$this->filesToTest) {
			$this->filesToTest = $this->_findFiles();
			if (!$this->filesToTest) {
				return;
			}
		}

		$this->results = array(
			'passed' => array(),
			'skipped' => array(),
			'failed' => array(),
			'unknown' => array(),
		);
		foreach($this->filesToTest as $i => $file) {
			$result = $this->_runTest($file);
			$shortFile = str_replace($this->params['working'] . DS, '', $file);
			if (strpos($result, '✔')) {
				$this->results['passed'][$shortFile] = '✔';//$result;
				unset($this->fails[$file]);
			} elseif (strpos($result, '❯')) {
				$this->results['skipped'][$shortFile] = '❯';//$result;
				unset($this->fails[$file]);
			} elseif (strpos($result, '✘')) {
				$this->results['failed'][$shortFile] = '✘';//$result;
				$this->fails[$file] = $file;
			} else {
				$this->results['unknown'][$shortFile] = '?';//$result;
			}
			$this->out($result);
		}
		$this->_hook(Hooks::ran_command);

		$total = -count($this->results['skipped']);
		foreach(array('passed', 'skipped', 'failed', 'unknown') as $type) {
			if (empty($this->results[$type])) {
				$this->results[$type . 'Count'] = 0;
				continue;
			}
			$total += count($this->results[$type]);
			$this->results[$type . 'Count'] = count($this->results[$type]);
		}
		$this->results['totalCount'] = $total;

		if (empty($this->results['failed']) && empty($this->results['unknown'])) {
			$this->_hook(Hooks::green, array_filter($this->results));
		} else {
			$this->_hook(Hooks::red, (int)$this->results['failedCount'], array_filter($this->results));
		}
	}

/**
 * runTest method
 *
 * @param mixed $file
 * @return void
 * @access protected
 */
	function _runTest($file) {
		$cmd = $this->paths['console'] . ' -app '. $this->params['working'] . ' repo checkFile ' . $file . ' -q -noclear';
		if ($this->settings['mode']) {
			$cmd .= ' -mode ' . $this->settings['mode'];
		}
		$out = exec($cmd, $_, $return);
		return implode($_, "\n");
	}

/**
 * waitForChanges method
 *
 * @return void
 * @access protected
 */
	function _waitForChanges() {
		$this->_hook(Hooks::waiting);
		do {
			sleep($this->settings['interval'] * 60);
			$changedFiles = $this->_findFiles();
			$files = array_unique(am($changedFiles, array_values((array)$this->fails)));
			$this->filesToTest = $files;
		} while (!$changedFiles && !file_exists(TMP . 'autopilot_stop'));
	}

/**
 * allGood method
 *
 * @return void
 * @access protected
 */
	function _allGood() {
		return empty($this->fails);
	}

/**
 * rerunAllTests method
 *
 * @return void
 * @access protected
 */
	function _rerunAllTests() {
		$this->_reset();
		$this->_runTests();
		if ($this->_allGood()) {
			$this->out('All tests passed.');
			$this->_hook(Hooks::all_good);
		}
	}

/**
 * reset method
 *
 * @return void
 * @access protected
 */
	function _reset() {
		$this->fails = null;
		$this->filesToTest = null;
		$this->lastMTime = null;

		$this->_hook(Hooks::reset);
	}

/**
 * debug method
 *
 * @param mixed $message
 * @return void
 * @access public
 */
	function debug($message) { // @ignore
		if (!$this->settings['debug']) {
			return;
		}
		$this->out($message);
	}

/**
 * addHook method
 *
 * @param mixed $hook
 * @param mixed $callback
 * @return void
 * @access public
 */
	static function addHook($hook, $callback) {
		if (!is_callable($callback)) {
			return false;
		}

		if (empty(AutopilotShell::$hooks[$hook])) {
			AutopilotShell::$hooks[$hook] = array();
		}
		AutopilotShell::$hooks[$hook][] = $callback;

		return true;
	}

/**
 * hook method
 *
 * @param mixed $hook
 * @return void
 * @access protected
 */
	function _hook($hook) {
		$params = func_get_args();
		array_shift($params);

		if (empty(AutopilotShell::$hooks[$hook])) {
			return false;
		}
		foreach (AutopilotShell::$hooks[$hook] as $callback) {
			call_user_func_array($callback, $params);
		}
	}

/**
 * findFiles method
 *
 * @param mixed $dir null
 * @return void
 * @access protected
 */
	function _findFiles($dir = null) {
		if (!$dir) {
			$dir = $this->params['working'];
		}

		if (!$this->lastMTime && !$this->settings['checkAllOnStart']) {
			$this->lastMTime = time();
			return array();
		}
		if (DS === '\\') {
			App::import('Core', 'Folder');
			if (empty($this->Folder)) {
				$this->Folder = new Folder($dir);
			}
			$files = $this->Folder->findRecursive('.*\.php$');
			if ($this->lastMTime) {
				$lastMTime = 0;
				foreach ($files as $key => $file) {
					$time = filemtime($file);
					if (!empty($this->lastMTime) && $time <= $this->lastMTime) {
						unset ($files[$key]);
						continue;
					}
					if ($time > $lastMTime) {
						$lastMTime = $time;
					}
				}
				if ($lastMTime > $this->lastMTime) {
					$this->lastMTime = $time;
				}
			} elseif (!$this->settings['checkAllOnStart']) {
				$files = array();
			}
		} else {
			$suffix = '';
			if ($this->lastMTime) {
				$suffix = $this->_findSuffix();
			}
			$cmd = 'find ' . $dir . ' ! -ipath "*.svn*" \
			! -ipath "*.git*" ! -iname "*.git*" ! -ipath "*/tmp/*" ! -ipath "*webroot*" \
			! -ipath "*Zend*" ! -ipath "*simpletest*" ! -ipath "*firephp*" \
			! -iname "*jquery*" ! -ipath "*Text*" -name "*.php" -type f' . $suffix;
			exec($cmd, $files);
			$this->lastMTime = time();
		}
		$files = array_unique($files);
		sort($files);
		foreach($files as $key => $file) {
			if (!empty($this->settings['excludePattern']) && preg_match($this->settings['excludePattern'], $file)) {
				unset($files[$key]);
			}
		}
		return array_values($files);
	}

	function _findSuffix() {
		static $system = null;
		if (is_null($system)) {
			$system = exec('uname -s');
		}

		if ($system == 'Darwin') {
			$tmpfile = TMP . 'autopilot_find_newer';
			touch($tmpfile, $this->lastMTime);
			return ' -cnewer ' . $tmpfile;
		} else {
			$sinceLast = time() - $this->lastMTime;
			return ' -mmin ' . $sinceLast / 60;
		}
	}

/**
 * registerPid method
 *
 * @return void
 * @access protected
 */
	function _registerPid() {
		if (!is_dir($this->settings['pidDir'])) {
			new Folder($this->settings['pidDir'], true);
			if (!is_dir($this->settings['pidDir'])) {
				trigger_error('unable to create pid directory ' . $this->settings['pidDir']);
				return false;
			}
		}
		$pidFile = $this->settings['pidDir'] . Inflector::slug($this->params['working']) . '.pid';
		if (file_exists($pidFile)) {
			$this->out('Existing PID file found: ' . $pidFile);
			$pid = trim(file_get_contents($pidFile));
			$stillRunning = posix_kill($pid, 0);
			if ($stillRunning) {
				$this->out("Autopilot already running (PID $pid)");
				return false;
			} else {
				$this->out("Process $pid nolonger running, ignoring existing PID file");
			}
		}
		$pid = posix_getpid();
		$File = new File($pidFile);
		$File->write($pid);
		return true;
	}
}