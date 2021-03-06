<?php

class Rejoiner_Acr_Block_Base extends Mage_Core_Block_Template
{
    const XML_PATH_REJOINER_TRACK_PRICE_WITH_TAX = 'checkout/rejoiner_acr/price_with_tax';

    const REJOINER_PARAM_NAMESPACE = 'rejoiner_';

    /**
     * @return array
     */
    public function getCartItems()
    {
        $items = array();
        if ($quote = $this->_getQuote()) {
            $displayPriceWithTax = $this->getTrackPriceWithTax();
            $mediaUrl            = Mage::getBaseUrl('media');
            $quoteItems          = $quote->getAllItems();
            /** @var Rejoiner_Acr_Helper_Data $rejoinerHelper */
            $rejoinerHelper = Mage::helper('rejoiner_acr');
            $parentToChild  = array();
            $categories     = array();
            /** @var Mage_Sales_Model_Quote_Item $item */
            foreach ($quoteItems as $item) {
                /** @var Mage_Sales_Model_Quote_Item $parent */
                if ($parent = $item->getParentItem()) {
                    if ($parent->getProductType() == Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE) {
                        $parentToChild[$parent->getId()] = $item;
                    }
                }
                $categories = array_merge($categories, $item->getProduct()->getCategoryIds());
            }

            /** @var Mage_Catalog_Model_Resource_Category_Collection $categoryCollection */
            $categoryCollection = Mage::getModel('catalog/category')->getCollection();
            $categoryCollection
                ->addAttributeToSelect('name')
                ->addFieldToFilter('entity_id', array('in' => array_unique($categories)));
            $imageHelper = Mage::helper('catalog/image');

            foreach ($quoteItems as $item) {
                if ($item->getParentItem()) {
                    continue;
                }
                $product = $item->getProduct();
                // Collection is loaded only once, so it is ok to do $categoryCollection->getItems() inside the loop
                // From the other hand we won't ever get here if not needed
                $productCategories = $rejoinerHelper->getProductCategories($product, $categoryCollection->getItems());
                $thumbnail = 'no_selection';
                // try finding thumbnail in the simple item
                if ($item->getProductType() == Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE) {
                    /** @var Mage_Sales_Model_Quote_Item $simpleItem */
                    $simpleItem = isset($parentToChild[$item->getId()]) ? $parentToChild[$item->getId()] : null;
                    if ($simpleItem) {
                        $simpleProduct = $simpleItem->getProduct();
                        if ($simpleProduct->getData('thumbnail')) {
                            $thumbnail = $simpleProduct->getData('thumbnail');
                        }
                    }
                }

                if (($thumbnail === 'no_selection')
                    && $product->getData('thumbnail')
                    && ($product->getData('thumbnail') != 'no_selection')
                ) {
                    $thumbnail = $product->getData('thumbnail');
                }

                $io = new Varien_Io_File();
                if (!$io->fileExists(Mage::getBaseDir('media') . '/catalog/product' . $thumbnail)) {
                    $thumbnail = 'no_selection';
                }
                // use placeholder image if nor simple nor configurable products does not have images
                if ($thumbnail == 'no_selection') {
                    $imageHelper->init($product, 'thumbnail');
                    $image = Mage::getDesign()->getSkinUrl($imageHelper->getPlaceholder());
                } elseif($imagePath = $rejoinerHelper->resizeImage($thumbnail)) {
                    $image = str_replace(Mage::getBaseDir('media') . '/', $mediaUrl, $imagePath);
                } else {
                    $image = $mediaUrl . 'catalog/product' . $thumbnail;
                }

                if ($displayPriceWithTax) {
                    $prodPrice = $item->getPriceInclTax();
                    $rowTotal  = $item->getRowTotalInclTax();
                } else {
                    $prodPrice = $item->getBaseCalculationPrice();
                    $rowTotal  = $item->getBaseRowTotal();
                }

                $stockProduct = isset($simpleProduct) ? $simpleProduct : $product;
                $stocklevel = $rejoinerHelper->getProductStockLevel($stockProduct);

                $newItem = array(
                    'name'        => $item->getName(),
                    'image_url'   => $image,
                    'price'       => $this->_convertPriceToCents($prodPrice),
                    'product_id'  => (string) $item->getSku(),
                    'product_url' => (string) $item->getProduct()->getProductUrl(),
                    'item_qty'    => $item->getQty(),
                    'qty_price'   => $this->_convertPriceToCents($rowTotal),
                    'category'    => $productCategories,
                    'stock'       => $stocklevel
                );
                $items[] = $newItem;
            }
        }
        return $items;
    }

