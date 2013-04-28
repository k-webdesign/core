<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2012 Isotope eCommerce Workgroup
 *
 * @package    Isotope
 * @link       http://www.isotopeecommerce.com
 * @license    http://opensource.org/licenses/lgpl-3.0.html LGPL
 */

namespace Isotope\Model;

use Isotope\Isotope;
use Isotope\Interfaces\IsotopePayment;
use Isotope\Interfaces\IsotopeProduct;
use Isotope\Interfaces\IsotopeProductCollection;
use Isotope\Interfaces\IsotopeShipping;
use Isotope\Model\Config;
use Isotope\Model\Payment;
use Isotope\Model\ProductCollectionItem;
use Isotope\Model\Shipping;
use Isotope\Product\Standard as StandardProduct;


/**
 * Class ProductCollection
 *
 * Provide methods to handle Isotope product collections.
 * @copyright  Isotope eCommerce Workgroup 2009-2012
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @author     Fred Bliss <fred.bliss@intelligentspark.com>
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 */
abstract class ProductCollection extends \Model
{

    /**
     * Name of the current table
     * @var string
     */
    protected static $strTable = 'tl_iso_product_collection';

    /**
     * Name of the child table
     * @var string
     */
    protected static $ctable = 'tl_iso_product_collection_item';

    /**
     * Define if data should be threaded as "locked", eg. not apply discount rules to product prices
     * @var boolean
     */
    protected $blnLocked = false;

    /**
     * Cache product items in this collection
     * @var array
     */
    protected $arrItems;

    /**
     * Cache surcharges in this collection
     * @var array
     */
    protected $arrSurcharges;

    /**
     * Shipping method for this collection, if shipping is required
     * @var IsotopeShipping
     */
    protected $objShipping = false;

    /**
     * Payment method for this collection, if payment is required
     * @var IsotopePayment
     */
    protected $objPayment = false;

    /**
     * Configuration
     * @var array
     */
    protected $arrSettings = array();

    /**
     * Record has been modified
     * @var boolean
     */
    protected $blnModified = false;


    /**
     * Initialize the object
     */
    public function __construct(\Database\Result $objResult=null)
    {
        parent::__construct($objResult);

        if ($objResult !== null) {
            $this->arrSettings = deserialize($this->arrData['settings'], true);
        }

        $this->arrData['type'] = substr(get_called_class(), strrpos(get_called_class(), '\\')+1);

        // Do not use __destruct, because Database object might be destructed first (see http://github.com/contao/core/issues/2236)
        register_shutdown_function(array($this, 'saveDatabase'));
    }


    /**
     * Shutdown function to save data if modified
     */
    public function saveDatabase()
    {
        if (!$this->blnLocked) {
            $this->save();
        }
    }


    /**
     * Return data
     * @param string
     * @return mixed
     */
    public function __get($strKey)
    {
        // If there is a database field for that key, retrive from there
        if (array_key_exists($strKey, $this->arrData)) {

            return deserialize($this->arrData[$strKey]);
        }

        // Everything else is in arrSettings and serialized
        else {

            return deserialize($this->arrSettings[$strKey]);
        }
    }


    /**
     * Set data
     * @param string
     * @param mixed
     */
    public function __set($strKey, $varValue)
    {
        // If there is a database field for that key, we store it there
        if (array_key_exists($strKey, $this->arrData) || \Database::getInstance()->fieldExists($strKey, static::$strTable)) {
            $this->arrData[$strKey] = $varValue;
        }

        // Everything else goes into arrSettings and is serialized
        else {
            if ($varValue === null) {
                unset($this->arrSettings[$strKey]);
            } else {
                $this->arrSettings[$strKey] = $varValue;
            }
        }

        // Empty all caches
        $this->setModified(true);
    }

    /**
     * Check whether a property is set
     * @param string
     * @return boolean
     */
    public function __isset($strKey)
    {
        if (isset($this->arrData[$strKey]) || isset($this->arrSettings[$strKey])) {

            return true;
        }

        return false;
    }

    /**
     * Return true if collection is locked
     * @return bool
     */
    public function isLocked()
    {
        return $this->blnLocked;
    }

    /**
     * Return true if collection has no items
     * @return bool
     */
    public function isEmpty()
    {
        $arrItems = $this->getItems();

        return empty($arrItems);
    }

