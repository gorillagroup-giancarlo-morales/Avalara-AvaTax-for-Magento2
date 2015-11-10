<?php

namespace ClassyLlama\AvaTax\Framework\Interaction;

use AvaTax\LineFactory;
use ClassyLlama\AvaTax\Helper\Validation;
use ClassyLlama\AvaTax\Model\Config;
use Magento\Catalog\Model\ResourceModel\Product as ResourceProduct;

class Line
{
    /**
     * @var Config
     */
    protected $config = null;

    /**
     * @var Validation
     */
    protected $validation = null;

    /**
     * @var LineFactory
     */
    protected $lineFactory = null;

    /**
     * @var ResourceProduct
     */
    protected $resourceProduct = null;

    /**
     * @var array
     */
    protected $productSkus = [];

    /**
     * A list of valid fields for the data array and meta data about their types to use in validation
     * based on the API documentation.
     * Validation based on API documentation found here:
     * http://developer.avalara.com/wp-content/apireference/master/?php#line30
     *
     * @var array
     */
    protected $validDataFields = [
        'store_id' => ['type' => 'integer'],
        'no' => ['type' => 'string', 'length' => 50, 'required' => true],
        'origin_address' => ['type' => 'object', 'class' => '\AvaTax\Address'],
        'destination_address' => ['type' => 'object', 'class' => '\AvaTax\Address'],
        'item_code' => ['type' => 'string', 'length' => 50],
        'tax_code' => ['type' => 'string', 'length' => 25],
        'customer_usage_type' => ['type' => 'string', 'length' => 25],
        'exemption_no' => ['type' => 'string', 'length' => 25],
        'description' => ['type' => 'string', 'length' => 255],
        'qty' => ['type' => 'float'],
        'amount' => ['type' => 'float'], // Required but $0 value is acceptable so removing required attribute.
        'discounted' => ['type' => 'boolean'],
        'tax_included' => ['type' => 'boolean'],
        'ref1' => ['type' => 'string', 'length' => 250],
        'ref2' => ['type' => 'string', 'length' => 250],
        'tax_override' => ['type' => 'object', 'class' => '\AvaTax\TaxOverride'],
    ];

    public function __construct(
        Config $config,
        Validation $validation,
        LineFactory $lineFactory,
        ResourceProduct $resourceProduct
    ) {
        $this->config = $config;
        $this->validation = $validation;
        $this->lineFactory = $lineFactory;
        $this->resourceProduct = $resourceProduct;
    }

    /**
     * Return an array with relevant data from an order item.
     * All TODOs in the doc block and the method body apply to all 4 conversion methods
     * TODO: tax_code can either be custom or system.  Custom tax codes can be configured in the AvaTax admin to set up specific tax reductions or exemptions for certain products.  In AvaTax Pro, there are many system tax codes that can be passed depending on the type of item that is being sold.  This really belongs on the product level although we could also put it on the Tax Class level as well.  The M1 module just uses the same value for this as for customer_usage_type which is confusing and incorrect for cases where you may want to pass both on the same item.  We should at least implement this as a text field on either the product, the tax class, or both.  We could possibly implement this as a more configurable option but that really seems like a phase 2 or phase 3 feature. More information: https://help.avalara.com/000_AvaTax_Calc/000AvaTaxCalc_User_Guide/051_Select_AvaTax_System_Tax_Codes and http://developer.avalara.com/api-docs/designing-your-integration/gettax.
     * TODO: Wishlist Product Attribute for tax_code
     * TODO: Fields to figure out: tax_override
     * TODO: Use Tax Class to get customer_usage_type, once this functionality is implemented
     * TODO: ref1 and ref2 are optional fields that can be used to pass extra data.  M1 module has config fields to enter attribute codes for each which will be passed along.  Create a source model to select an attribute (and a do not pass attribute option) for each field.
     *
     * @author Jonathan Hodges <jonathan@classyllama.com>
     * @param \Magento\Sales\Api\Data\OrderItemInterface $item
     * @return array
     */
    protected function convertOrderItemToData(\Magento\Sales\Api\Data\OrderItemInterface $item)
    {
        // Items that have parent items do not contain taxable information
        // TODO: Confirm this is true for all item types
        if (!is_null($item->getParentItemId())) {
            return null;
        }

        return [
            'store_id' => $item->getStoreId(),
            'no' => $item->getItemId(),
            'item_code' => $item->getSku(), // TODO: Figure out if this is related to AvaTax UPC functionality
//            'tax_code' => null,
//            'customer_usage_type' => null,
            'description' => $item->getName(),
            'qty' => $item->getQtyOrdered(),
            'amount' => $item->getRowTotal(), // TODO: Figure out how to handle amount and discounted to comply with US and EU tax regulations correctly
            'discounted' => (bool)($item->getDiscountAmount() > 0),
            'tax_included' => false,
//            'ref1' => null,
//            'ref2' => null,
//            'tax_override' => null,
        ];
    }

