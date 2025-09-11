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

namespace Tests\Sylius\AdyenPlugin\Unit\Provider;

use Adyen\Model\Checkout\PaymentMethodsResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\Checker\AdyenPaymentMethodCheckerInterface;
use Sylius\AdyenPlugin\Client\AdyenClientInterface;
use Sylius\AdyenPlugin\Entity\ShopperReferenceInterface;
use Sylius\AdyenPlugin\Exception\AdyenPaymentMethodNotFoundException;
use Sylius\AdyenPlugin\Filter\PaymentMethodsFilterInterface;
use Sylius\AdyenPlugin\Filter\StoredPaymentMethodsFilterInterface;
use Sylius\AdyenPlugin\Mapper\PaymentMethodsMapperInterface;
use Sylius\AdyenPlugin\PaymentCaptureMode;
use Sylius\AdyenPlugin\Provider\AdyenClientProviderInterface;
use Sylius\AdyenPlugin\Provider\CurrentShopUserProviderInterface;
use Sylius\AdyenPlugin\Provider\PaymentMethodsProvider;
use Sylius\AdyenPlugin\Repository\PaymentMethodRepositoryInterface;
use Sylius\AdyenPlugin\Resolver\ShopperReferenceResolverInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;

final class PaymentMethodsProviderTest extends TestCase
{
    private AdyenClientProviderInterface $adyenClientProvider;

    private PaymentMethodRepositoryInterface $paymentMethodRepository;

    private PaymentMethodsFilterInterface $paymentMethodsFilter;

    private AdyenPaymentMethodCheckerInterface $adyenPaymentMethodChecker;

    private StoredPaymentMethodsFilterInterface $storedPaymentMethodsFilter;

    private PaymentMethodsMapperInterface $paymentMethodsMapper;

    private ShopperReferenceResolverInterface $shopperReferenceResolver;

    private CurrentShopUserProviderInterface $shopUserProvider;

    private PaymentMethodsProvider $paymentMethodsProvider;

    protected function setUp(): void
    {
        $this->adyenClientProvider = $this->createMock(AdyenClientProviderInterface::class);
        $this->paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $this->paymentMethodsFilter = $this->createMock(PaymentMethodsFilterInterface::class);
        $this->adyenPaymentMethodChecker = $this->createMock(AdyenPaymentMethodCheckerInterface::class);
        $this->storedPaymentMethodsFilter = $this->createMock(StoredPaymentMethodsFilterInterface::class);
        $this->paymentMethodsMapper = $this->createMock(PaymentMethodsMapperInterface::class);
        $this->shopperReferenceResolver = $this->createMock(ShopperReferenceResolverInterface::class);
        $this->shopUserProvider = $this->createMock(CurrentShopUserProviderInterface::class);

        $this->paymentMethodsProvider = new PaymentMethodsProvider(
            $this->adyenClientProvider,
            $this->paymentMethodRepository,
            $this->paymentMethodsFilter,
            $this->adyenPaymentMethodChecker,
            $this->storedPaymentMethodsFilter,
            $this->paymentMethodsMapper,
            $this->shopperReferenceResolver,
            $this->shopUserProvider,
        );
    }

    public function testThrowsWhenPaymentMethodNotFound(): void
    {
        $order = $this->createMock(OrderInterface::class);

        $this->paymentMethodRepository
            ->method('getOneAdyenForCode')
            ->with('missing')
            ->willReturn(null);

        $this->expectException(AdyenPaymentMethodNotFoundException::class);

        $this->paymentMethodsProvider->provideForOrder('missing', $order);
    }

    #[DataProvider('getCaptureMode')]
    public function testGuestWithoutCustomerDoesNotCallResolver(bool $isManual): void
    {
        $order = $this->createMock(OrderInterface::class);
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $client = $this->createMock(AdyenClientInterface::class);

        $order->method('getCustomer')->willReturn(null);

        $this->paymentMethodRepository
            ->method('getOneAdyenForCode')
            ->willReturn($paymentMethod);

        $this->shopperReferenceResolver
            ->expects(self::never())
            ->method('resolve');

        $this->adyenClientProvider
            ->method('getClientForCode')
            ->willReturn($client);

        $this->adyenPaymentMethodChecker
            ->method('isCaptureMode')
            ->with($paymentMethod, PaymentCaptureMode::MANUAL)
            ->willReturn($isManual);

        $client
            ->expects(self::once())
            ->method('getPaymentMethodsResponse')
            ->with($order, null, $isManual)
            ->willReturn($this->paymentMethodsResponse(['raw_available'], ['raw_stored']));

        $this->expectMappingPipeline(
            rawAvailable: ['raw_available'],
            mappedAvailable: ['A1', 'A2'],
            filteredAvailable: ['A2'],
            rawStored: ['raw_stored'],
            mappedStored: ['S1', 'S2'],
            filteredStoredAgainst: ['S2'],
        );

        $result = $this->paymentMethodsProvider->provideForOrder('adyen_card', $order);

        self::assertSame(['A2'], $result->paymentMethods);
        self::assertSame(['S2'], $result->storedPaymentMethods);
    }