    /**
     * Return true if collection has been modified
     * @return bool
     */
    public function isModified()
    {
        return $this->blnModified;
    }

    /**
     * Mark collection as modified
     * @param bool
     */
    protected function setModified($varValue)
    {
        $this->blnModified = (bool) $varValue;
        $this->arrItems = null;
        $this->arrSurcharges = null;
        $this->arrCache = array();
        $this->arrRelated = array();
    }

    /**
     * Return payment method for this collection
     * @return IsotopePayment|null
     */
    public function getPaymentMethod()
    {
        if (false === $this->objPayment) {
            $this->objPayment = $this->getRelated('payment_id');
        }

        return $this->objPayment;
    }

    /**
     * Set payment method for this collection
     * @param IsotopePayment|null
     */
    public function setPaymentMethod(IsotopePayment $objPayment)
    {
        $this->objPayment = $objPayment;
        $this->payment_id = $objPayment->id;

        $this->setModified(true);
    }

    /**
     * Return surcharge for current payment method
     * @return ProductCollectionSurcharge|null
     */
    public function getPaymentSurcharge()
    {
        return ($this->hasPayment()) ? $this->getPaymentMethod()->getSurcharge($this) : null;
    }

    /**
     * Return boolean wether collection has payment
     * @return bool
     */
    public function hasPayment()
    {
        return (null === $this->getPaymentMethod()) ? false : true;
    }

    /**
     * Return boolean wether collection requires payment
     * @return bool
     */
    public function requiresPayment()
    {
        return $this->getTotal() > 0 ? true : false;
    }

    /**
     * Return shipping method for this collection
     * @return IsotopeShipping|null
     */
    public function getShippingMethod()
    {
        if (false === $this->objShipping) {
            $this->objShipping = $this->getRelated('shipping_id');
        }

        return $this->objShipping;
    }

    /**
     * Set shipping method for this collection
     * @param IsotopeShipping|null
     */
    public function setShippingMethod(IsotopeShipping $objShipping)
    {
        $this->objShipping = $objShipping;
        $this->shipping_id = $objShipping->id;

        $this->setModified(true);
    }

    /**
     * Return surcharge for current shipping method
     * @return ProductCollectionSurcharge|null
     */
    public function getShippingSurcharge()
    {
        return ($this->hasShipping()) ? $this->getShippingMethod()->getSurcharge($this) : null;
    }

    /**
     * Return boolean wether collection has shipping
     * @return bool
     */
    public function hasShipping()
    {
        return (null === $this->getShippingMethod()) ? false : true;
    }

    /**
     * Return boolean wether collection requires shipping
     * @return bool
     */
    public function requiresShipping()
    {
        if (!isset($this->arrCache['requiresShipping'])) {

            $this->arrCache['requiresShipping'] = false;
            $arrItems = $this->getItems();

            foreach ($arrItems as $objItem) {
                if ($objItem->hasProduct() && !$objItem->getProduct()->shipping_exempt) {
                    $this->arrCache['requiresShipping'] = true;
                }
            }
        }

        return $this->arrCache['requiresShipping'];
    }

    /**
     * Get billing address for collection
     * @return  Address|null
     */
    public function getBillingAddress()
    {
        return $this->getRelated('address1_id');
    }

    /**
     * Set billing address for collectino
     * @param   Address
     */
    public function setBillingAddress(Address $objAddress)
    {
        if (null === $objAddress || $objAddress->id < 1) {
            $this->address1_id = 0;
        } else {
            $this->address1_id = $objAddress->id;
        }

        $this->setModified(true);
    }

    /**
     * Get shipping address for collection
     * @return  Address|null
     */
    public function getShippingAddress()
    {
        if (!$this->hasPayment()) {
            return $this->getRelated('address1_id');
        }

        return $this->hasShipping() ? $this->getRelated('address2_id') : null;
    }

    /**
     * Set shipping address for collection
     * @param   Address
     */
    public function setShippingAddress(Address $objAddress)
    {
        if (null === $objAddress || $objAddress->id < 1) {
            $intId = 0;
        } else {
            $intId = $objAddress->id;
        }

        // If the collection does not have a payment, the shipping address is the primary address for the collection
        if (!$this->requiresPayment()) {
            $this->address1_id = $intId;
        } else {
            $this->address2_id = $intId;
        }

        $this->setModified(true);
    }

