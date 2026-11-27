<?php
/* Bootstrap des tests unitaires emRecupel v2.
 *
 * Charge uniquement les classes à logique pure (politique de cotisation,
 * parseurs CSV) avec des stubs Dolibarr minimaux : aucune base, aucun
 * main.inc.php.
 */

define('EMRECUPEL_MODULE_DIR', dirname(__DIR__));

if (!defined('LOG_ERR')) {
	define('LOG_ERR', 3);
}
if (!defined('LOG_WARNING')) {
	define('LOG_WARNING', 4);
}
if (!defined('LOG_INFO')) {
	define('LOG_INFO', 6);
}
if (!defined('LOG_DEBUG')) {
	define('LOG_DEBUG', 7);
}
if (!defined('MAIN_DB_PREFIX')) {
	define('MAIN_DB_PREFIX', 'llx_');
}

if (!function_exists('dol_include_once')) {
	/**
	 * Stub : '/emrecupel/class/x.php' => <module>/class/x.php.
	 *
	 * @param string $relpath Chemin relatif Dolibarr
	 * @return bool
	 */
	function dol_include_once($relpath)
	{
		$relpath = ltrim((string) $relpath, '/');
		if (strpos($relpath, 'emrecupel/') !== 0) {
			return false;
		}
		$file = EMRECUPEL_MODULE_DIR . '/' . substr($relpath, strlen('emrecupel/'));
		if (!is_file($file)) {
			return false;
		}
		return (bool) include_once $file;
	}
}
if (!function_exists('getDolGlobalString')) {
	/**
	 * @param string $name    Constante
	 * @param string $default Défaut
	 * @return string
	 */
	function getDolGlobalString($name, $default = '')
	{
		return (string) ($GLOBALS['__test_dol_consts'][$name] ?? $default);
	}
}
if (!function_exists('getDolGlobalInt')) {
	/**
	 * @param string $name    Constante
	 * @param int    $default Défaut
	 * @return int
	 */
	function getDolGlobalInt($name, $default = 0)
	{
		return (int) ($GLOBALS['__test_dol_consts'][$name] ?? $default);
	}
}
if (!function_exists('dol_syslog')) {
	/**
	 * @param string $message Message
	 * @param int    $level   Niveau
	 * @return void
	 */
	function dol_syslog($message, $level = LOG_INFO)
	{
	}
}
if (!function_exists('price2num')) {
	/**
	 * Stub simplifié : virgule décimale acceptée.
	 *
	 * @param mixed  $amount Montant
	 * @param string $rounding Mode (ignoré sauf 'MU' : 8 décimales)
	 * @return string
	 */
	function price2num($amount, $rounding = '')
	{
		$v = (float) str_replace(array(' ', ','), array('', '.'), (string) $amount);
		return (string) ($rounding === 'MU' ? round($v, 8) : $v);
	}
}

require_once EMRECUPEL_MODULE_DIR . '/class/emrecupelfeepolicy.class.php';
require_once EMRECUPEL_MODULE_DIR . '/class/emrecupelcatalog.class.php';
require_once EMRECUPEL_MODULE_DIR . '/class/emrecupelproduct.class.php';
