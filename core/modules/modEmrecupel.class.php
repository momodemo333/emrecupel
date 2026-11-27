<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

/**
 * \defgroup emrecupel Module emRecupel
 * \brief    Belgian Recupel fee (WEEE) on proposals, orders and invoices, and
 *           support for the Recupel declaration.
 *
 * \file     core/modules/modEmrecupel.class.php
 * \ingroup  emrecupel
 * \brief    Descriptor of module emRecupel.
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modEmrecupel extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		$this->numero = 491415;
		$this->rights_class = 'emrecupel';
		$this->family = 'financial';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Belgian Recupel fee (WEEE) on proposals, orders and invoices, and declaration report';
		$this->descriptionlong = 'Official Recupel scales 2026 and 2027 (92 codes, FR/NL/EN), several codes per product, display rules for resellers and end users, separate line or "fee included" mention on documents, no fee outside Belgium, declaration report (brought to market, Returnable).';
		$this->editor_name = 'E-dem SRL';
		$this->editor_url = 'https://www.e-dem.com';

		// Keep in sync with EmrecupelMigrations::MODULE_VERSION.
		$this->version = '2.0.1';
		$this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
		$this->picto = 'fa-recycle';
		// Plain-text file holding the latest released version (checked by Dolibarr on demand).
		$this->url_last_version = 'https://raw.githubusercontent.com/momodemo333/emrecupel/main/VERSION';

		$this->module_parts = array(
			'triggers' => 1,
			// 'all' is needed by the automatic migration (printCommonFooter on
			// every page); the other contexts are listed for readability.
			'hooks' => array(
				'all',
				'main',
				'propalcard',
				'ordercard',
				'invoicecard',
				'pdfgeneration',
			),
			'models' => 0,
			'css' => array('/emrecupel/css/emrecupel.css'),
			'js' => array(),
			'menus' => 0,
			'tpl' => 0,
			'moduleforexternal' => 0,
		);

		$this->dirs = array('/emrecupel/temp');
		$this->config_page_url = array('setup.php@emrecupel');

		$this->hidden = false;
		$this->depends = array('modFacture', 'modSociete', 'modProduct');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('emrecupel@emrecupel');

		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(21, 0);

		// [name, type, value, note, visible, entity, deleteonunactive]
		$this->const = array(
			array('EMRECUPEL_PROFILE', 'chaine', 'reseller', 'Company profile: reseller, exporter or producer', 0, 'current', 0),
			array('EMRECUPEL_MODE_RESELLER_PRO', 'chaine', 'included', 'Display for professional appliances sold to resellers', 0, 'current', 0),
			array('EMRECUPEL_MODE_ENDUSER_HOUSEHOLD', 'chaine', 'included', 'Display for household appliances sold to end users', 0, 'current', 0),
			array('EMRECUPEL_MODE_ENDUSER_PRO', 'chaine', 'included', 'Display for professional appliances sold to end users', 0, 'current', 0),
			array('EMRECUPEL_PRICE_BASE_TTC', 'chaine', '1', 'Fee lines priced VAT included (round official amount)', 0, 'current', 0),
			array('EMRECUPEL_ON_PROPAL', 'chaine', '1', 'Recupel fee on proposals', 0, 'current', 0),
			array('EMRECUPEL_ON_ORDER', 'chaine', '1', 'Recupel fee on orders', 0, 'current', 0),
			array('EMRECUPEL_ON_INVOICE', 'chaine', '1', 'Recupel fee on invoices', 0, 'current', 0),
			array('EMRECUPEL_COUNTRY_RULE', 'chaine', 'BE', 'BE = no fee outside Belgium', 0, 'current', 0),
		);

		if (!isset($conf->emrecupel) || !isset($conf->emrecupel->enabled)) {
			$conf->emrecupel = new stdClass();
			$conf->emrecupel->enabled = 0;
		}

		$this->tabs = array(
			'product:+emrecupel:Recupel:emrecupel@emrecupel:$user->hasRight("emrecupel", "read"):/emrecupel/product.php?id=__ID__',
			'propal:+emrecupel:Recupel:emrecupel@emrecupel:$user->hasRight("emrecupel", "read"):/emrecupel/document.php?element=propal&id=__ID__',
			'order:+emrecupel:Recupel:emrecupel@emrecupel:$user->hasRight("emrecupel", "read"):/emrecupel/document.php?element=commande&id=__ID__',
			'invoice:+emrecupel:Recupel:emrecupel@emrecupel:$user->hasRight("emrecupel", "read"):/emrecupel/document.php?element=facture&id=__ID__',
		);

		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		// Permissions: (int)($this->numero . 'NN')
		$this->rights = array();
		$r = 0;
		$this->rights[$r] = array((int) ($this->numero . '01'), 'Read Recupel data (scale, product codes, document analysis)', 'r', 0, 'read', '');
		$r++;
		$this->rights[$r] = array((int) ($this->numero . '02'), 'Classify products and recalculate Recupel fees on documents', 'w', 0, 'write', '');
		$r++;
		$this->rights[$r] = array((int) ($this->numero . '03'), 'Set up emRecupel and import Recupel scales', 'w', 0, 'setup', '');
		$r++;
		$this->rights[$r] = array((int) ($this->numero . '04'), 'Read the Recupel declaration report', 'r', 0, 'declare', '');

		// Menus (left menu of Products and of Billing).
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products',
			'type' => 'left',
			'titre' => 'Recupel',
			'prefix' => img_picto('', 'fa-recycle', 'class="pictofixedwidth"'),
			'mainmenu' => 'products',
			'leftmenu' => 'emrecupel',
			'url' => '/emrecupel/classify.php',
			'langs' => 'emrecupel@emrecupel',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("emrecupel")',
			'perms' => '$user->hasRight("emrecupel", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=emrecupel',
			'type' => 'left',
			'titre' => 'RecupelProductsToClassify',
			'mainmenu' => 'products',
			'leftmenu' => 'emrecupel_classify',
			'url' => '/emrecupel/classify.php',
			'langs' => 'emrecupel@emrecupel',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("emrecupel")',
			'perms' => '$user->hasRight("emrecupel", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=emrecupel',
			'type' => 'left',
			'titre' => 'RecupelImportClassification',
			'mainmenu' => 'products',
			'leftmenu' => 'emrecupel_import',
			'url' => '/emrecupel/import.php',
			'langs' => 'emrecupel@emrecupel',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("emrecupel")',
			'perms' => '$user->hasRight("emrecupel", "write")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=emrecupel',
			'type' => 'left',
			'titre' => 'RecupelScale',
			'mainmenu' => 'products',
			'leftmenu' => 'emrecupel_scale',
			'url' => '/emrecupel/scale.php',
			'langs' => 'emrecupel@emrecupel',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("emrecupel")',
			'perms' => '$user->hasRight("emrecupel", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=emrecupel',
			'type' => 'left',
			'titre' => 'RecupelSwitchAssistant',
			'mainmenu' => 'products',
			'leftmenu' => 'emrecupel_switch',
			'url' => '/emrecupel/switch.php',
			'langs' => 'emrecupel@emrecupel',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("emrecupel")',
			'perms' => '$user->hasRight("emrecupel", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=emrecupel',
			'type' => 'left',
			'titre' => 'Setup',
			'mainmenu' => 'products',
			'leftmenu' => 'emrecupel_setup',
			'url' => '/emrecupel/admin/setup.php',
			'langs' => 'admin',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("emrecupel")',
			'perms' => '$user->hasRight("emrecupel", "setup")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing',
			'type' => 'left',
			'titre' => 'RecupelDeclaration',
			'prefix' => img_picto('', 'fa-recycle', 'class="pictofixedwidth"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'emrecupel_declaration',
			'url' => '/emrecupel/declaration.php',
			'langs' => 'emrecupel@emrecupel',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("emrecupel")',
			'perms' => '$user->hasRight("emrecupel", "declare")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Called when the module is enabled: tables, constants, rights, menus, then
	 * every migration (idempotent).
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/emrecupel/sql/');
		if ($result < 0) {
			return -1;
		}

		dol_include_once('/emrecupel/class/emrecupelmigrations.class.php');
		$res = EmrecupelMigrations::createHelper($this->db)->forceRunAll();
		if (!empty($res['errors'])) {
			dol_syslog('emrecupel: init migrations: ' . implode(' | ', $res['errors']), LOG_ERR);
		}

		return $this->_init(array(), $options);
	}

	/**
	 * Called when the module is disabled. Data, tables and settings are kept.
	 *
	 * @param string $options Options
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
