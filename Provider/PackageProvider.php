<?php

namespace Oro\Bundle\DPDBundle\Provider;

use Doctrine\Common\Persistence\ManagerRegistry;
use Oro\Bundle\DPDBundle\Model\Package;
use Oro\Bundle\LocaleBundle\Helper\LocalizationHelper;
use Oro\Bundle\ShippingBundle\Context\LineItem\Collection\ShippingLineItemCollectionInterface;
use Oro\Bundle\ShippingBundle\Context\ShippingLineItemInterface;
use Oro\Bundle\ShippingBundle\Entity\ProductShippingOptions;
use Oro\Bundle\ShippingBundle\Model\Weight;
use Oro\Bundle\ShippingBundle\Provider\MeasureUnitConversion;

class PackageProvider
{
    const MAX_PACKAGE_WEIGHT_KGS = 31.5; //as defined on dpd api documentation
    const UNIT_OF_WEIGHT = 'kg'; //dpd only supports kg

    /** @var ManagerRegistry */
    protected $registry;

    /** @var MeasureUnitConversion */
    protected $measureUnitConversion;

    /** @var LocalizationHelper */
    protected $localizationHelper;

    /**
     * @param ManagerRegistry       $registry
     * @param MeasureUnitConversion $measureUnitConversion
     * @param LocalizationHelper    $localizationHelper
     */
    public function __construct(
        ManagerRegistry $registry,
        MeasureUnitConversion $measureUnitConversion,
        LocalizationHelper $localizationHelper
    ) {
        $this->registry = $registry;
        $this->measureUnitConversion = $measureUnitConversion;
        $this->localizationHelper = $localizationHelper;
    }

    /**
     * @param ShippingLineItemCollectionInterface $lineItems
     *
     * @return null|\Oro\Bundle\DPDBundle\Model\Package[]
     */
    public function createPackages(ShippingLineItemCollectionInterface $lineItems)
    {
        if ($lineItems->isEmpty()) {
            return null;
        }

        $packages = [];
        $productsInfoByUnit = $this->getProductInfoByUnit($lineItems);
        if (count($productsInfoByUnit) > 0) {
            $weight = 0;
            $contents = [];
            /** @var array $unit */
            foreach ($productsInfoByUnit as $unit) {
                if ($unit['weight'] > static::MAX_PACKAGE_WEIGHT_KGS) {
                    return null;
                }
                if (($weight + $unit['weight']) > static::MAX_PACKAGE_WEIGHT_KGS) {
                    $packages[] = (new Package())
                        ->setWeight($weight)
                        ->setContents(implode(',', $contents));

                    $weight = 0;
                    $contents = [];
                }

                $weight += $unit['weight'];
                $contents[$unit['productId']] = $unit['productName'];
            }

            if ($weight > 0) {
                $packages[] = (new Package())
                    ->setWeight($weight)
                    ->setContents(implode(',', $contents));
            }
        }

        return $packages;
    }

    /**
     * @param ShippingLineItemCollectionInterface $lineItems
     *
     * @return array
     *
     * @throws \UnexpectedValueException
     */
    protected function getProductInfoByUnit(ShippingLineItemCollectionInterface $lineItems)
    {
        $productsInfoByUnit = [];

        $productsInfo = $this->convertLineItemsToProductInfoArray($lineItems);

        if (count($productsInfo) === 0) {
            return [];
        }

        $allProductsShippingOptions = $this->getProductsShippingOptions($productsInfo);

        if (count($allProductsShippingOptions) === 0) {
            return [];
        }

        /** @var ProductShippingOptions $productShippingOptions */
        foreach ($allProductsShippingOptions as $productShippingOptions) {
            $productId = $productShippingOptions->getProduct()->getId();
            //$productDefaultName = $productShippingOptions->getProduct()->getDefaultName()->getString();
            $productName = (string) $this->localizationHelper
                ->getLocalizedValue($productShippingOptions->getProduct()->getNames());

            $lineItemWeight = null;
            if ($productShippingOptions->getWeight() instanceof Weight) {
                if (!$productShippingOptions->getWeight()->getValue()) {
                    return [];
                }
                /** @var Weight|null $lineItemWeight */
                $lineItemWeight = $this->measureUnitConversion->convert(
                    $productShippingOptions->getWeight(),
                    static::UNIT_OF_WEIGHT
                );

                $lineItemWeight = $lineItemWeight !== null ? $lineItemWeight->getValue() : null;
            }
            if (!$lineItemWeight) {
                return [];
            }

            for ($i = 0; $i < $productsInfo[$productId]['quantity']; ++$i) {
                $productsInfoByUnit[] = [
                    'productId' => $productId,
                    'productName' => $productName,
                    'weightUnit' => static::UNIT_OF_WEIGHT,
                    'weight' => $lineItemWeight,
                ];
            }
        }

        return $productsInfoByUnit;
    }

    /**
     * @param ShippingLineItemCollectionInterface $lineItems
     *
     * @return array
     */
    private function convertLineItemsToProductInfoArray(ShippingLineItemCollectionInterface $lineItems)
    {
        $productsInfo = [];

        /** @var ShippingLineItemInterface $lineItem */
        foreach ($lineItems as $lineItem) {
            $product = $lineItem->getProduct();
            if (null === $product) {
                return [];
            }

            $productsInfo[$product->getId()] = [
                'product' => $product,
                'productUnit' => $lineItem->getProductUnit(),
                'quantity' => $lineItem->getQuantity(),
            ];
        }
        return $productsInfo;
    }

    /**
     * @param array $productsInfo
     *
     * @return ProductShippingOptions[]
     */
    private function getProductsShippingOptions(array $productsInfo)
    {
        $requiredProducts = array_column($productsInfo, 'product');

        $productsShippingOptions = $this->registry
            ->getManagerForClass('OroShippingBundle:ProductShippingOptions')
            ->getRepository('OroShippingBundle:ProductShippingOptions')
            ->findBy([
                'product' => $requiredProducts,
                'productUnit' => array_column($productsInfo, 'productUnit'),
            ]);

        /** ProductShippingOptions has unique key on product and unit, so this check is valid */
        if (count($requiredProducts) !== count($productsShippingOptions)) {
            return [];
        }

        return $productsShippingOptions;
    }
}
