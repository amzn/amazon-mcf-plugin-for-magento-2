<?php
/**
 * Copyright 2017 Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 * A copy of the License is located at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * or in the "license" file accompanying this file. This file is distributed
 * on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 * express or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 */
namespace Amazon\MCF\Plugin;

/**
 * Class ProductSkuConfig
 *
 * @package Amazon\MCF\Plugin
 */
class ProductSkuConfig
{

    /**
     * @var \Amazon\MCF\Model\Service\Inventory
     */
    private $inventory;

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    private $configHelper;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    private $messageManager;

    /**
     * @var \Magento\CatalogInventory\Api\StockRegistryInterface
     */
    private $stockRegistry;

    /**
     * ConfigPlugin constructor.
     *
     * @param \Amazon\MCF\Model\Service\Inventory         $inventory
     * @param \Amazon\MCF\Helper\Data                     $data
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     */
    public function __construct(
        \Amazon\MCF\Model\Service\Inventory $inventory,
        \Amazon\MCF\Helper\Data $data,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\Framework\Message\ManagerInterface $messageManager
    ) {

        $this->stockRegistry = $stockRegistry;
        $this->configHelper = $data;
        $this->messageManager = $messageManager;
        $this->inventory = $inventory;
    }

    public function afterSave(\Magento\Catalog\Model\Product $product) 
    {
        $skus = [];

        if ($product && $product->getAmazonMcfAsinEnabled()) {

            $skus[] = $product->getSku();

            if ($product->getAmazonMcfMerchantSku()) {
                $skus[] = $product->getAmazonMcfMerchantSku();
            }

            if ($skus) {
                $exists = false;
                $response = $this->inventory->getFulfillmentInventoryList(['member' => $skus]);

                if ($response) {
                    $supplyList = $response->getListInventorySupplyResult()
                        ->getInventorySupplyList()
                        ->getmember();

                    if ($supplyList) {

                        $quantities = 0;

                        foreach ($supplyList as $item) {

                            if ($item->getASIN()) {
                                $exists = true;
                            }

                            $quantities += $item->getInStockSupplyQuantity();
                        }
                    }
                }

                if ($exists) {
                    $stockItem = $this->stockRegistry->getStockItem($product->getId());
                    $stockItem->setData('qty', $quantities);

                    // make sure to set item in/out of stock if there is/isn't inventory. This will hide/show it on the front end
                    if ($quantities > 0) {
                        $stockItem->setData('is_in_stock', true);
                    } else {
                        $stockItem->setData('is_in_stock', false);
                    }

                    $this->stockRegistry->updateStockItemBySku($product->getSku(), $stockItem);

                    $this->messageManager->addSuccessMessage(
                        'The SKU or alternate Merchant SKU has an associated Seller Sku at Amazon. '
                        . $quantities . ' item(s) are in stock. The amount of inventory has been updated.'
                    );
                } else {
                    $this->messageManager->addErrorMessage(
                        'The SKU entered "' . $product->getSku()
                        . '" does not have an associated Seller Sku at Amazon. 
                        Please check the SKU value matches between systems.'
                    );
                }
            }
        }
    }
}