    /**
     * Return customer email address for the collection
     * @return  string
     */
    public function getEmailRecipient()
    {
        $strName = '';
        $strEmail = '';
        $objBillingAddress = $this->getBillingAddress();
        $objShippingAddress = $this->getShippingAddress();

        if ($objBillingAddress->email != '') {
            $strName = $objBillingAddress->firstname . ' ' . $objBillingAddress->lastname;
            $strEmail = $objBillingAddress->email;
        } elseif ($objShippingAddress->email != '') {
            $strName = $objShippingAddress->firstname . ' ' . $objShippingAddress->lastname;
            $strEmail = $objShippingAddress->email;
        } elseif ($this->member > 0 && ($objMember = \MemberModel::findByPk($this->member)) !== null && $objMember->email != '') {
            $strName = $objMember->firstname . ' ' . $objMember->lastname;
            $strEmail = $objMember->email;
        }

        if (trim($strName) != '') {
            $strEmail = sprintf('"%s" <%s>', \Isotope\Email::romanizeFriendlyName($strName), $strEmail);
        }

        // !HOOK: determine email recipient for collection
        if (isset($GLOBALS['ISO_HOOKS']['emailRecipientForCollection']) && is_array($GLOBALS['ISO_HOOKS']['emailRecipientForCollection'])) {
        	foreach ($GLOBALS['ISO_HOOKS']['emailRecipientForCollection'] as $callback) {
        		$objCallback = \System::importStatic($callback[0]);
        		$strEmail = $objCallback->$callback[1]($strEmail, $this);
        	}
        }

        return $strEmail;
    }

    /**
     * Return number of items in the collection
     * @return  int
     */
    public function countItems()
    {
        if (!isset($this->arrCache['countItems'])) {
            $this->arrCache['countItems'] = ProductCollectionItem::countBy('pid', $this->id);
        }

        return $this->arrCache['countItems'];
    }

    /**
     * Return summary of item quantity in collection
     * @return  int
     */
    public function sumItemsQuantity()
    {
        if (!isset($this->arrCache['sumItemsQuantity'])) {
            $this->arrCache['sumItemsQuantity'] = ProductCollectionItem::countBy('quantity', 'pid', $this->id);
        }

        return $this->arrCache['sumItemsQuantity'];
    }


    /**
     * Load settings from database field
     * @param object
     * @param string
     * @param string
     */
    public function setRow(array $arrData)
    {
        parent::setRow($arrData);
        $this->arrSettings = deserialize($arrData['settings'], true);
    }


    /**
     * Update database with latest product prices and store settings
     * @param boolean
     * @return integer
     */
    public function save($blnForceInsert=false)
    {
        if ($this->isLocked()) {
            return false;
        }

        if ($this->blnModified) {
            $this->arrData['tstamp'] = time();
            $this->arrData['settings'] = serialize($this->arrSettings);
        }

        $arrItems = $this->getItems();

        foreach ($arrItems as $objItem) {
            $objItem->price = $objItem->getProduct()->price;
            $objItem->tax_free_price = $objItem->getProduct()->tax_free_price;
            $objItem->save();
        }

        // !HOOK: additional functionality when saving a collection
        if (isset($GLOBALS['ISO_HOOKS']['saveCollection']) && is_array($GLOBALS['ISO_HOOKS']['saveCollection']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['saveCollection'] as $callback)
            {
                $objCallback = \System::importStatic($callback[0]);
                $objCallback->$callback[1]($this);
            }
        }

        if ($this->blnModified || $blnForceInsert) {
            parent::save($blnForceInsert);
        }

        return $this;
    }


    /**
     * Also delete child table records when dropping this collection
     * @return integer
     */
    public function delete()
    {
        // !HOOK: additional functionality when deleting a collection
        if (isset($GLOBALS['ISO_HOOKS']['deleteCollection']) && is_array($GLOBALS['ISO_HOOKS']['deleteCollection']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['deleteCollection'] as $callback)
            {
                $objCallback = \System::importStatic($callback[0]);
                $blnRemove = $objCallback->$callback[1]($this);

                if ($blnRemove === false)
                {
                    return 0;
                }
            }
        }

        $intAffectedRows = parent::delete();

        if ($intAffectedRows > 0) {
            \Database::getInstance()->prepare("DELETE FROM " . static::$ctable . " WHERE pid=?")->execute($this->id);
        }

        $this->arrCache = array();
        $this->arrItems = null;
        $this->arrSurcharges = null;

        return $intAffectedRows;
    }


