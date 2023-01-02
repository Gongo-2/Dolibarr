<?php
/* Copyright (C) 2001-2004	Rodolphe Quiedeville	<rodolphe@quiedeville.org>
 * Copyright (C) 2004-2016	Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2005-2014	Regis Houssin			<regis.houssin@inodbox.com>
 * Copyright (C) 2015       Juanjo Menent           <jmenent@2byte.es>
 * Copyright (C) 2020       Tobias Sekan            <tobias.sekan@startmail.com>
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
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *      \file       htdocs/product/stock/list.php
 *      \ingroup    stock
 *      \brief      Page with warehouse and stock value
 */

// Load Dolibarr environment
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';

if (isModEnabled('categorie')) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcategory.class.php';
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
}

// Load translation files required by the page
$langs->loadLangs(array("stocks", "other"));

$action     = GETPOST('action', 'aZ09') ?GETPOST('action', 'aZ09') : 'view'; // The action 'add', 'create', 'edit', 'update', 'view', ...
$massaction = GETPOST('massaction', 'alpha'); // The bulk action (combo box choice into lists)
$show_files = GETPOST('show_files', 'int'); // Show files area generated by bulk actions ?
$confirm    = GETPOST('confirm', 'alpha'); // Result of a confirmation
$cancel     = GETPOST('cancel', 'alpha'); // We click on a Cancel button
$toselect   = GETPOST('toselect', 'array'); // Array of ids of elements selected into a list
$contextpage = GETPOST('contextpage', 'aZ') ?GETPOST('contextpage', 'aZ') : 'stocklist'; // To manage different context of search
$backtopage = GETPOST('backtopage', 'alpha'); // Go back to a dedicated page
$optioncss  = GETPOST('optioncss', 'aZ'); // Option for the css output (always '' except when 'print')
$toselect = GETPOST('toselect', 'array');


$search_all = trim((GETPOST('search_all', 'alphanohtml') != '') ?GETPOST('search_all', 'alphanohtml') : GETPOST('sall', 'alphanohtml'));
$search_ref = GETPOST("sref", "alpha") ?GETPOST("sref", "alpha") : GETPOST("search_ref", "alpha");
$search_label = GETPOST("snom", "alpha") ?GETPOST("snom", "alpha") : GETPOST("search_label", "alpha");
$search_status = GETPOST("search_status", "int");
$mode     = GETPOST('mode', 'alpha');


if (isModEnabled('categorie')) {
	$search_category_list = GETPOST("search_category_".Categorie::TYPE_WAREHOUSE."_list", "array");
}

// Load variable for pagination
$limit = GETPOST('limit', 'int') ?GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page == -1 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha') || (empty($toselect) && $massaction === '0')) {
	$page = 0;
}     // If $page is not defined, or '' or -1 or if we click on clear filters or if we select empty mass action
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (!$sortfield) {
	$sortfield = "t.ref";
}
if (!$sortorder) {
	$sortorder = "ASC";
}

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$object = new Entrepot($db);
$extrafields = new ExtraFields($db);
$diroutputmassaction = $conf->stock->dir_output.'/temp/massgeneration/'.$user->id;
$hookmanager->initHooks(array('stocklist'));

// Fetch optionals attributes and labels
$extrafields->fetch_name_optionals_label($object->table_element);

$search_array_options = $extrafields->getOptionalsFromPost($object->table_element, '', 'search_');


// Initialize array of search criterias
$search_all = GETPOST("search_all", 'alpha');
$search = array();
foreach ($object->fields as $key => $val) {
	$search_key = $key;
	if ($search_key == 'statut') {
		$search_key = 'status'; // remove this after refactor entrepot.class property statut to status
	}
	if (GETPOST('search_'.$search_key, 'alpha') !== '') {
		$search[$search_key] = GETPOST('search_'.$search_key, 'alpha');
	}
}

// List of fields to search into when doing a "search in all"
$fieldstosearchall = array();
foreach ($object->fields as $key => $val) {
	if (!empty($val['searchall'])) {
		$fieldstosearchall['t.'.$key] = $val['label'];
	}
}

