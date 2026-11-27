<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/triggers/interface_99_modEmrecupel_EmrecupelTriggers.class.php
 * \ingroup emrecupel
 * \brief   Keeps Recupel fee lines in sync with product lines of proposals,
 *          orders and invoices, and records validated invoices for the
 *          declaration.
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

class InterfaceEmrecupelTriggers extends DolibarrTriggers
{
	/** @var array<string,array{0:string,1:string}> trigger => [kind, element] */
	private static $map = array(
		'LINEPROPAL_INSERT' => array('line_insert', 'propal'),
		'LINEORDER_INSERT' => array('line_insert', 'commande'),
		'LINEBILL_INSERT' => array('line_insert', 'facture'),
		'LINEPROPAL_MODIFY' => array('line_modify', 'propal'),
		'LINEORDER_MODIFY' => array('line_modify', 'commande'),
		'LINEBILL_MODIFY' => array('line_modify', 'facture'),
		'LINEPROPAL_DELETE' => array('line_delete', 'propal'),
		'LINEORDER_DELETE' => array('line_delete', 'commande'),
		'LINEBILL_DELETE' => array('line_delete', 'facture'),
		'PROPAL_CREATE' => array('doc_create', 'propal'),
		'ORDER_CREATE' => array('doc_create', 'commande'),
		'BILL_CREATE' => array('doc_create', 'facture'),
		'PROPAL_MODIFY' => array('doc_modify', 'propal'),
		'ORDER_MODIFY' => array('doc_modify', 'commande'),
		'BILL_MODIFY' => array('doc_modify', 'facture'),
		'PROPAL_ADD_CONTACT' => array('doc_modify', 'propal'),
		'ORDER_ADD_CONTACT' => array('doc_modify', 'commande'),
		'BILL_ADD_CONTACT' => array('doc_modify', 'facture'),
		'FACTURE_ADD_CONTACT' => array('doc_modify', 'facture'),
		'COMMANDE_ADD_CONTACT' => array('doc_modify', 'commande'),
		'PROPAL_DELETE_CONTACT' => array('doc_modify', 'propal'),
		'ORDER_DELETE_CONTACT' => array('doc_modify', 'commande'),
		'BILL_DELETE_CONTACT' => array('doc_modify', 'facture'),
		'FACTURE_DELETE_CONTACT' => array('doc_modify', 'facture'),
		'COMMANDE_DELETE_CONTACT' => array('doc_modify', 'commande'),
		'BILL_VALIDATE' => array('ledger_write', 'facture'),
		'BILL_UNVALIDATE' => array('ledger_delete', 'facture'),
		'BILL_DELETE' => array('ledger_delete', 'facture'),
		'PRODUCT_DELETE' => array('product_delete', 'product'),
	);

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'financial';
		$this->description = 'emRecupel: Recupel fee lines on proposals, orders and invoices';
		$this->version = 'dolibarr';
		$this->picto = 'fa-recycle';
	}

	/**
	 * @param string       $action Trigger code
	 * @param CommonObject $object Object
	 * @param User         $user   User
	 * @param Translate    $langs  Translations
	 * @param Conf         $conf   Configuration
	 * @return int
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('emrecupel') || !isset(self::$map[$action])) {
			return 0;
		}

		dol_include_once('/emrecupel/class/emrecupeldocumentsync.class.php');
		if (EmrecupelDocumentSync::$busy) {
			return 0;
		}
		list($kind, $element) = self::$map[$action];

		$sync = new EmrecupelDocumentSync($this->db);
		$res = 0;
		try {
			switch ($kind) {
				case 'line_insert':
					$res = $sync->onLineInserted($object, $user);
					break;
				case 'line_modify':
					$res = $sync->onLineModified($object, $user);
					break;
				case 'line_delete':
					$res = $sync->onLineDeleted($object, $user);
					break;
				case 'doc_create':
					$res = $sync->onDocumentCreated($element, (int) $object->id, $user);
					break;
				case 'doc_modify':
					$res = $sync->onDocumentModified($element, (int) $object->id, $user);
					break;
				case 'ledger_write':
					$res = $sync->writeLedger((int) $object->id);
					break;
				case 'ledger_delete':
					$res = $sync->deleteLedger((int) $object->id);
					break;
				case 'product_delete':
					dol_include_once('/emrecupel/class/emrecupelproduct.class.php');
					$p = new EmrecupelProduct($this->db);
					$p->onProductDeleted((int) $object->id);
					break;
			}
		} catch (\Throwable $e) {
			dol_syslog('emrecupel: ' . $action . ' failed: ' . $e->getMessage(), LOG_ERR);
			$res = 0;
		}

		if ($res < 0) {
			// Never block the user's own operation because of the fee: log it;
			// the "Recalculate" button of the Recupel tab repairs a document.
			dol_syslog('emrecupel: ' . $action . ' error: ' . $sync->error, LOG_WARNING);
			return 0;
		}
		return $res > 0 ? 1 : 0;
	}
}