    /**
     * Delete all products in the collection
     */
    public function purge()
    {
        $arrItems = $this->getItems();

        foreach ($arrItems as $objItem) {
            $this->deleteItem($objItem);
        }
    }


    public function getSubtotal()
    {
        if (!isset($this->arrCache['subtotal'])) {

            $fltAmount = 0;
            $arrItems = $this->getItems();

            foreach ($arrItems as $objItem) {

                $varPrice = $objItem->getPrice() * $objItem->quantity;

                if ($varPrice !== null) {
                    $fltAmount += $varPrice;
                }
            }

            $this->arrCache['subtotal'] = $fltAmount;
        }

        return $this->arrCache['subtotal'];
    }


    public function getTaxFreeSubtotal()
    {
        if (!isset($this->arrCache['taxFreeSubtotal'])) {

            $fltAmount = 0;
            $arrItems = $this->getItems();

            foreach ($arrItems as $objItem) {

                $varPrice = $objItem->getTaxFreePrice() * $objItem->quantity;

                if ($varPrice !== null) {
                    $fltAmount += $varPrice;
                }
            }

            $this->arrCache['taxFreeSubtotal'] = $fltAmount;
        }

        return $this->arrCache['taxFreeSubtotal'];
    }


    public function getTotal()
    {
        if (!isset($this->arrCache['total'])) {

            $fltAmount = $this->getSubtotal();
            $arrSurcharges = $this->getSurcharges();

            foreach ($arrSurcharges as $objSurcharge) {
                if ($objSurcharge->add !== false) {
                    $fltAmount += $objSurcharge->total_price;
                }
            }

            $this->arrCache['total'] = $fltAmount > 0 ? Isotope::roundPrice($fltAmount) : 0;
        }

        return $this->arrCache['total'];
    }


    public function getTaxFreeTotal()
    {
        if (!isset($this->arrCache['taxFreeTotal'])) {

            $fltAmount = $this->getTaxFreeSubtotal();
            $arrSurcharges = $this->getSurcharges();

            foreach ($arrSurcharges as $objSurcharge) {
                if ($objSurcharge->add !== false) {
                    $fltAmount += $objSurcharge->tax_free_total_price;
                }
            }

            $this->arrCache['taxFreeTotal'] = $fltAmount > 0 ? Isotope::roundPrice($fltAmount) : 0;
        }

        return $this->arrCache['taxFreeTotal'];
    }


    /**
     * Return the item with the latest timestamp (e.g. the latest added item)
     * @return ProductCollectionItem|null
     */
    public function getLatestItem()
    {
        if (!isset($this->arrCache['latestItem'])) {

            $latest = 0;
            $arrItems = $this->getItems();

            foreach ($arrItems as $objItem) {
                if ($objItem->tstamp > $latest) {
                    $this->arrCache['latestItem'] = $objItem;
                    $latest = $objItem->tstamp;
                }
            }
        }

        return $this->arrCache['latestItem'];
    }


    /**
     * Return all items in the collection
     * @param  bool
     * @return array
     */
    public function getItems($blnNoCache=false)
    {
        if (null === $this->arrItems || $blnNoCache) {
            $this->arrItems = array();

            if (($objItems = ProductCollectionItem::findByPid($this->id)) !== null) {
                while ($objItems->next()) {

                    $objItem = $objItems->current();

                    if ($this->isLocked()) {
                        $objItem->lock();
                    }

                    // Remove item from collection if it is no longer available
                    if (!$this->isLocked() && (!$objItem->hasProduct() || !$objItem->getProduct()->isAvailable())) {
                        $this->deleteItem($objItem);
                        continue;
                    }

                    $this->arrItems[$objItem->id] = $objItem;
                }
            }
        }

        return $this->arrItems;
    }


    /**
     * Search item for a specific product
     * @param  IsotopeProduct
     * @return ProductCollectionItem|null
     */
    public function getItemForProduct(IsotopeProduct $objProduct)
    {
        $arrType = $objProduct->getType();
        $strClass = $arrType['class'];

        $objItem = ProductCollectionItem::findBy(array('pid=?', 'type=?', 'product_id=?', 'options=?'), array($this->id, $strClass, $objProduct->id, serialize($objProduct->getOptions())));

        return (null === $objItem) ? null : $this->arrItems[$objItem->id];
    }