// Definition of array of fields for columns
$arrayfields = array(
	'stockqty'=>array('type'=>'float', 'label'=>'PhysicalStock', 'enabled'=>1, 'visible'=>-2, 'checked'=>0, 'position'=>170),
	'estimatedvalue'=>array('type'=>'float', 'label'=>'EstimatedStockValue', 'enabled'=>1, 'visible'=>1, 'checked'=>1, 'position'=>171),
	'estimatedstockvaluesell'=>array('type'=>'float', 'label'=>'EstimatedStockValueSell', 'enabled'=>1, 'checked'=>1, 'visible'=>2, 'position'=>172),
);
foreach ($object->fields as $key => $val) {
	// If $val['visible']==0, then we never show the field
	if (!empty($val['visible'])) {
		$visible = (int) dol_eval($val['visible'], 1, 1, '1');
		$arrayfields['t.'.$key] = array(
			'label'=>$val['label'],
			'checked'=>(($visible < 0) ? 0 : 1),
			'enabled'=>($visible != 3 && dol_eval($val['enabled'], 1, 1, '1')),
			'position'=>$val['position'],
			'help'=> isset($val['help']) ? $val['help'] : 'help'
		);
	}
}
// Extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_array_fields.tpl.php';

$object->fields = dol_sort_array($object->fields, 'position');
$arrayfields = dol_sort_array($arrayfields, 'position');

// Security check
$result = restrictedArea($user, 'stock');


/*
 * Actions
 */

if (GETPOST('cancel', 'alpha')) {
	$action = 'list'; $massaction = '';
}
if (!GETPOST('confirmmassaction', 'alpha') && $massaction != 'presend' && $massaction != 'confirm_presend') {
	$massaction = '';
}

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	// Selection of new fields
	include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';

	// Purge search criteria
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) { // All tests are required to be compatible with all browsers
		foreach ($object->fields as $key => $val) {
			$search[$key] = '';
		}
		$toselect = array();
		$search_array_options = array();
		$search_category_list = array();
	}
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')
		|| GETPOST('button_search_x', 'alpha') || GETPOST('button_search.x', 'alpha') || GETPOST('button_search', 'alpha')) {
		$massaction = ''; // Protection to avoid mass action if we force a new search during a mass action confirmation
	}

	// Mass actions
	$objectclass = 'Entrepot';
	$objectlabel = 'Warehouse';
	$permissiontoread = $user->rights->stock->lire;
	$permissiontodelete = $user->rights->stock->supprimer;
	$permissiontoadd = $user->rights->stock->creer;
	$uploaddir = $conf->stock->dir_output;
	include DOL_DOCUMENT_ROOT.'/core/actions_massactions.inc.php';
}


/*
 *	View
 */

$form = new Form($db);
$warehouse = new Entrepot($db);

$now = dol_now();

$help_url = 'EN:Module_Stocks_En|FR:Module_Stock|ES:M&oacute;dulo_Stocks';
$title = $langs->trans("Warehouses");

$totalarray = array();
$totalarray['nbfield'] = 0;

// Build and execute select
// --------------------------------------------------------------------
$sql = 'SELECT ';
foreach ($object->fields as $key => $val) {
	$sql .= "t.".$key.", ";
}
// Add fields from extrafields
if (!empty($extrafields->attributes[$object->table_element]['label'])) {
	foreach ($extrafields->attributes[$object->table_element]['label'] as $key => $val) {
		$sql .= ($extrafields->attributes[$object->table_element]['type'][$key] != 'separate' ? "ef.".$key." as options_".$key.', ' : '');
	}
}

//For Multicompany PMP per entity
$separatedPMP = false;
if (!empty($conf->global->MULTICOMPANY_PRODUCT_SHARING_ENABLED) && !empty($conf->global->MULTICOMPANY_PMP_PER_ENTITY_ENABLED)) {
	$separatedPMP = true;
	$sql .= " SUM(pa.pmp * ps.reel) as estimatedvalue, SUM(p.price * ps.reel) as sellvalue, SUM(ps.reel) as stockqty";
} else {
	$sql .= " SUM(p.pmp * ps.reel) as estimatedvalue, SUM(p.price * ps.reel) as sellvalue, SUM(ps.reel) as stockqty";
}

