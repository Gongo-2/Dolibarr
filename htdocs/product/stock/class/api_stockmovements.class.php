<?php
/* Copyright (C) 2016   Laurent Destailleur     <eldy@users.sourceforge.net>
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

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';


/**
 * API class for stock movements
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class StockMovements extends DolibarrApi
{
	/**
	 * @var array   $FIELDS     Mandatory fields, checked when create and update object
	 */
	public static $FIELDS = array(
		'product_id',
		'warehouse_id',
		'qty'
	);

	/**
	 * @var MouvementStock $stockmovement {@type MouvementStock}
	 */
	public $stockmovement;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db, $conf;
		$this->db = $db;
		$this->stockmovement = new MouvementStock($this->db);
	}

	/**
	 * Get properties of a stock movement object
	 *
	 * Return an array with stock movement informations
	 *
	 * @param 	int 	$id ID of movement
	 * @return 	array|mixed data without useless information
	 *
	 * @throws 	RestException
	 */
	/*
	public function get($id)
	{
		if(! DolibarrApiAccess::$user->rights->stock->lire) {
			throw new RestException(401);
		}

		$result = $this->stockmovement->fetch($id);
		if( ! $result ) {
			throw new RestException(404, 'warehouse not found');
		}

		if( ! DolibarrApi::_checkAccessToResource('warehouse',$this->stockmovement->id)) {
			throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

		return $this->_cleanObjectDatas($this->stockmovement);
	}*/

	/**
	 * Get a list of stock movement
	 *
	 * @param string	$sortfield	Sort field
	 * @param string	$sortorder	Sort order
	 * @param int		$limit		Limit for list
	 * @param int		$page		Page number
	 * @param string    $sqlfilters Other criteria to filter answers separated by a comma. Syntax example "(t.product_id:=:1) and (t.date_creation:<:'20160101')"
	 * @return array                Array of warehouse objects
	 *
	 * @throws RestException
	 */
	public function index($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 100, $page = 0, $sqlfilters = '')
	{
		global $conf;

		$obj_ret = array();

		if (!DolibarrApiAccess::$user->rights->stock->lire) {
			throw new RestException(401);
		}

		$sql = "SELECT t.rowid";
		$sql .= " FROM ".$this->db->prefix()."stock_mouvement as t";
		//$sql.= ' WHERE t.entity IN ('.getEntity('stock').')';
		$sql .= ' WHERE 1 = 1';
		// Add sql filters
		if ($sqlfilters) {
			$errormessage = '';
			if (!DolibarrApi::_checkFilters($sqlfilters, $errormessage)) {
				throw new RestException(503, 'Error when validating parameter sqlfilters -> '.$errormessage);
			}
			$regexstring = '\(([^:\'\(\)]+:[^:\'\(\)]+:[^\(\)]+)\)';
			$sql .= " AND (".preg_replace_callback('/'.$regexstring.'/', 'DolibarrApi::_forge_criteria_callback', $sqlfilters).")";
		}

		$sql .= $this->db->order($sortfield, $sortorder);
		if ($limit) {
			if ($page < 0) {
				$page = 0;
			}
			$offset = $limit * $page;

			$sql .= $this->db->plimit($limit + 1, $offset);
		}

		$result = $this->db->query($sql);
		if ($result) {
			$i = 0;
			$num = $this->db->num_rows($result);
			$min = min($num, ($limit <= 0 ? $num : $limit));
			while ($i < $min) {
				$obj = $this->db->fetch_object($result);
				$stockmovement_static = new MouvementStock($this->db);
				if ($stockmovement_static->fetch($obj->rowid)) {
					$obj_ret[] = $this->_cleanObjectDatas($stockmovement_static);
				}
				$i++;
			}
		} else {
			throw new RestException(503, 'Error when retrieve stock movement list : '.$this->db->lasterror());
		}
		if (!count($obj_ret)) {
			throw new RestException(404, 'No stock movement found');
		}
		return $obj_ret;
	}

	/**
	 * Create stock movement object.
	 * You can use the following message to test this RES API:
	 * { "product_id": 1, "warehouse_id": 1, "qty": 1, "lot": "", "movementcode": "INV123", "movementlabel": "Inventory 123", "price": 0 }
	 * $price Can be set to update AWP (Average Weighted Price) when you make a stock increase
	 * $dlc Eat-by date. Will be used if lot does not exists yet and will be created.
	 * $dluo Sell-by date. Will be used if lot does not exists yet and will be created.
		 *
	 * @param int $product_id Id product id {@min 1} {@from body} {@required true}
	 * @param int $warehouse_id Id warehouse {@min 1} {@from body} {@required true}
	 * @param float $qty Qty to add (Use negative value for a stock decrease) {@from body} {@required true}
	 * @param int $type Optionally specify the type of movement. 0=input (stock increase by a stock transfer), 1=output (stock decrease by a stock transfer), 2=output (stock decrease), 3=input (stock increase). {@from body} {@type int}
	 * @param string $lot Lot {@from body}
	 * @param string $movementcode Movement code {@from body}
	 * @param string $movementlabel Movement label {@from body}
	 * @param string $price To update AWP (Average Weighted Price) when you make a stock increase (qty must be higher then 0). {@from body}
	 * @param string $datem Date of movement {@from body} {@type date}
	 * @param string $dlc Eat-by date. {@from body} {@type date}
	 * @param string $dluo Sell-by date. {@from body} {@type date}
	 * @param string $origin_type   Origin type (Element of source object, like 'project', 'inventory', ...)
	 * @param string $origin_id     Origin id (Id of source object)
	 *
	 * @return  int                         ID of stock movement
	 * @throws RestException
	 */
	public function post($product_id, $warehouse_id, $qty, $type = 2, $lot = '', $movementcode = '', $movementlabel = '', $price = '', $datem = '', $dlc = '', $dluo = '', $origin_type = '', $origin_id = 0)
	{
		if (!DolibarrApiAccess::$user->rights->stock->creer) {
			throw new RestException(401);
		}

		if ($qty == 0) {
			throw new RestException(503, "Making a stock movement with a quantity of 0 is not possible");
		}

		// Type increase or decrease
		if ($type == 1 && $qty >= 0) {
			$type = 0;
		}
		if ($type == 2 && $qty >= 0) {
			$type = 3;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
		$eatBy = empty($dluo) ? '' : dol_stringtotime($dluo);
		$sellBy = empty($dlc) ? '' : dol_stringtotime($dlc);
		$dateMvt = empty($datem) ? '' : dol_stringtotime($datem);

		$this->stockmovement->setOrigin($origin_type, $origin_id);
		if ($this->stockmovement->_create(DolibarrApiAccess::$user, $product_id, $warehouse_id, $qty, $type, $price, $movementlabel, $movementcode, $dateMvt, $eatBy, $sellBy, $lot) <= 0) {
			$errormessage = $this->stockmovement->error;
			if (empty($errormessage)) {
				$errormessage = join(',', $this->stockmovement->errors);
			}
			throw new RestException(503, 'Error when create stock movement : '.$errormessage);
		}

		return $this->stockmovement->id;
	}

	/**
	 * Update stock movement
	 *
	 * @param int   $id             Id of warehouse to update
	 * @param array $request_data   Datas
	 * @return int
	 */
	/*
	public function put($id, $request_data = null)
	{
		if(! DolibarrApiAccess::$user->rights->stock->creer) {
			throw new RestException(401);
		}

		$result = $this->stockmovement->fetch($id);
		if( ! $result ) {
			throw new RestException(404, 'stock movement not found');
		}

		if( ! DolibarrApi::_checkAccessToResource('stock',$this->stockmovement->id)) {
			throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

		foreach($request_data as $field => $value) {
			if ($field == 'id') continue;
			$this->stockmovement->$field = $value;
		}

		if($this->stockmovement->update($id, DolibarrApiAccess::$user))
			return $this->get ($id);

		return false;
	}*/

	/**
	 * Delete stock movement
	 *
	 * @param int $id   Stock movement ID
	 * @return array
	 */
	/*
	public function delete($id)
	{
		if(! DolibarrApiAccess::$user->rights->stock->supprimer) {
			throw new RestException(401);
		}
		$result = $this->stockmovement->fetch($id);
		if( ! $result ) {
			throw new RestException(404, 'stock movement not found');
		}

		if( ! DolibarrApi::_checkAccessToResource('stock',$this->stockmovement->id)) {
			throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

		if (! $this->stockmovement->delete(DolibarrApiAccess::$user)) {
			throw new RestException(401,'error when delete stock movement');
		}

		return array(
			'success' => array(
				'code' => 200,
				'message' => 'Warehouse deleted'
			)
		);
	}*/



	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 * Clean sensible object datas
	 *
	 * @param   MouvementStock $object     Object to clean
	 * @return  Object                     Object with cleaned properties
	 */
	protected function _cleanObjectDatas($object)
	{
		// phpcs:enable
		$object = parent::_cleanObjectDatas($object);

		// Remove useless data
		unset($object->civility_id);
		unset($object->firstname);
		unset($object->lastname);
		unset($object->name);
		unset($object->location_incoterms);
		unset($object->label_incoterms);
		unset($object->fk_incoterms);
		unset($object->lines);
		unset($object->total_ht);
		unset($object->total_ttc);
		unset($object->total_tva);
		unset($object->total_localtax1);
		unset($object->total_localtax2);
		unset($object->note);
		unset($object->note_private);
		unset($object->note_public);
		unset($object->shipping_method_id);
		unset($object->fk_account);
		unset($object->model_pdf);
		unset($object->fk_delivery_address);
		unset($object->cond_reglement);
		unset($object->cond_reglement_id);
		unset($object->mode_reglement_id);
		unset($object->barcode_type_coder);
		unset($object->barcode_type_label);
		unset($object->barcode_type_code);
		unset($object->barcode_type);
		unset($object->country_code);
		unset($object->country_id);
		unset($object->country);
		unset($object->thirdparty);
		unset($object->contact);
		unset($object->contact_id);
		unset($object->user);
		unset($object->fk_project);
		unset($object->project);
		unset($object->canvas);

		//unset($object->eatby);        Filled correctly in read mode
		//unset($object->sellby);       Filled correctly in read mode

		return $object;
	}

	/**
	 * Validate fields before create or update object
	 *
	 * @param array|null    $data    Data to validate
	 * @return array
	 *
	 * @throws RestException
	 */
	private function _validate($data)
	{
		$stockmovement = array();
		foreach (self::$FIELDS as $field) {
			if (!isset($data[$field])) {
				throw new RestException(400, "$field field missing");
			}
			$stockmovement[$field] = $data[$field];
		}
		return $stockmovement;
	}
}