    /**
     * Check if a given product is already in the collection
     * @param  IsotopeProduct
     * @param  bool
     * @return bool
     */
    public function hasProduct(IsotopeProduct $objProduct, $blnIdentical=true)
    {
        // !HOOK: additional functionality to check if product is in collection
        if (isset($GLOBALS['ISO_HOOKS']['hasProductInCollection']) && is_array($GLOBALS['ISO_HOOKS']['hasProductInCollection']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['hasProductInCollection'] as $callback)
            {
                $objCallback = \System::importStatic($callback[0]);
                $intQuantity = $objCallback->$callback[1]($objProduct, $intQuantity, $this);
            }
        }

        if (true === $blnIdentical) {

            $objItem = $this->getItemForProduct($objProduct);

            return (null === $objItem) ? false : true;

        } else {

            $intId = $objProduct->pid ?: $objProduct->id;

            foreach ($this->getItems() as $objItem) {

                if ($objItem->getProduct()->id == $intId || $objItem->getProduct()->pid == $intId) {
                    return true;
                }
            }

            return false;
        }
    }


    /**
     * Add a product to the collection
     * @param object The product object
     * @param integer How many products to add
     * @return integer ID of database record added/updated
     */
    public function addProduct(IsotopeProduct $objProduct, $intQuantity)
    {
        // !HOOK: additional functionality when adding product to collection
        if (isset($GLOBALS['ISO_HOOKS']['addProductToCollection']) && is_array($GLOBALS['ISO_HOOKS']['addProductToCollection'])) {
            foreach ($GLOBALS['ISO_HOOKS']['addProductToCollection'] as $callback) {
                $objCallback = \System::importStatic($callback[0]);
                $intQuantity = $objCallback->$callback[1]($objProduct, $intQuantity, $this);
            }
        }

        if ($intQuantity == 0) {
            return false;
        }

        $time = time();
        $this->setModified(true);

        // Make sure collection is in DB before adding product
        if (!$this->blnRecordExists) {
            $this->save();
        }

        $objItem = $this->getItemForProduct($objProduct);

        if (null !== $objItem)
        {
            // Set product quantity so we can determine the correct minimum price
            $objProduct->quantity_requested = $objItem->quantity;

            if (($objItem->quantity + $intQuantity) < $objProduct->minimum_quantity) {
                $_SESSION['ISO_INFO'][] = sprintf($GLOBALS['TL_LANG']['ERR']['productMinimumQuantity'], $objProduct->name, $objProduct->minimum_quantity);
                $intQuantity = $objProduct->minimum_quantity - $objItem->quantity;
            }

            $objItem->increaseQuantityBy($intQuantity);

            return $objItem;
        }
        else
        {
            if ($intQuantity < $objProduct->minimum_quantity) {
                $_SESSION['ISO_INFO'][] = sprintf($GLOBALS['TL_LANG']['ERR']['productMinimumQuantity'], $objProduct->name, $objProduct->minimum_quantity);
                $intQuantity = $objProduct->minimum_quantity;
            }

            $objItem = new ProductCollectionItem();
            $objItem->pid               = $this->id;
            $objItem->tstamp            = $time;
            $objItem->type              = substr(get_class($objProduct), strrpos(get_class($objProduct), '\\')+1);
            $objItem->product_id        = (int) $objProduct->id;
            $objItem->sku               = (string) $objProduct->sku;
            $objItem->name              = (string) $objProduct->name;
            $objItem->options           = $objProduct->getOptions();
            $objItem->quantity          = (int) $intQuantity;
            $objItem->price             = (float) $objProduct->price;
            $objItem->tax_free_price    = (float) $objProduct->tax_free_price;
            $objItem->href_reader       = $objProduct->href_reader;

            $objItem->save();

            // Add the new item to our cache
            $this->arrItems[$objItem->id] = $objItem;

            return $objItem;
        }
    }


