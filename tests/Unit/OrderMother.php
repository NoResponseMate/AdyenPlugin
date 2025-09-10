<?php

/*
 * This file is part of the Sylius Adyen Plugin package.
 *
 * (c) Sylius Sp. z o.o.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Sylius\AdyenPlugin\Unit;

use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;

final class OrderMother
{
    public const CUSTOMER_EMAIL = 'ferdek@example.com';

    public const CUSTOMER_FIRST_NAME = 'Ferdynand';

    public const CUSTOMER_LAST_NAME = 'Kiepski';

    public const LOCALE = 'pl_PL';

    public const ITEM_VARIANT_NAME = 'Bulbulator';

    public const ITEM_PRODUCT_SLUG = 'Bakłażan';

    public const ITEM_UNIT_PRICE = 42;

    public const ITEM_TAX_VALUE = 10;

    public const ITEM_TAX_PERCENT = 0.24;

    public const ITEM_ID = 31337;

    public static function createOrderItem(): OrderItemInterface
    {
        $product = new Product();
        $product->setFallbackLocale(self::LOCALE);
        $product->setCurrentLocale(self::LOCALE);
        $product->setSlug(self::ITEM_PRODUCT_SLUG);

        $variant = new ProductVariant();
        $variant->setFallbackLocale(self::LOCALE);
        $variant->setCurrentLocale(self::LOCALE);
        $variant->setName(self::ITEM_VARIANT_NAME);
        $variant->setProduct($product);

        $adjustment = new Adjustment();
        $adjustment->setType(AdjustmentInterface::TAX_ADJUSTMENT);
        $adjustment->setAmount(self::ITEM_TAX_VALUE);

        $item = new class() extends OrderItem {
            public function __construct()
            {
                parent::__construct();
                $this->id = OrderMother::ITEM_ID;
            }
        };
        $item->setVariant($variant);
        $item->setUnitPrice(self::ITEM_UNIT_PRICE);
        $item->addAdjustment($adjustment);

        new OrderItemUnit($item);

        return $item;
    }

    public static function createOrderItemWithTaxDetails(): OrderItemInterface
    {
        $product = new Product();
        $product->setFallbackLocale(self::LOCALE);
        $product->setCurrentLocale(self::LOCALE);
        $product->setSlug(self::ITEM_PRODUCT_SLUG);

        $variant = new ProductVariant();
        $variant->setFallbackLocale(self::LOCALE);
        $variant->setCurrentLocale(self::LOCALE);
        $variant->setName(self::ITEM_VARIANT_NAME);
        $variant->setProduct($product);

        $item = new class() extends OrderItem {
            public function __construct()
            {
                parent::__construct();
                $this->id = OrderMother::ITEM_ID;
            }
        };
        $item->setVariant($variant);
        $item->setUnitPrice(self::ITEM_UNIT_PRICE);

        $adjustment = new Adjustment();
        $adjustment->setType(AdjustmentInterface::TAX_ADJUSTMENT);
        $adjustment->setAmount(self::ITEM_TAX_VALUE);
        $adjustment->setDetails([
            'taxRateAmount' => self::ITEM_TAX_PERCENT,
        ]);

        $unit = new OrderItemUnit($item);
        $unit->addAdjustment($adjustment);

        $item->addUnit($unit);

        return $item;
    }

    public static function createForNormalization(): OrderInterface
    {
        $result = new Order();

        $result->setBillingAddress(AddressMother::createBillingAddress());
        $result->setShippingAddress(AddressMother::createShippingAddress());

        $customer = new Customer();
        $customer->setEmail(self::CUSTOMER_EMAIL);
        $customer->setFirstName(self::CUSTOMER_FIRST_NAME);
        $customer->setLastName(self::CUSTOMER_LAST_NAME);
        $customer->setPhoneNumber(AddressMother::BILLING_PHONE_NUMBER);

        $result->setCustomer($customer);

        $item = self::createOrderItem();

        $item2 = clone $item;

        $result->addItem($item);
        $result->addItem($item2);

        return $result;
    }
}
