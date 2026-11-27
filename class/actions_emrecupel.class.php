<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/actions_emrecupel.class.php
 * \ingroup emrecupel
 * \brief   Hooks of emRecupel:
 *           - printCommonFooter : automatic database migration after an update;
 *           - formConfirm       : apply deferred fee reconciliations before a
 *                                 proposal/order/invoice card is displayed;
 *           - beforePDFCreation : "fee included" mention under product lines
 *                                 and summary in the footer of the PDF, with
 *                                 every standard model (no own PDF model);
 *           - afterPDFCreation  : restore what beforePDFCreation changed.
 */

class ActionsEmrecupel
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var array<string,mixed> */
	public $results = array();

	/** @var string */
	public $resprints = '';

	/** @var array<int,array{lines:array<int,string>,const:string,old:string|null}> object id => saved state */
	private static $pdfState = array();

	/** @var array<string,string> element => free text constant of the PDF footer */
	private static $freeTextConst = array(
		'propal' => 'PROPOSAL_FREE_TEXT',
		'commande' => 'ORDER_FREE_TEXT',
		'facture' => 'INVOICE_FREE_TEXT',
	);

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Automatic migration after a file-only update.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject|null   $object     Object
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
	{
		global $user;
		if (!isModEnabled('emrecupel') || !is_object($user) || empty($user->id)) {
			return 0;
		}
		// Literal version first: no class loaded on every page once up to date.
		if (getDolGlobalString('EMRECUPEL_DB_VERSION') !== '2.0.1') {
			dol_include_once('/emrecupel/class/emrecupelmigrations.class.php');
			if (getDolGlobalString('EMRECUPEL_DB_VERSION') !== EmrecupelMigrations::MODULE_VERSION) {
				EmrecupelMigrations::runIfNeeded($this->db);
			}
		}
		// Deferred fee reconciliations of this request (lines copied after the
		// document creation trigger, e.g. mass invoicing from the order list):
		// run them now, llxFooter() is called before $db->close().
		if (class_exists('EmrecupelDocumentSync', false)) {
			EmrecupelDocumentSync::runPending();
		}
		return 0;
	}

	/**
	 * Before a document card is rendered: run the reconciliations scheduled by
	 * the action of this request (credit note with lines, conversion…) and
	 * reload the object when its lines changed, so that the card shows them.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject        $object      Object
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		if (!isModEnabled('emrecupel') || !is_object($object) || empty($object->id)) {
			return 0;
		}
		$element = $this->elementOf($object);
		if ($element === '') {
			return 0;
		}
		dol_include_once('/emrecupel/class/emrecupeldocumentsync.class.php');
		EmrecupelDocumentSync::runPending();
		if (!empty(EmrecupelDocumentSync::$changedDocs[$element][(int) $object->id])) {
			unset(EmrecupelDocumentSync::$changedDocs[$element][(int) $object->id]);
			$object->fetch($object->id);
		}
		return 0;
	}

	/**
	 * Add the Recupel mentions to the document being printed.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters (file, object, outputlangs)
	 * @param CommonObject        $object      Document
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function beforePDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;
		if (!isModEnabled('emrecupel') || !is_object($object) || empty($object->id) || !empty($object->specimen)) {
			return 0;
		}
		$element = $this->elementOf($object);
		if ($element === '' || empty($object->lines)) {
			return 0;
		}
		try {
			$outputlangs = (isset($parameters['outputlangs']) && is_object($parameters['outputlangs'])) ? $parameters['outputlangs'] : $GLOBALS['langs'];
			$outputlangs->loadLangs(array('emrecupel@emrecupel'));
			dol_include_once('/emrecupel/class/emrecupeldocumentsync.class.php');
			$sync = new EmrecupelDocumentSync($this->db);
			$analysis = $sync->analyse($element, (int) $object->id);
			if ($analysis === null) {
				return 0;
			}
			$render = self::buildMentions($analysis, $outputlangs);
			if (empty($render['lines']) && $render['footer'] === '') {
				return 0;
			}

			$state = array('lines' => array(), 'const' => self::$freeTextConst[$element], 'old' => null);
			foreach ($object->lines as $i => $line) {
				$lid = (int) (!empty($line->id) ? $line->id : (!empty($line->rowid) ? $line->rowid : 0));
				if ($lid > 0 && isset($render['lines'][$lid])) {
					$state['lines'][$i] = (string) $line->desc;
					$object->lines[$i]->desc = rtrim((string) $line->desc) . "\n" . $render['lines'][$lid];
				}
			}
			if ($render['footer'] !== '') {
				$const = $state['const'];
				$state['old'] = isset($conf->global->$const) ? (string) $conf->global->$const : null;
				$current = (string) $state['old'];
				$conf->global->$const = $render['footer'] . ($current !== '' ? "\n" . $current : '');
			}
			self::$pdfState[spl_object_id($object)] = $state;
		} catch (\Throwable $e) {
			dol_syslog('emrecupel: beforePDFCreation ' . $e->getMessage(), LOG_ERR);
		}
		return 0;
	}

	/**
	 * Restore the descriptions and the free text changed for the PDF.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters (object = document)
	 * @param object              $object      PDF model
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function afterPDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;
		$doc = isset($parameters['object']) && is_object($parameters['object']) ? $parameters['object'] : null;
		if ($doc === null) {
			return 0;
		}
		$k = spl_object_id($doc);
		if (!isset(self::$pdfState[$k])) {
			return 0;
		}
		$state = self::$pdfState[$k];
		unset(self::$pdfState[$k]);
		foreach ($state['lines'] as $i => $desc) {
			if (isset($doc->lines[$i])) {
				$doc->lines[$i]->desc = $desc;
			}
		}
		$const = $state['const'];
		if ($state['old'] === null) {
			unset($conf->global->$const);
		} else {
			$conf->global->$const = $state['old'];
		}
		return 0;
	}

	/**
	 * Texts printed on a document: mention under each product line whose fee
	 * is included, and one footer summary.
	 *
	 * @param array<string,mixed> $analysis    Result of EmrecupelDocumentSync::analyse()
	 * @param Translate           $outputlangs Language of the document
	 * @return array{lines:array<int,string>,footer:string,total_included:float}
	 */
	public static function buildMentions(array $analysis, $outputlangs)
	{
		$lines = array();
		$total = 0.0;
		$footerOnly = false;
		$used = false;
		foreach ($analysis['lines'] as $l) {
			$texts = array();
			foreach ($l['fees'] as $fee) {
				if ($fee['decision'] === EmrecupelFeePolicy::SKIP_USED) {
					$used = true;
					continue;
				}
				if ($fee['decision'] !== EmrecupelFeePolicy::APPLY || $fee['mode'] === EmrecupelFeePolicy::MODE_LINE || $fee['included_ttc'] === null) {
					continue;
				}
				$lineTotal = round((float) $fee['included_ttc'] * abs((float) $l['qty']), 2);
				$total += $lineTotal;
				if ($fee['mode'] === EmrecupelFeePolicy::MODE_FOOTER) {
					$footerOnly = true;
					continue;
				}
				$texts[] = $outputlangs->transnoentities('RecupelMentionLine', price((float) $fee['included_ttc'], 0, $outputlangs, 1, -1, 2, getDolGlobalString('MAIN_MONNAIE', 'EUR')), $fee['code']);
			}
			if (!empty($texts)) {
				$lines[(int) $l['id']] = implode("\n", $texts);
			}
		}
		$footer = array();
		if ($total > 0 || $footerOnly) {
			$footer[] = $outputlangs->transnoentities('RecupelMentionFooter', price($total, 0, $outputlangs, 1, -1, 2, getDolGlobalString('MAIN_MONNAIE', 'EUR')));
		}
		if ($used) {
			$footer[] = $outputlangs->transnoentities('RecupelMentionUsed');
		}
		return array('lines' => $lines, 'footer' => implode("\n", $footer), 'total_included' => $total);
	}

	/**
	 * Element of a supported document.
	 *
	 * @param object $object Object
	 * @return string propal, commande, facture or ''
	 */
	private function elementOf($object)
	{
		$el = isset($object->element) ? (string) $object->element : '';
		return in_array($el, array('propal', 'commande', 'facture'), true) ? $el : '';
	}
}