// Add fields from hooks
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters, $object); // Note that $action and $object may have been modified by hook
$sql .= $hookmanager->resPrint;
$sql = preg_replace('/,\s*$/', '', $sql);
$sql .= " FROM ".MAIN_DB_PREFIX.$object->table_element." as t";
if (!empty($extrafields->attributes[$object->table_element]['label']) && is_array($extrafields->attributes[$object->table_element]['label']) && count($extrafields->attributes[$object->table_element]['label'])) {
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX.$object->table_element."_extrafields as ef on (t.rowid = ef.fk_object)";
}
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps ON t.rowid = ps.fk_entrepot";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON ps.fk_product = p.rowid";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_departements as c_dep ON c_dep.rowid = t.fk_departement";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_country as ccount ON ccount.rowid = t.fk_pays";
if ($separatedPMP) {
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_perentity as pa ON pa.fk_product = p.rowid AND pa.fk_product = ps.fk_product AND pa.entity = ". (int) $conf->entity;
}
$sql .= " WHERE t.entity IN (".getEntity('stock').")";
foreach ($search as $key => $val) {
	$class_key = $key;
	if ($class_key == 'status') {
		$class_key = 'statut'; // remove this after refactoring entrepot.class property statut to status
	}
	if (($key == 'status' && $search[$key] == -1) || $key == 'entity') {
		continue;
	}
	$mode_search = (($object->isInt($object->fields[$key]) || $object->isFloat($object->fields[$key])) ? 1 : 0);
	if (strpos($object->fields[$key]['type'], 'integer:') === 0) {
		if ($search[$key] == '-1') {
			$search[$key] = '';
		}
		$mode_search = 2;
	}
	if ($search[$key] != '') {
		$sql .= natural_search((($key == "ref") ? "t.ref" : "t.".$class_key), $search[$key], (($key == 'status') ? 2 : $mode_search));
	}
}
if ($search_all) {
	$sql .= natural_search(array_keys($fieldstosearchall), $search_all);
}
// Search for tag/category ($searchCategoryWarehouseList is an array of ID)
$searchCategoryWarehouseList = $search_category_list;
$searchCategoryWarehouseOperator = 0;
if (!empty($searchCategoryWarehouseList)) {
	$searchCategoryWarehouseSqlList = array();
	$listofcategoryid = '';
	foreach ($searchCategoryWarehouseList as $searchCategoryWarehouse) {
		if (intval($searchCategoryWarehouse) == -2) {
			$searchCategoryWarehouseSqlList[] = "NOT EXISTS (SELECT ck.fk_warehouse FROM ".MAIN_DB_PREFIX."categorie_warehouse as ck WHERE p.rowid = ck.fk_warehouse)";
		} elseif (intval($searchCategoryWarehouse) > 0) {
			if ($searchCategoryWarehouseOperator == 0) {
				$searchCategoryWarehouseSqlList[] = " EXISTS (SELECT ck.fk_warehouse FROM ".MAIN_DB_PREFIX."categorie_warehouse as ck WHERE p.rowid = ck.fk_warehouse AND ck.fk_categorie = ".((int) $searchCategoryWarehouse).")";
			} else {
				$listofcategoryid .= ($listofcategoryid ? ', ' : '') .((int) $searchCategoryWarehouse);
			}
		}
	}
	if ($listofcategoryid) {
		$searchCategoryWarehouseSqlList[] = " EXISTS (SELECT ck.fk_warehouse FROM ".MAIN_DB_PREFIX."categorie_warehouse as ck WHERE p.rowid = ck.fk_warehouse AND ck.fk_categorie IN (".$db->sanitize($listofcategoryid)."))";
	}
	if ($searchCategoryWarehouseOperator == 1) {
		if (!empty($searchCategoryWarehouseSqlList)) {
			$sql .= " AND (".implode(' OR ', $searchCategoryWarehouseSqlList).")";
		}
	} else {
		if (!empty($searchCategoryWarehouseSqlList)) {
			$sql .= " AND (".implode(' AND ', $searchCategoryWarehouseSqlList).")";
		}
	}
}

// Add where from extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_sql.tpl.php';
// Add where from hooks
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters, $object); // Note that $action and $object may have been modified by hook
$sql .= $hookmanager->resPrint;
$sql .= " GROUP BY ";
foreach ($object->fields as $key => $val) {
	$sql .= "t.".$key.", ";
}
// Add fields from extrafields
if (!empty($extrafields->attributes[$object->table_element]['label'])) {
	foreach ($extrafields->attributes[$object->table_element]['label'] as $key => $val) {
		$sql .= ($extrafields->attributes[$object->table_element]['type'][$key] != 'separate' ? "ef.".$key.', ' : '');
	}
}
// Add where from hooks
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListGroupBy', $parameters); // Note that $action and $object may have been modified by hook
$sql .= $hookmanager->resPrint;
$sql = preg_replace('/,\s*$/', '', $sql);
$totalnboflines = 0;