    /**
     * Update a product in the collection
     * @param object The product object
     * @param array The property(ies) to adjust
     * @return bool
     */
    public function updateProduct(IsotopeProduct $objProduct, $arrSet)
    {
        if (($objItem = $this->getItemForProduct($objProduct)) === null) {
            return false;
        }

        // !HOOK: additional functionality when updating a product in the collection
        if (isset($GLOBALS['ISO_HOOKS']['updateProductInCollection']) && is_array($GLOBALS['ISO_HOOKS']['updateProductInCollection'])) {
            foreach ($GLOBALS['ISO_HOOKS']['updateProductInCollection'] as $callback) {
                $objCallback = \System::importStatic($callback[0]);
                $arrSet = $objCallback->$callback[1]($objProduct, $arrSet, $this);

                if (is_array($arrSet) && empty($arrSet)) {
                    return false;
                }
            }
        }

        // Quantity set to 0, delete product
        if (isset($arrSet['quantity']) && $arrSet['quantity'] == 0) {
            return $this->deleteProduct($objProduct);
        }

        if (isset($arrSet['quantity'])) {

            // Set product quantity so we can determine the correct minimum price
            $objProduct->quantity_requested = $arrSet['quantity'];

            if ($arrSet['quantity'] < $objProduct->minimum_quantity) {
                $_SESSION['ISO_INFO'][] = sprintf($GLOBALS['TL_LANG']['ERR']['productMinimumQuantity'], $objProduct->name, $objProduct->minimum_quantity);
                $arrSet['quantity'] = $objProduct->minimum_quantity;
            }
        }

        // Modify timestamp when updating a product
        $arrSet['tstamp'] = time();

        foreach ($arrSet as $k => $v) {
            $objItem->$k = $v;
        }

        if ($objItem->save() > 0) {
            $this->setModified(true);

            return true;
        }

        return false;
    }


    /**
     * Remove item from collection
     * @param   ProductCollectionItem
     * @return  bool
     */
    public function deleteItem(ProductCollectionItem $objItem)
    {
        return $this->deleteItemById($objItem->id);
    }

    /**
     * Remove item with given ID from collection
     * @param   int
     * @return  bool
     */
    public function deleteItemById($intId)
    {
        $arrItems = $this->getItems();

        if (!isset($arrItems[$intId])) {
            return false;
        }

        // !HOOK: additional functionality when a product is removed from the collection
        if (isset($GLOBALS['ISO_HOOKS']['deleteFromCollection']) && is_array($GLOBALS['ISO_HOOKS']['deleteFromCollection'])) {
            foreach ($GLOBALS['ISO_HOOKS']['deleteFromCollection'] as $callback) {
                $objCallback = \System::importStatic($callback[0]);
                $blnRemove = $objCallback->$callback[1]($arrItems[$intId], $this);

                if ($blnRemove === false) {
                    return false;
                }
            }
        }

        $arrItems[$intId]->delete();
        $this->setModified(true);

        return true;
    }


    public function getSurcharges()
    {
        if (null === $this->arrSurcharges) {
            $this->arrSurcharges = $this->isLocked() ? ProductCollectionSurcharge::findBy('pid', $this->id) : ProductCollectionSurcharge::findForCollection($this);
        }

        return $this->arrSurcharges;
    }


    /**
     * Initialize a new collection from given collection
     * @param   IsotopeProductCollection
     * @return  IsotopeProductCollection
     */
    public function setSourceCollection(IsotopeProductCollection $objSource)
    {
        global $objPage;

        $objConfig = Config::findByPk($objSource->config_id);

        if (null === $objConfig) {
            $objConfig = Isotope::getConfig();
        }

        // Store in arrData, otherwise each call to __set would trigger setModified(true)
        $this->arrData['source_collection_id'] = $objSource->id;
        $this->arrData['config_id']            = $objSource->config_id;
        $this->arrData['store_id']             = $objConfig->store_id;
        $this->arrData['member']               = $objSource->member;
        $this->arrData['language']             = $GLOBALS['TL_LANGUAGE'];
        $this->arrData['pageId']               = (int) $objPage->id;
        $this->arrData['currency']             = $objConfig->currency;

        // Do not change the unique ID
        if ($this->arrData['uniqid'] == '') {
            $this->arrData['uniqid'] = uniqid(Isotope::getInstance()->call('replaceInsertTags', $objConfig->orderPrefix), true);
        }

        $this->setModified(true);
    }


