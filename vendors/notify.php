<?php
/**
 * Send status messages to the OS
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
 * @subpackage    autotest.vendors
 * @since         v 1.0 (22-Jul-2009)
 * @license       http://www.opensource.org/licenses/mit-license.php The MIT License
 */

/**
 * Notify class
 *
 * @uses
 * @package       autotest
 * @subpackage    autotest.vendors
 */
class Notify {

/**
 * method property
 *
 * @var mixed null
 * @access public
 */
	public static $method = null;

/**
 * statuses property
 *
 * @var array
 * @access public
 */
	public static $statuses = array(
		'success' => 'test-pass-icon.png',
		'error'   => 'test-error-icon.png',
		'caution' => 'test-fail-icon.png',
	);

/**
 * notifiers property
 *
 * @var array
 * @access public
 */
	public static $notifiers = array(
		'NotifySend' => 'notify-send',
		'Growlnotify' => array(
			'cmd' => 'growlnotify',
			'statuses' => array(
				'success' => '/Applications/Mail.app/Contents/Resources/status-available.tiff',
				'error'   => '/Applications/Mail.app/Contents/Resources/redlight.tiff',
				'caution' => '/Applications/Mail.app/Contents/Resources/Caution.tiff',
			)
		)
	);

/**
 * allGood method
 *
 * Shortcut for sending all ok message
 *
 * @return void
 * @access public
 */
	static function allGood() {
		Notify::message('All Tests Passed');
	}

/**
 * debug method
 *
 * Dump the classes variables
 *
 * @return void
 * @access public
 */
	static function debug() { //@ignore
		debug(Debugger::trace()); //@ignore
		debug(Notify::$method); //@ignore
		debug(Notify::$statuses); //@ignore
		debug(Notify::$notifiers); //@ignore
	}

/**
 * green method
 *
 * Shortcut for sending a success message
 *
 * @param mixed $params
 * @return void
 * @access public
 */
	static function green($params) {
		list($title, $message) =  Notify::_normalize($params);
		Notify::message($title, $message);
	}

/**
 * red method
 *
 * Shortcut for sending an error message
 *
 * @param mixed $fails
 * @param mixed $params
 * @return void
 * @access public
 */
	static function red($fails, $params) {
		list($title, $message) =  Notify::_normalize($params);
		Notify::message($title, $message, -2, 'error');
	}

/**
 * message method
 *
 * @param mixed $title null
 * @param mixed $message null
 * @param int $priority 0
 * @param string $status 'success'
 * @return void
 * @access public
 */
	static function message($title = null, $message = null, $priority = 0, $status = 'success') {
		if (Notify::$method === false || (Notify::$method === null && !Notify::_detectNotify())) {
			return false;
		}
		$img = '';
		if (!empty(Notify::$statuses[$status])) {
			$img = Notify::$statuses[$status];
			if (!file_exists($img)) {
				$img = dirname(__FILE__) . DS . 'img' . DS . $img;
			}
		}
		if (empty($title)) {
			$title = APP_DIR;
		}
		$message = addslashes($message);
		$title = addslashes($title);
		$method = '_message' . Notify::$method;
		return Notify::$method($img, $title, $message, $priority);
	}

/**
 * normalize method
 *
 * Change the params into a concise, printable, string
 *
 * @param mixed $params
 * @return void
 * @access protected
 */
	static protected function _normalize($params) {
		$summary = $params['totalCount'] . ' files.';
		unset($params['totalCount']);
		unset($params['passed']);
		$counts = array();
		if (isset($params['passedCount'])) {
			$counts[] = $params['passedCount'] . ' ✔';
			unset($params['passedCount']);
		}
		if (isset($params['failedCount'])) {
			$counts[] = $params['failedCount'] . ' ✘';
			unset($params['failedCount']);
		}
		if (isset($params['skippedCount'])) {
			$counts[] = $params['skippedCount'] . ' ❯';
			unset($params['skippedCount']);
		}
		if (isset($params['unknownCount'])) {
			$counts[] = $params['unknownCount'] . ' ?';
			unset($params['unknownCount']);
		}
		if ($counts) {
			$summary .= ' ' . implode($counts, ', ');
		}

		foreach ($params as $key => $value) {
			if ($value === 0) {
				unset($params[$key]);
				continue;
			}
			if (is_array($value)) {
				$params[$key] = '';
				foreach ($value as $k => $v) {
					$params[$key] .= str_replace(APP, '', $k) . " $v\n";
				}
			} else {
				$params[$key] = $value . ' ' . $key;
			}
		}
		return array($summary, implode($params, "\n"));
	}

/**
 * detectNotify method
 *
 * Check if it's windows - if it is set to log (temporary while some equivalent is found)
 *
 * Else, check which of the notify methods are available, first found is used
 *
 * @param bool $reset false
 * @return void
 * @access protected
 */
	static protected function _detectNotify($reset = false) {
		if (!$reset && Notify::$method) {
			return Notify::$method;
		}
		if (DS === '/') {
			foreach(Notify::$notifiers as $method => $params) {
				if (is_string($params)) {
					$params = array('cmd' => $params);
				}
				exec('which ' . $params['cmd'], $_, $return);
				if (!$return) {
					Notify::$method = $method;
					if(!empty($params['statuses'])) {
						Notify::$statuses = $params['statuses'];
					}
					return $method;
				}
			}
		} else {
			Notify::$method = 'Log';
			return 'Log';
		}
		return false;
	}

/**
 * messageDebug method
 *
 * Pseudo send method
 *
 * @param mixed $img
 * @param mixed $title
 * @param mixed $message
 * @param int $priority 0
 * @return void
 * @access protected
 */
	static protected function _messageDebug($img, $title, $message, $priority = 0) {
		debug(func_get_args()); //@ignore
		return func_get_args();
	}

/**
 * messageGrowlnotify method
 *
 * Send a message using growl
 *
 * @param mixed $img
 * @param mixed $title
 * @param mixed $message
 * @param int $priority 0
 * @return void
 * @access protected
 */
	static protected function _messageGrowlnotify($img, $title, $message, $priority = 0) {
		$cmd = 'growlnotify -n "CakePHP Autopilot Shell" -p ' . $priority;
		if ($img) {
			$cmd .= ' --image ' . $img;
		}
		if ($message) {
			$cmd .= " -m \"$message\"";
		} else {
			$cmd .= ' -m ""';
		}
		if ($title) {
			$cmd .= " \"$title\"";
		}
		shell_exec($cmd);
	}

/**
 * messageNotifySend method
 *
 * Send a message using notify-send
 * Set a timeout of 10s so that messages don't end up creating a que that will never finish
 *
 * @param mixed $img
 * @param mixed $title
 * @param mixed $message
 * @param int $priority 0
 * @return void
 * @access protected
 */
	static protected function _messageNotifySend($img, $title, $message, $priority = 0) {
		$cmd = 'notify-send';
		$cmd .= ' -u normal';
		$cmd .= ' -c confirmation';
		if ($img) {
			$cmd .= ' -i ' . $img;
		}
		if ($title) {
			$cmd .= " \"$title\"";
		}
		if ($message) {
			$cmd .= " \"$message\"";
		}
		shell_exec($cmd);
	}
}