$result = $db->query($sql);
if ($result) {
	$totalnboflines = $db->num_rows($result);
	// fetch totals
	$line = $total = $totalsell = $totalStock = 0;
	while ($line < $totalnboflines) {
		$objp = $db->fetch_object($result);
		$total += $objp->estimatedvalue;
		$totalsell += $objp->sellvalue;
		$totalStock += $objp->stockqty;
		$line++;
	}
	$totalarray['val']['stockqty'] = price2num($totalStock, 'MS');
	$totalarray['val']['estimatedvalue'] = price2num($total, 'MT');
	$totalarray['val']['estimatedstockvaluesell'] = price2num($totalsell, 'MT');
}
$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records
$nbtotalofrecords = '';
if (empty($conf->global->MAIN_DISABLE_FULL_SCANLIST)) {
	$resql = $db->query($sql);
	$nbtotalofrecords = $db->num_rows($resql);
	if (($page * $limit) > $nbtotalofrecords) {	// if total of record found is smaller than page * limit, goto and load page 0
		$page = 0;
		$offset = 0;
	}
}
// if total of record found is smaller than limit, no need to do paging and to restart another select with limits set.
if (is_numeric($nbtotalofrecords) && ($limit > $nbtotalofrecords || empty($limit))) {
	$num = $nbtotalofrecords;
} else {
	if ($limit) {
		$sql .= $db->plimit($limit + 1, $offset);
	}

	$resql = $db->query($sql);
	if (!$resql) {
		dol_print_error($db);
		exit;
	}

	$num = $db->num_rows($resql);
}

// Direct jump if only one record found
if ($num == 1 && !empty($conf->global->MAIN_SEARCH_DIRECT_OPEN_IF_ONLY_ONE) && $search_all && !$page) {
	$obj = $db->fetch_object($resql);
	$id = $obj->rowid;
	header("Location: ".DOL_URL_ROOT.'/product/stock/card.php?id='.$id);
	exit;
}


// Output page
// --------------------------------------------------------------------

llxHeader('', $title, $help_url);

$arrayofselected = is_array($toselect) ? $toselect : array();

$param = '';
if (!empty($mode)) {
	$param .= '&mode='.urlencode($mode);
}
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) {
	$param .= '&contextpage='.urlencode($contextpage);
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.urlencode($limit);
}
foreach ($search as $key => $val) {
	if (is_array($search[$key]) && count($search[$key])) {
		foreach ($search[$key] as $skey) {
			$param .= '&search_'.$key.'[]='.urlencode($skey);
		}
	} else {
		$param .= '&search_'.$key.'='.urlencode($search[$key]);
	}
}
if ($optioncss != '') {
	$param .= '&optioncss='.urlencode($optioncss);
}
// Add $param from extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_param.tpl.php';

// List of mass actions available
$arrayofmassactions = array(
	//'presend'=>img_picto('', 'email', 'class="pictofixedwidth"').$langs->trans("SendByMail"),
	//'builddoc'=>img_picto('', 'pdf', 'class="pictofixedwidth"').$langs->trans("PDFMerge"),
);
//if ($user->rights->stock->supprimer) $arrayofmassactions['predelete']=img_picto('', 'delete', 'class="pictofixedwidth"').$langs->trans("Delete");
if (GETPOST('nomassaction', 'int') || in_array($massaction, array('presend', 'predelete','preaffecttag'))) {
	$arrayofmassactions = array();
}
if (isModEnabled('category') && $user->rights->stock->creer) {
	$arrayofmassactions['preaffecttag'] = img_picto('', 'label', 'class="pictofixedwidth"').$langs->trans("AffectTag");
}
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

print '<form action="'.$_SERVER["PHP_SELF"].'" id="searchFormList" method="POST" name="formulaire">';
if ($optioncss != '') {
	print '<input type="hidden" name="optioncss" value="'.$optioncss.'">';
}
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="contextpage" value="'.$contextpage.'">';
print '<input type="hidden" name="mode" value="'.$mode.'">';


