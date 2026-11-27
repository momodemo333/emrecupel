<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/about.php
 * \ingroup emrecupel
 * \brief   About emRecupel: version, sources, disclaimer.
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

dol_include_once('/emrecupel/core/modules/modEmrecupel.class.php');
dol_include_once('/emrecupel/lib/emrecupel.lib.php');

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'emrecupel@emrecupel'));
if (!$user->admin && !$user->hasRight('emrecupel', 'setup')) {
	accessforbidden();
}
$mod = new modEmrecupel($db);

llxHeader('', $langs->trans('About'), '', '', 0, 0, '', '', '', 'mod-emrecupel page-admin-about');
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($langs->trans('EmrecupelSetup'), $linkback, 'title_setup');
print dol_get_fiche_head(emrecupel_admin_prepare_head(), 'about', '', -1, '');

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">emRecupel</th></tr>';
print '<tr class="oddeven"><td class="titlefield">' . $langs->trans('Version') . '</td><td><strong>' . dol_escape_htmltag($mod->version) . '</strong></td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('Publisher') . '</td><td>' . dol_escape_htmltag($mod->editor_name) . ' — <a href="' . dol_escape_htmltag($mod->editor_url) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($mod->editor_url) . '</a></td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('License') . '</td><td>GPL-3.0-or-later</td></tr>';
print '</table><br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>' . $langs->trans('RecupelSources') . '</th></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('RecupelSourcesText') . '<ul>';
print '<li><a href="https://www.recupel.be/fr/media/2246" target="_blank" rel="noopener noreferrer">Recupel — liste des appareils 2026 (FR)</a> · <a href="https://www.recupel.be/nl/media/2245" target="_blank" rel="noopener noreferrer">NL</a> · <a href="https://www.recupel.be/en/media/2239" target="_blank" rel="noopener noreferrer">EN</a></li>';
print '<li><a href="https://www.recupel.be/fr/media/2411" target="_blank" rel="noopener noreferrer">Recupel — liste des appareils 2027 (FR)</a> · <a href="https://www.recupel.be/nl/media/2409" target="_blank" rel="noopener noreferrer">NL</a> · <a href="https://www.recupel.be/en/media/2410" target="_blank" rel="noopener noreferrer">EN</a></li>';
print '<li><a href="https://www.recupel.be/en/faq/declarations" target="_blank" rel="noopener noreferrer">Recupel — FAQ declarations (2.13)</a></li>';
print '<li><a href="https://www.cnc-cbn.be/fr/avis/implications-comptables-de-lobligation-dacceptation-des-dechets-dequipements-electriques-et" target="_blank" rel="noopener noreferrer">CNC — avis 2009/9</a></li>';
print '</ul></td></tr>';
print '</table><br>';

print emrecupel_disclaimer();

print dol_get_fiche_end();
llxFooter();
$db->close();
