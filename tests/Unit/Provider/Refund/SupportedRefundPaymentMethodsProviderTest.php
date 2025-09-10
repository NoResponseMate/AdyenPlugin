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

namespace Tests\Sylius\AdyenPlugin\Unit\Provider\Refund;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\Checker\AdyenPaymentMethodCheckerInterface;
use Sylius\AdyenPlugin\Provider\Refund\SupportedRefundPaymentMethodsProvider;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\RefundPlugin\Provider\RefundPaymentMethodsProviderInterface;

final class SupportedRefundPaymentMethodsProviderTest extends TestCase
{
    private MockObject|RefundPaymentMethodsProviderInterface $decoratedProvider;

    private AdyenPaymentMethodCheckerInterface|MockObject $adyenPaymentMethodChecker;

    private SupportedRefundPaymentMethodsProvider $provider;

    protected function setUp(): void
    {
        $this->decoratedProvider = $this->createMock(RefundPaymentMethodsProviderInterface::class);
        $this->adyenPaymentMethodChecker = $this->createMock(AdyenPaymentMethodCheckerInterface::class);
        $this->provider = new SupportedRefundPaymentMethodsProvider($this->decoratedProvider, $this->adyenPaymentMethodChecker);
    }

    public function test_it_returns_all_methods_when_order_has_no_completed_payment(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $expectedMethods = [$this->createMock(PaymentMethodInterface::class)];

        $order->expects($this->once())
            ->method('getLastPayment')
            ->with(PaymentInterface::STATE_COMPLETED)
            ->willReturn(null);

        $this->decoratedProvider
            ->expects($this->once())
            ->method('findForOrder')
            ->with($order)
            ->willReturn($expectedMethods);

        $result = $this->provider->findForOrder($order);

        self::assertSame($expectedMethods, $result);
    }

    public function test_it_returns_all_methods_when_last_payment_is_adyen_payment(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $payment = $this->createMock(PaymentInterface::class);
        $expectedMethods = [$this->createMock(PaymentMethodInterface::class)];

        $order->expects($this->once())
            ->method('getLastPayment')
            ->with(PaymentInterface::STATE_COMPLETED)
            ->willReturn($payment);

        $this->adyenPaymentMethodChecker->expects($this->once())
            ->method('isAdyenPayment')
            ->with($payment)
            ->willReturn(true);

        $this->decoratedProvider
            ->expects($this->once())
            ->method('findForOrder')
            ->with($order)
            ->willReturn($expectedMethods);

        $result = $this->provider->findForOrder($order);

        self::assertSame($expectedMethods, $result);
    }

    public function test_it_filters_out_adyen_methods_when_last_payment_is_not_adyen(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $payment = $this->createMock(PaymentInterface::class);
        $adyenMethod = $this->createMock(PaymentMethodInterface::class);
        $nonAdyenMethod = $this->createMock(PaymentMethodInterface::class);
        $allMethods = [$adyenMethod, $nonAdyenMethod];

        $order->expects($this->once())
            ->method('getLastPayment')
            ->with(PaymentInterface::STATE_COMPLETED)
            ->willReturn($payment);

        $this->adyenPaymentMethodChecker->expects($this->once())
            ->method('isAdyenPayment')
            ->with($payment)
            ->willReturn(false);

        $this->adyenPaymentMethodChecker->expects($this->exactly(2))
            ->method('isAdyenPaymentMethod')
            ->willReturnCallback(fn ($method) => $method === $adyenMethod);

        $this->decoratedProvider
            ->expects($this->once())
            ->method('findForOrder')
            ->with($order)
            ->willReturn($allMethods);

        $result = $this->provider->findForOrder($order);

        self::assertCount(1, $result);
        self::assertContains($nonAdyenMethod, $result);
        self::assertNotContains($adyenMethod, $result);
    }
}