$newcardbutton = '';
$newcardbutton .= dolGetButtonTitle($langs->trans('ViewList'), '', 'fa fa-bars imgforviewmode', $_SERVER["PHP_SELF"].'?mode=common'.preg_replace('/(&|\?)*mode=[^&]+/', '', $param), '', ((empty($mode) || $mode == 'common') ? 2 : 1), array('morecss'=>'reposition'));
$newcardbutton .= dolGetButtonTitle($langs->trans('ViewKanban'), '', 'fa fa-th-list imgforviewmode', $_SERVER["PHP_SELF"].'?mode=kanban'.preg_replace('/(&|\?)*mode=[^&]+/', '', $param), '', ($mode == 'kanban' ? 2 : 1), array('morecss'=>'reposition'));
$newcardbutton .= dolGetButtonTitle($langs->trans('MenuNewWarehouse'), '', 'fa fa-plus-circle', DOL_URL_ROOT.'/product/stock/card.php?action=create&backtopage='.urlencode($_SERVER['PHP_SELF']), '', $user->rights->stock->creer);

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'stock', 0, $newcardbutton, '', $limit, 0, 0, 1);

// Add code for pre mass action (confirmation or email presend form)
$topicmail = "Information";
$modelmail = "warehouse";
$objecttmp = new Entrepot($db);
$trackid = 'ware'.$object->id;
include DOL_DOCUMENT_ROOT.'/core/tpl/massactions_pre.tpl.php';


if ($search_all) {
	foreach ($fieldstosearchall as $key => $val) {
		$fieldstosearchall[$key] = $langs->trans($val);
	}
	print '<div class="divsearchfieldfilter">'.$langs->trans("FilterOnInto", $sall).join(', ', $fieldstosearchall).'</div>';
}

$moreforfilter = '';

if (isModEnabled('categorie') && $user->rights->categorie->lire) {
	$formcategory = new FormCategory($db);
	$moreforfilter .= $formcategory->getFilterBox(Categorie::TYPE_WAREHOUSE, $search_category_list);
}

/*$moreforfilter.='<div class="divsearchfield">';
 $moreforfilter.= $langs->trans('MyFilter') . ': <input type="text" name="search_myfield" value="'.dol_escape_htmltag($search_myfield).'">';
 $moreforfilter.= '</div>';*/

$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldPreListTitle', $parameters, $object); // Note that $action and $object may have been modified by hook
if (empty($reshook)) {
	$moreforfilter .= $hookmanager->resPrint;
} else {
	$moreforfilter = $hookmanager->resPrint;
}

if (!empty($moreforfilter)) {
	print '<div class="liste_titre liste_titre_bydiv centpercent">';
	print $moreforfilter;
	print '</div>';
}

$varpage = empty($contextpage) ? $_SERVER["PHP_SELF"] : $contextpage;
$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $varpage, getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN', '')); // This also change content of $arrayfields
$selectedfields .= (count($arrayofmassactions) ? $form->showCheckAddButtons('checkforselect', 1) : '');

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste'.($moreforfilter ? " listwithfilterbefore" : "").'">'."\n";

// Fields title search
// --------------------------------------------------------------------
print '<tr class="liste_titre_filter">';
// Action column
if (!empty($conf->global->MAIN_CHECKBOX_LEFT_COLUMN)) {
	print '<td class="liste_titre maxwidthsearch">';
	$searchpicto = $form->showFilterButtons('left');
	print $searchpicto;
	print '</td>';
}
foreach ($object->fields as $key => $val) {
	if ($key == 'statut') {
		continue;
	}
	$cssforfield = (empty($val['csslist']) ? (empty($val['css']) ? '' : $val['css']) : $val['csslist']);
	if ($key == 'status') {
		$cssforfield .= ($cssforfield ? ' ' : '').'center';
	} elseif (in_array($val['type'], array('date', 'datetime', 'timestamp'))) {
		$cssforfield .= ($cssforfield ? ' ' : '').'center';
	} elseif (in_array($val['type'], array('timestamp'))) {
		$cssforfield .= ($cssforfield ? ' ' : '').'nowrap';
	} elseif (in_array($val['type'], array('double(24,8)', 'double(6,3)', 'integer', 'real', 'price')) && $val['label'] != 'TechnicalID' && empty($val['arrayofkeyval'])) {
		$cssforfield .= ($cssforfield ? ' ' : '').'right';
	}
	if (!empty($arrayfields['t.'.$key]['checked'])) {
		print '<td class="liste_titre'.($cssforfield ? ' '.$cssforfield : '').'">';
		if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
			print $form->selectarray('search_'.$key, $val['arrayofkeyval'], $search[$key], $val['notnull'], 0, 0, '', 1, 0, 0, '', 'maxwidth100', 1);
		} elseif ((strpos($val['type'], 'integer:') === 0) || (strpos($val['type'], 'sellist:') === 0)) {
			print $object->showInputField($val, $key, $search[$key], '', '', 'search_', 'maxwidth125', 1);
		} elseif (!preg_match('/^(date|timestamp)/', $val['type'])) {
			print '<input type="text" class="flat maxwidth75" name="search_'.$key.'" value="'.dol_escape_htmltag(!empty($search[$key])?$search[$key]:'').'">';
		}
		print '</td>';
	}
}