    protected function convertQuoteItemToData(\Magento\Quote\Api\Data\CartItemInterface $item)
    {
        // Items that have parent items do not contain taxable information
        // TODO: Confirm this is true for all item types
        if ($item->getParentItem()) {
            return null;
        }

        return [
            'store_id' => $item->getStoreId(),
            'no' => $item->getItemId(),
            'item_code' => $item->getSku(), // TODO: Figure out if this is related to AvaTax UPC functionality
//            'tax_code' => null,
//            'customer_usage_type' => null,
            'description' => $item->getName(),
            'qty' => $item->getQty(),
            'amount' => $item->getRowTotal(), // TODO: Figure out how to handle amount and discounted to comply with US and EU tax regulations correctly
            'discounted' => (bool)($item->getDiscountAmount() > 0),
            'tax_included' => false,
//            'ref1' => null,
//            'ref2' => null,
//            'tax_override' => null,
        ];
    }

    protected function convertInvoiceItemToData(\Magento\Sales\Api\Data\InvoiceItemInterface $item)
    {

    }

    protected function convertCreditMemoItemToData(\Magento\Sales\Api\Data\CreditmemoItemInterface $data)
    {

    }

        /**
     *
     * TODO: Figure out if we need to account for Streamlined Sales Tax requirements for description
     *
     * @author Jonathan Hodges <jonathan@classyllama.com>
     * @param $data
     * @return \AvaTax\Line|null
     */
    public function getLine($data) {
        switch (true) {
            case ($data instanceof \Magento\Sales\Api\Data\OrderItemInterface):
                $data = $this->convertOrderItemToData($data);
                break;
            case ($data instanceof \Magento\Quote\Api\Data\CartItemInterface):
                $data = $this->convertQuoteItemToData($data);
                break;
            case ($data instanceof \Magento\Sales\Api\Data\InvoiceItemInterface):
//                $data = $this->convertInvoiceItemToData($data);
                break;
            case ($data instanceof \Magento\Sales\Api\Data\CreditmemoItemInterface):
//                $data = $this->convertCreditMemoItemToData($data);
                break;
            case (!is_array($data)):
                return false;
                break;
        }

        if (is_null($data)) {
            return null;
        }

        $data = $this->validation->validateData($data, $this->validDataFields);
        /** @var $line \AvaTax\Line */
        $line = $this->lineFactory->create();

        if (isset($data['no'])) {
            $line->setNo($data['no']);
        }
        if (isset($data['origin_address'])) {
            $line->setOriginAddress($data['origin_address']);
        }
        if (isset($data['destination_address'])) {
            $line->setDestinationAddress($data['destination_address']);
        }
        if (isset($data['item_code'])) {
            $line->setItemCode($data['item_code']);
        }
        if (isset($data['tax_code'])) {
            $line->setTaxCode($data['tax_code']);
        }
        if (isset($data['customer_usage_type'])) {
            $line->setCustomerUsageType($data['customer_usage_type']);
        }
        if (isset($data['exemption_no'])) {
            $line->setExemptionNo($data['exemption_no']);
        }
        if (isset($data['description'])) {
            $line->setDescription($data['description']);
        }
        if (isset($data['qty'])) {
            $line->setQty($data['qty']);
        }
        if (isset($data['amount'])) {
            $line->setAmount($data['amount']);
        }
        if (isset($data['discounted'])) {
            $line->setDiscounted($data['discounted']);
        }
        if (isset($data['tax_included'])) {
            $line->setTaxIncluded($data['tax_included']);
        }
        if (isset($data['ref1'])) {
            $line->setRef1($data['ref1']);
        }
        if (isset($data['ref2'])) {
            $line->setRef2($data['ref2']);
        }
        if (isset($data['tax_override'])) {
            $line->setTaxOverride($data['tax_override']);
        }
        return $line;
    }
}