    #[DataProvider('getCaptureMode')]
    public function testGuestWithCustomerWithoutUserDoesNotCallResolver(bool $isManual): void
    {
        $order = $this->createMock(OrderInterface::class);
        $customer = $this->createMock(CustomerInterface::class);
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $client = $this->createMock(AdyenClientInterface::class);

        $order->method('getCustomer')->willReturn($customer);
        $customer->method('getUser')->willReturn(null);

        $this->paymentMethodRepository
            ->method('getOneAdyenForCode')
            ->willReturn($paymentMethod);

        $this->shopperReferenceResolver
            ->expects(self::never())
            ->method('resolve');

        $this->adyenClientProvider
            ->method('getClientForCode')
            ->willReturn($client);

        $this->adyenPaymentMethodChecker
            ->method('isCaptureMode')
            ->with($paymentMethod, PaymentCaptureMode::MANUAL)
            ->willReturn($isManual);

        $client
            ->expects(self::once())
            ->method('getPaymentMethodsResponse')
            ->with($order, null, $isManual)
            ->willReturn($this->paymentMethodsResponse(['raw_available'], ['raw_stored']));

        $this->expectMappingPipeline(
            rawAvailable: ['raw_available'],
            mappedAvailable: ['A'],
            filteredAvailable: ['Af'],
            rawStored: ['raw_stored'],
            mappedStored: ['S'],
            filteredStoredAgainst: ['Sf'],
        );

        $result = $this->paymentMethodsProvider->provideForOrder('adyen_card', $order);

        self::assertSame(['Af'], $result->paymentMethods);
        self::assertSame(['Sf'], $result->storedPaymentMethods);
    }

    #[DataProvider('getCaptureMode')]
    public function testGuestWithCustomerAndUserDoesNotCallResolver(bool $isManual): void
    {
        $order = $this->createMock(OrderInterface::class);
        $customer = $this->createMock(CustomerInterface::class);
        $user = $this->createMock(ShopUserInterface::class);
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $client = $this->createMock(AdyenClientInterface::class);

        $order->method('getCustomer')->willReturn($customer);
        $customer->method('getUser')->willReturn($user);

        $this->shopUserProvider
            ->method('getShopUser')
            ->willReturn(null);

        $this->paymentMethodRepository
            ->method('getOneAdyenForCode')
            ->willReturn($paymentMethod);

        $this->shopperReferenceResolver
            ->expects(self::never())
            ->method('resolve');

        $this->adyenClientProvider
            ->method('getClientForCode')
            ->willReturn($client);

        $this->adyenPaymentMethodChecker
            ->method('isCaptureMode')
            ->with($paymentMethod, PaymentCaptureMode::MANUAL)
            ->willReturn($isManual);

        $client
            ->expects(self::once())
            ->method('getPaymentMethodsResponse')
            ->with($order, null, $isManual)
            ->willReturn($this->paymentMethodsResponse(['raw_available'], ['raw_stored']));

        $this->expectMappingPipeline(
            rawAvailable: ['raw_available'],
            mappedAvailable: ['A'],
            filteredAvailable: ['Af'],
            rawStored: ['raw_stored'],
            mappedStored: ['S'],
            filteredStoredAgainst: ['Sf'],
        );

        $result = $this->paymentMethodsProvider->provideForOrder('adyen_card', $order);

        self::assertSame(['Af'], $result->paymentMethods);
        self::assertSame(['Sf'], $result->storedPaymentMethods);
    }