if (!empty($arrayfields["stockqty"]['checked'])) {
	print '<td class="liste_titre"></td>';
}

if (!empty($arrayfields["estimatedvalue"]['checked'])) {
	print '<td class="liste_titre"></td>';
}

if (!empty($arrayfields["estimatedstockvaluesell"]['checked'])) {
	print '<td class="liste_titre"></td>';
}

// Extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_input.tpl.php';

// Fields from hook
$parameters = array('arrayfields'=>$arrayfields);
$reshook = $hookmanager->executeHooks('printFieldListOption', $parameters, $object); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;

// Status
if (!empty($arrayfields['t.statut']['checked'])) {
	print '<td class="liste_titre center">';
	print $form->selectarray('search_status', $warehouse->statuts, $search_status, 1, 0, 0, '', 1, 0, 0, '', 'onrightofpage');
	print '</td>';
}

// Action column
if (empty($conf->global->MAIN_CHECKBOX_LEFT_COLUMN)) {
	print '<td class="liste_titre maxwidthsearch">';
	$searchpicto = $form->showFilterButtons();
	print $searchpicto;
	print '</td>';
}
print '</tr>'."\n";

// Fields title label
// --------------------------------------------------------------------
print '<tr class="liste_titre">';

// Action column
if (!empty($conf->global->MAIN_CHECKBOX_LEFT_COLUMN)) {
	print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ')."\n";
}
foreach ($object->fields as $key => $val) {
	if ($key == 'statut') {
		continue;
	}
	$cssforfield = (empty($val['csslist']) ? (empty($val['css']) ? '' : $val['css']) : $val['csslist']);
	if ($key == 'status') {
		$cssforfield .= ($cssforfield ? ' ' : '').'center';
	} elseif (in_array($val['type'], array('date', 'datetime', 'timestamp'))) {
		$cssforfield .= ($cssforfield ? ' ' : '').'center';
	} elseif (in_array($val['type'], array('timestamp'))) {
		$cssforfield .= ($cssforfield ? ' ' : '').'nowrap';
	} elseif (in_array($val['type'], array('double(24,8)', 'double(6,3)', 'integer', 'real', 'price')) && $val['label'] != 'TechnicalID' && empty($val['arrayofkeyval'])) {
		$cssforfield .= ($cssforfield ? ' ' : '').'right';
	}
	if (!empty($arrayfields['t.'.$key]['checked'])) {
		print getTitleFieldOfList($arrayfields['t.'.$key]['label'], 0, $_SERVER['PHP_SELF'], 't.'.$key, '', $param, ($cssforfield ? 'class="'.$cssforfield.'"' : ''), $sortfield, $sortorder, ($cssforfield ? $cssforfield.' ' : ''))."\n";
	}
}

if (!empty($arrayfields["stockqty"]['checked'])) {
	print_liste_field_titre("PhysicalStock", $_SERVER["PHP_SELF"], "stockqty", '', $param, '', $sortfield, $sortorder, 'right ');
}

if (!empty($arrayfields["estimatedvalue"]['checked'])) {
	print_liste_field_titre("EstimatedStockValue", $_SERVER["PHP_SELF"], "estimatedvalue", '', $param, '', $sortfield, $sortorder, 'right ');
}

if (!empty($arrayfields["estimatedstockvaluesell"]['checked'])) {
	print_liste_field_titre("EstimatedStockValueSell", $_SERVER["PHP_SELF"], "", '', $param, '', $sortfield, $sortorder, 'right ');
}

// Extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_title.tpl.php';

// Hook fields
$parameters = array('arrayfields'=>$arrayfields, 'param'=>$param, 'sortfield'=>$sortfield, 'sortorder'=>$sortorder);
$reshook = $hookmanager->executeHooks('printFieldListTitle', $parameters, $object); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;

if (!empty($arrayfields['t.statut']['checked'])) {
	print_liste_field_titre($arrayfields['t.statut']['label'], $_SERVER["PHP_SELF"], "t.statut", '', $param, '', $sortfield, $sortorder, 'center ');
}

