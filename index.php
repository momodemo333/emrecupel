<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 * GPL-3.0-or-later
 *
 * Landing page of the module: products to classify.
 */

$res = 0;
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

header('Location: ' . dol_buildpath('/emrecupel/classify.php', 1));
exit;