    #[DataProvider('getCaptureMode')]
    public function testLoggedInUserWithDifferentUserOrderDoesNotCallResolver(bool $isManual): void
    {
        $order = $this->createMock(OrderInterface::class);
        $customer = $this->createMock(CustomerInterface::class);
        $customerUser = $this->createMock(ShopUserInterface::class);
        $loggedUser = $this->createMock(ShopUserInterface::class);
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $client = $this->createMock(AdyenClientInterface::class);

        $order->method('getCustomer')->willReturn($customer);
        $customer->method('getUser')->willReturn($customerUser);

        $this->shopUserProvider
            ->method('getShopUser')
            ->willReturn($loggedUser);

        $this->paymentMethodRepository
            ->method('getOneAdyenForCode')
            ->willReturn($paymentMethod);

        $this->shopperReferenceResolver
            ->expects(self::never())
            ->method('resolve');

        $this->adyenClientProvider
            ->method('getClientForCode')
            ->willReturn($client);

        $this->adyenPaymentMethodChecker
            ->method('isCaptureMode')
            ->with($paymentMethod, PaymentCaptureMode::MANUAL)
            ->willReturn($isManual);

        $client
            ->expects(self::once())
            ->method('getPaymentMethodsResponse')
            ->with($order, null, $isManual)
            ->willReturn($this->paymentMethodsResponse(['raw_available'], ['raw_stored']));

        $this->expectMappingPipeline(
            rawAvailable: ['raw_available'],
            mappedAvailable: ['A'],
            filteredAvailable: ['Af'],
            rawStored: ['raw_stored'],
            mappedStored: ['S'],
            filteredStoredAgainst: ['Sf'],
        );

        $result = $this->paymentMethodsProvider->provideForOrder('adyen_card', $order);

        self::assertSame(['Af'], $result->paymentMethods);
        self::assertSame(['Sf'], $result->storedPaymentMethods);
    }

    #[DataProvider('getCaptureMode')]
    public function testLoggedInUserResolvesShopperReferenceAndPassesItToClient(bool $isManual): void
    {
        $order = $this->createMock(OrderInterface::class);
        $customer = $this->createMock(CustomerInterface::class);
        $user = $this->createMock(ShopUserInterface::class);
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $client = $this->createMock(AdyenClientInterface::class);
        $shopperReference = $this->createMock(ShopperReferenceInterface::class);

        $order->method('getCustomer')->willReturn($customer);
        $customer->method('getUser')->willReturn($user);

        $this->shopUserProvider
            ->method('getShopUser')
            ->willReturn($user);

        $this->paymentMethodRepository
            ->method('getOneAdyenForCode')
            ->willReturn($paymentMethod);

        $this->shopperReferenceResolver
            ->expects(self::once())
            ->method('resolve')
            ->with($paymentMethod, $customer)
            ->willReturn($shopperReference);

        $this->adyenClientProvider
            ->method('getClientForCode')
            ->willReturn($client);

        $this->adyenPaymentMethodChecker
            ->method('isCaptureMode')
            ->with($paymentMethod, PaymentCaptureMode::MANUAL)
            ->willReturn($isManual);

        $client
            ->expects(self::once())
            ->method('getPaymentMethodsResponse')
            ->with($order, $shopperReference, $isManual)
            ->willReturn($this->paymentMethodsResponse(['raw_av'], ['raw_st']));

        $this->expectMappingPipeline(
            rawAvailable: ['raw_av'],
            mappedAvailable: ['A'],
            filteredAvailable: ['Af'],
            rawStored: ['raw_st'],
            mappedStored: ['S'],
            filteredStoredAgainst: ['Sf'],
        );

        $result = $this->paymentMethodsProvider->provideForOrder('adyen_card', $order);

        self::assertSame(['Af'], $result->paymentMethods);
        self::assertSame(['Sf'], $result->storedPaymentMethods);
    }

    public static function getCaptureMode(): iterable
    {
        yield 'manual' => [true];
        yield 'automatic' => [false];
    }

    private function paymentMethodsResponse(array $available, array $stored): PaymentMethodsResponse
    {
        $resp = new PaymentMethodsResponse();
        $resp->setPaymentMethods($available);
        $resp->setStoredPaymentMethods($stored);

        return $resp;
    }

    private function expectMappingPipeline(
        array $rawAvailable,
        array $mappedAvailable,
        array $filteredAvailable,
        array $rawStored,
        array $mappedStored,
        array $filteredStoredAgainst,
    ): void {
        $this->paymentMethodsMapper
            ->expects(self::once())
            ->method('mapAvailable')
            ->with($rawAvailable)
            ->willReturn($mappedAvailable);

        $this->paymentMethodsFilter
            ->expects(self::once())
            ->method('filter')
            ->with($mappedAvailable)
            ->willReturn($filteredAvailable);

        $this->paymentMethodsMapper
            ->expects(self::once())
            ->method('mapStored')
            ->with($rawStored)
            ->willReturn($mappedStored);

        $this->storedPaymentMethodsFilter
            ->expects(self::once())
            ->method('filterAgainstAvailable')
            ->with($mappedStored, $filteredAvailable)
            ->willReturn($filteredStoredAgainst);
    }
}