    /**
     * Copy product collection items from another collection to this one (e.g. Cart to Order)
     * @param object
     * @param boolean
     * @return array
     */
    public function copyItemsFrom(IsotopeProductCollection $objSource)
    {
        if (!$this->blnRecordExists) {
            $this->save(true);
        }

        // Make sure database table has the latest prices
        $objSource->save();

        $time = time();
        $arrIds = array();
        $arrOldItems = $objSource->getItems();

        foreach ($arrOldItems as $objOldItem) {
            $objNewItems = \Database::getInstance()->prepare("SELECT * FROM " . static::$ctable . " WHERE pid={$this->id} AND product_id={$objOldItem->product_id} AND options=?")->execute($objOldItem->options);

            // !HOOK: additional functionality when copying product to collection
            if (isset($GLOBALS['ISO_HOOKS']['copyCollectionItem']) && is_array($GLOBALS['ISO_HOOKS']['copyCollectionItem'])) {
                foreach ($GLOBALS['ISO_HOOKS']['copyCollectionItem'] as $callback) {
                    $objCallback = \System::importStatic($callback[0]);

                    if ($objCallback->$callback[1]($objOldItem, $objSource, $this) === false) {
                        continue;
                    }
                }
            }

            if ($objOldItem->hasProduct() && $this->hasProduct($objOldItem->getProduct())) {

                $objNewItem = $this->getItemForProduct($objOldItem->getProduct());
                $objNewItem->increaseQuantityBy($objOldItem->quantity);

            } else {

                $objNewItem = clone $objOldItem;
                $objNewItem->pid = $this->id;
                $objNewItem->tstamp = $time;
                $objNewItem->save(true);
            }

            $arrIds[$objOldItem->id] = $objNewItem->id;
        }

        if (!empty($arrIds)) {
            $this->setModified(true);
        }

        // !HOOK: additional functionality when adding product to collection
        if (isset($GLOBALS['ISO_HOOKS']['copiedCollectionItems']) && is_array($GLOBALS['ISO_HOOKS']['copiedCollectionItems'])) {
            foreach ($GLOBALS['ISO_HOOKS']['copiedCollectionItems'] as $callback) {
                $objCallback = \System::importStatic($callback[0]);
                $objCallback->$callback[1]($objSource, $this, $arrIds);
            }
        }

        return $arrIds;
    }


    /**
     * Calculate the weight of all products in the cart in a specific weight unit
     * @param string
     * @return mixed
     */
    public function getShippingWeight($unit)
    {
        $arrWeights = array();
        $arrItems = $this->getItems();

        foreach ($arrItems as $objItem)
        {
            if (!$objItem->hasProduct()) {
                continue;
            }

            $arrWeight = deserialize($objItem->getProduct()->shipping_weight, true);
            $arrWeight['value'] = $objItem->getProduct()->quantity_requested * floatval($arrWeight['value']);

            $arrWeights[] = $arrWeight;
        }

        return Isotope::calculateWeight($arrWeights, $unit);
    }