// Action column
if (empty($conf->global->MAIN_CHECKBOX_LEFT_COLUMN)) {
	print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ')."\n";
}
print '</tr>'."\n";

// Loop on record
// --------------------------------------------------------------------
$i = 0;

$warehouse = new Entrepot($db);

while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	if (empty($obj)) {
		break; // Should not happen
	}

	// Store properties in $object
	$warehouse->setVarsFromFetchObj($obj);

	$warehouse->label = $warehouse->ref;
	$warehouse->sellvalue = $obj->sellvalue;

	if ($mode =='kanban') {
		if ($i == 0) {
			print '<tr><td colspan="12">';
			print '<div class="box-flex-container">';
		}
		// Output Kanban
		print $warehouse->getKanbanView('');

		if ($i == ($imaxinloop - 1)) {
			print '</div>';
			print '</td></tr>';
		}
	} else {
		// Show here line of result
		print '<tr class="oddeven">';

		// Action column
		if (!empty($conf->global->MAIN_CHECKBOX_LEFT_COLUMN)) {
			print '<td class="nowrap center">';
			if ($massactionbutton || $massaction) {   // If we are in select mode (massactionbutton defined) or if we have already selected and sent an action ($massaction) defined
				$selected = 0;
				if (in_array($obj->rowid, $arrayofselected)) {
					$selected = 1;
				}
				print '<input id="cb'.$obj->rowid.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$obj->rowid.'"'.($selected ? ' checked="checked"' : '').'>';
			}
			print '</td>';
		}
		foreach ($warehouse->fields as $key => $val) {
			if ($key == 'statut') {
				continue;
			}
			$cssforfield = (empty($val['csslist']) ? (empty($val['css']) ? '' : $val['css']) : $val['csslist']);
			if (in_array($val['type'], array('date', 'datetime', 'timestamp'))) {
				$cssforfield .= ($cssforfield ? ' ' : '').'center';
			} elseif ($key == 'status') {
				$cssforfield .= ($cssforfield ? ' ' : '').'center';
			}

			if (in_array($val['type'], array('timestamp'))) {
				$cssforfield .= ($cssforfield ? ' ' : '').'nowrap';
			} elseif ($key == 'ref') {
				$cssforfield .= ($cssforfield ? ' ' : '').'nowrap';
			}

			if (in_array($val['type'], array('double(24,8)', 'double(6,3)', 'integer', 'real', 'price')) && $key != 'status' && empty($val['arrayofkeyval'])) {
				$cssforfield .= ($cssforfield ? ' ' : '').'right';
			}
			if (in_array($key, array('fk_soc', 'fk_user', 'fk_warehouse'))) {
				$cssforfield = 'tdoverflowmax100';
			}

			if (!empty($arrayfields['t.'.$key]['checked'])) {
				print '<td'.($cssforfield ? ' class="'.$cssforfield.'"' : '').'>';
				if ($key == 'statut') {
					print $warehouse->getLibStatut(5);
				}
				if ($key == 'phone') {
					print dol_print_phone($obj->phone, '', 0, $obj->rowid, 'AC_TEL');
				} elseif ($key == 'fax') {
					print dol_print_phone($obj->fax, '', 0, $obj->rowid, 'AC_FAX');
				} else {
					print $warehouse->showOutputField($val, $key, $warehouse->$key, '');
				}
				print '</td>';
				if (!$i) {
					$totalarray['nbfield']++;
				}
				if (!empty($val['isameasure']) && $val['isameasure'] == 1) {
					if (!$i) {
						$totalarray['pos'][$totalarray['nbfield']] = 't.'.$key;
					}
					if (!isset($totalarray['val'])) {
						$totalarray['val'] = array();
					}
					if (!isset($totalarray['val']['t.'.$key])) {
						$totalarray['val']['t.'.$key] = 0;
					}
					$totalarray['val']['t.'.$key] += $warehouse->$key;
				}
			}
		}

		// Stock qty
		if (!empty($arrayfields["stockqty"]['checked'])) {
			print '<td class="right">'.price2num($obj->stockqty, 5).'</td>';
			if (!$i) {
				$totalarray['nbfield']++;
			}
			if (!$i) {
				$totalarray['pos'][$totalarray['nbfield']] = 'stockqty';
			}
		}

		// PMP value
		if (!empty($arrayfields["estimatedvalue"]['checked'])) {
			print '<td class="right">';
			if (price2num($obj->estimatedvalue, 'MT')) {
				print '<span class="amount">'.price(price2num($obj->estimatedvalue, 'MT'), 1).'</span>';
			} else {
				print '';
			}
			print '</td>';
			if (!$i) {
				$totalarray['nbfield']++;
			}
			if (!$i) {
				$totalarray['pos'][$totalarray['nbfield']] = 'estimatedvalue';
			}
		}

		// Selling value
		if (!empty($arrayfields["estimatedstockvaluesell"]['checked'])) {
			print '<td class="right">';
			if (empty($conf->global->PRODUIT_MULTIPRICES)) {
				if ($obj->sellvalue) {
					print '<span class="amount">'.price(price2num($obj->sellvalue, 'MT'), 1).'</span>';
				}
			} else {
				$htmltext = $langs->trans("OptionMULTIPRICESIsOn");
				print $form->textwithtooltip('<span class="opacitymedium">'.$langs->trans("Variable").'</span>', $htmltext);
			}
			print '</td>';
			if (!$i) {
				$totalarray['nbfield']++;
			}
			if (!$i) {
				$totalarray['pos'][$totalarray['nbfield']] = 'estimatedstockvaluesell';
			}
		}

		// Extra fields
		include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_print_fields.tpl.php';
		// Fields from hook
		$parameters = array('arrayfields'=>$arrayfields, 'object'=>$object, 'obj'=>$obj, 'i'=>$i, 'totalarray'=>&$totalarray);
		$reshook = $hookmanager->executeHooks('printFieldListValue', $parameters, $object); // Note that $action and $object may have been modified by hook
		print $hookmanager->resPrint;

		// Status
		if (!empty($arrayfields['t.statut']['checked'])) {
			print '<td class="center">'.$warehouse->LibStatut($obj->statut, 5).'</td>';
			if (!$i) {
				$totalarray['nbfield']++;
			}
		}

		// Action column
		if (empty($conf->global->MAIN_CHECKBOX_LEFT_COLUMN)) {
			print '<td class="nowrap center">';
			if ($massactionbutton || $massaction) {   // If we are in select mode (massactionbutton defined) or if we have already selected and sent an action ($massaction) defined
				$selected = 0;
				if (in_array($obj->rowid, $arrayofselected)) {
					$selected = 1;
				}
				print '<input id="cb'.$obj->rowid.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$obj->rowid.'"'.($selected ? ' checked="checked"' : '').'>';
			}
			print '</td>';
		}
		if (!$i) {
			$totalarray['nbfield']++;
		}

		print '</tr>'."\n";
	}

	$i++;
}