    /**
     * @return string|null
     */
    public function getSessionMetadata()
    {
        $rejoinerHelper = Mage::helper('rejoiner_acr');

        $sessionMetadata = array();

        if ($rejoinerHelper->getBrowseCouponsEnabled()) {
            $couponCodeParam = $rejoinerHelper->getCouponParam('browse');
            $sessionMetadata[$couponCodeParam] = (string) $this->_generateCouponCode('browse');
            $sessionMetadata = array_merge($sessionMetadata, $this->_getExtraCodes('browse'));
        }

        if (empty($sessionMetadata)) {
            return null;
        }

        return str_replace('\\/', '/', json_encode($sessionMetadata));
    }

    /**
     * @param string $couponType
     * @return array
     */
    protected function _getExtraCodes($couponType)
    {
        $rejoinerHelper = Mage::helper('rejoiner_acr');
        $extraCodes = $rejoinerHelper->returnExtraCodes($couponType);

        foreach ($extraCodes as $param => $salesrule) {
            $extraCodes[$param] = $this->_generateCouponCode($couponType, $salesrule, $param);
        }

        return $extraCodes;
    }

    protected function _namespaceParam($param)
    {
        $namespace = self::REJOINER_PARAM_NAMESPACE;
        return $namespace . $param;
    }

    /**
     * @param string $couponType
     * @param string $param
     * @return string
     */
    protected function _getCouponCode($couponType, $param = 'promo')
    {
        switch ($couponType) {
            case 'cart':
                /** @var Mage_Checkout_Helper_Cart $cartHelper */
                $cartHelper = Mage::helper('checkout/cart');
                /** @var Mage_Sales_Model_Quote $quote */
                $quote = $cartHelper->getCart()->getQuote();
                $codes = unserialize($quote->getPromo());
                $couponCode = isset($codes[$param]) ? $codes[$param] : '';
                break;
            case 'browse':
                /** @var Mage_Core_Model_Session $session */
                $session = Mage::getSingleton('core/session');
                $couponCode = $session->getData($this->_namespaceParam($param));
                break;
            default:
                $couponCode = '';
        }

        return $couponCode;
    }

    /**
     * @param string $couponType
     * @param string $couponCode
     * @param string $param
     * @return
     */
    protected function _setCouponCode($couponType, $couponCode, $param = 'promo')
    {
        $couponCode = strlen($couponCode) ? $couponCode : '';

        switch ($couponType) {
            case 'cart':
                /** @var Mage_Checkout_Helper_Cart $cartHelper */
                $cartHelper = Mage::helper('checkout/cart');
                $cartQuote = $cartHelper->getCart()->getQuote();

                $codes = unserialize($cartQuote->getPromo());

                $codes[$param] = $couponCode;

                return $cartQuote->setPromo(serialize($codes))->save();
            case 'browse':
                /** @var Mage_Core_Model_Session $session */
                $session = Mage::getSingleton('core/session');
                return $session->setData($this->_namespaceParam($param), $couponCode);
        }
    }

    /**
     * @param string $couponType
     * @return string
     */
    protected function _generateCouponCode($couponType, $ruleId = null, $param = 'promo')
    {
        $couponCode = $this->_getCouponCode($couponType, $param);

        $rejoinerHelper = Mage::helper('rejoiner_acr');
        if ($ruleId == null) {
            $ruleId = $rejoinerHelper->getCouponRuleId($couponType);
        }

        /** @var Mage_SalesRule_Model_Rule $ruleItem */
        $ruleItem   = Mage::getModel('salesrule/rule')
            ->getCollection()
            ->addFieldToFilter('rule_id', array('eq' => $ruleId))
            ->getFirstItem();

        if ($ruleItem->getUseAutoGeneration() && !$couponCode) {
            /** @var Mage_SalesRule_Model_Coupon_Codegenerator $codeGenerator */
            $codeGenerator = Mage::getModel('salesrule/coupon_codegenerator');
            $couponCode = $codeGenerator->generateCode();

            /** @var Mage_SalesRule_Model_Coupon $coupon */
            $coupon = Mage::getModel('salesrule/coupon');
            $coupon->setRuleId($ruleId)
                ->setCode($couponCode)
                ->setUsageLimit(1)
                ->setType(Mage_SalesRule_Helper_Coupon::COUPON_TYPE_SPECIFIC_AUTOGENERATED)
                ->save();

            $this->_setCouponCode($couponType, $couponCode, $param);
        }

        return $couponCode;
    }

    /**
     * @param $price float
     * @return float
     */
    protected function _convertPriceToCents($price) {
        return round($price * 100);
    }

    /**
     * @return bool
     */
    protected function getTrackPriceWithTax()
    {
        return Mage::getStoreConfig(self::XML_PATH_REJOINER_TRACK_PRICE_WITH_TAX);
    }

    /**
     * @return Mage_Sales_Model_Quote
     */
    protected function _getQuote()
    {
        /** @var Mage_Checkout_Model_Session $session */
        $session = Mage::getSingleton('checkout/session');
        return $session->getQuote();
    }
}