    /**
     * Generate the collection using a template.
     * @param string
     * @param boolean
     * @return string
     */
    public function generate($strTemplate, $blnResetConfig=true)
    {
        // Set global config to this collection (if available)
        if ($this->config_id > 0)
        {
            Isotope::overrideConfig($this->config_id);
        }

        // Load language files for the order
        if ($this->language != '')
        {
            \System::loadLanguageFile('default', $this->language);
        }

        $objTemplate = new \Isotope\Template($this->strTemplate);
        $objTemplate->setData($this->arrData);
        $objTemplate->logoImage = '';

        if (Isotope::getConfig()->invoiceLogo != '' && is_file(TL_ROOT . '/' . Isotope::getConfig()->invoiceLogo))
        {
            $objTemplate->logoImage = '<img src="' . TL_ROOT . '/' . Isotope::getConfig()->invoiceLogo . '" alt="" />';
        }

        $objTemplate->invoiceTitle = $GLOBALS['TL_LANG']['MSC']['iso_invoice_title'] . ' ' . $this->order_id . ' – ' . date($GLOBALS['TL_CONFIG']['datimFormat'], $this->date);

        $arrItems = array();
        $objBillingAddress = $this->getBillingAddress();
        $objShippingAddress = $this->getShippingAddress();

        foreach ($objOrder->getItems() as $objItem)
        {
            $objProduct = $objItem->getProduct();

            $arrItems[] = array
            (
                'raw'               => ($objItem->hasProduct() ? $objProduct->getData() : $objItem->row()),
                'sku'               => $objItem->getSku(),
                'name'              => $objItem->getName(),
                'options'           => Isotope::formatOptions($objItem->getOptions()),
                'quantity'          => $objItem->quantity,
                'price'             => Isotope::formatPriceWithCurrency($objItem->getPrice()),
                'tax_free_price'    => Isotope::formatPriceWithCurrency($objItem->getTaxFreePrice()),
                'total'             => Isotope::formatPriceWithCurrency($objItem->getPrice() * $objItem->quantity),
                'tax_free_total'    => Isotope::formatPriceWithCurrency($objItem->getTaxFreePrice() * $objItem->quantity),
                'href'              => ($this->jumpTo ? $this->generateFrontendUrl($arrPage, ($GLOBALS['TL_CONFIG']['useAutoItem'] ? '/' : '/product/') . $objProduct->alias) : ''),
                'tax_id'            => $objProduct->tax_id,
            );
        }

        $objTemplate->collection = $this;
        $objTemplate->config = Isotope::getConfig()->getData();
        $objTemplate->info = deserialize($this->checkout_info);
        $objTemplate->items = $arrItems;
        $objTemplate->raw = $this->arrData;
        $objTemplate->date = \System::parseDate($GLOBALS['TL_CONFIG']['dateFormat'], $this->date);
        $objTemplate->time = \System::parseDate($GLOBALS['TL_CONFIG']['timeFormat'], $this->date);
        $objTemplate->datim = \System::parseDate($GLOBALS['TL_CONFIG']['datimFormat'], $this->date);
        $objTemplate->datimLabel = $GLOBALS['TL_LANG']['MSC']['datimLabel'];
        $objTemplate->subTotalPrice = Isotope::formatPriceWithCurrency($this->getSubtotal());
        $objTemplate->grandTotal = Isotope::formatPriceWithCurrency($this->getTotal());
        $objTemplate->subTotalLabel = $GLOBALS['TL_LANG']['MSC']['subTotalLabel'];
        $objTemplate->grandTotalLabel = $GLOBALS['TL_LANG']['MSC']['grandTotalLabel'];

        $objTemplate->surcharges = \Isotope\Frontend::formatSurcharges($this->getSurcharges());
        $objTemplate->billing_label = $GLOBALS['TL_LANG']['MSC']['billing_address'];
        $objTemplate->billing_address = (null === $objBillingAddress) ? '' : $objBillingAddress->generateText(Isotope::getConfig()->billing_fields);

        if ($this->shipping_method == '' || null === $objShippingAddress || null === $objBillingAddress || $objShippingAddress->id == $objBillingAddress->id) {
            $objTemplate->has_shipping = false;
            $objTemplate->billing_label = $GLOBALS['TL_LANG']['MSC']['billing_shipping_address'];
        } else {
            $objTemplate->has_shipping = true;
            $objTemplate->shipping_label = $GLOBALS['TL_LANG']['MSC']['shipping_address'];
            $objTemplate->shipping_address = $objShippingAddress->generateText(Isotope::getConfig()->shipping_fields);
        }

        // !HOOK: allow overriding of the template
        if (isset($GLOBALS['ISO_HOOKS']['generateCollection']) && is_array($GLOBALS['ISO_HOOKS']['generateCollection']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['generateCollection'] as $callback)
            {
                $objCallback = \System::importStatic($callback[0]);
                $objCallback->$callback[1]($objTemplate, $arrItems, $this);
            }
        }

        // Set config back to default
        if ($blnResetConfig)
        {
            Isotope::resetConfig();
            \System::loadLanguageFile('default', $GLOBALS['TL_LANGUAGE']);
        }

        return $strArticle;
    }


    /**
     * Make sure we only return results of the given model type
     */
    protected static function find(array $arrOptions)
    {
        // Convert to array if necessary
        $arrOptions['value'] = (array) $arrOptions['value'];
        if (!is_array($arrOptions['column']))
        {
            $arrOptions['column'] = array($arrOptions['column'].'=?');
        }

        $arrOptions['column'][] = 'type=?';
        $arrOptions['value'][] = substr(get_called_class(), strrpos(get_called_class(), '\\')+1);

        return parent::find($arrOptions);
    }
}