if ($totalnboflines - $offset <= $limit) {
	// Show total line
	include DOL_DOCUMENT_ROOT.'/core/tpl/list_print_total.tpl.php';
}

// If no record found
if ($num == 0) {
	$colspan = 1;
	foreach ($arrayfields as $key => $val) {
		if (!empty($val['checked'])) {
			$colspan++;
		}
	}
	print '<tr><td colspan="'.$colspan.'" class="opacitymedium">'.$langs->trans("NoRecordFound").'</td></tr>';
}

$db->free($resql);

$parameters = array('arrayfields'=>$arrayfields, 'sql'=>$sql);
$reshook = $hookmanager->executeHooks('printFieldListFooter', $parameters, $object); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;

print '</table>'."\n";
print '</div>'."\n";

print '</form>'."\n";

if (in_array('builddoc', $arrayofmassactions) && ($nbtotalofrecords === '' || $nbtotalofrecords)) {
	$hidegeneratedfilelistifempty = 1;
	if ($massaction == 'builddoc' || $action == 'remove_file' || $show_files) {
		$hidegeneratedfilelistifempty = 0;
	}

	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
	$formfile = new FormFile($db);

	// Show list of available documents
	$urlsource = $_SERVER['PHP_SELF'].'?sortfield='.$sortfield.'&sortorder='.$sortorder;
	$urlsource .= str_replace('&amp;', '&', $param);

	$filedir = $diroutputmassaction;
	$genallowed = $user->rights->stock->lire;
	$delallowed = $user->rights->stock->creer;

	print $formfile->showdocuments('massfilesarea_stock', '', $filedir, $urlsource, 0, $delallowed, '', 1, 1, 0, 48, 1, $param, $title, '', '', '', null, $hidegeneratedfilelistifempty);
}

// End of page
llxFooter();
$db->close();
