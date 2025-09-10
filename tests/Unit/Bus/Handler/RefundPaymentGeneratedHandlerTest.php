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

namespace Tests\Sylius\AdyenPlugin\Unit\Bus\Handler;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\Bus\Command\CreateReferenceForRefund;
use Sylius\AdyenPlugin\Bus\Handler\RefundPaymentGeneratedHandler;
use Sylius\AdyenPlugin\Checker\AdyenPaymentMethodCheckerInterface;
use Sylius\AdyenPlugin\Repository\PaymentMethodRepositoryInterface;
use Sylius\AdyenPlugin\Repository\RefundPaymentRepositoryInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\RefundPlugin\Entity\RefundPayment;
use Sylius\RefundPlugin\Event\RefundPaymentGenerated;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class RefundPaymentGeneratedHandlerTest extends TestCase
{
    private const DUMMY_REFERENCE = 'W Szczebrzeszynie chrząszcz brzmi w trzcinie';

    private const PSP_REFERENCE = 'Bakłażan';

    private const NEW_PSP_REFERENCE = 'Rzeżucha';

    use AdyenClientTrait;

    private MockObject|PaymentRepositoryInterface $paymentRepository;

    private MockObject|PaymentMethodRepositoryInterface $paymentMethodRepository;

    private MockObject|RefundPaymentRepositoryInterface $refundPaymentRepository;

    private MessageBusInterface|MockObject $messageBus;

    private AdyenPaymentMethodCheckerInterface|MockObject $adyenPaymentMethodChecker;

    private RefundPaymentGeneratedHandler $handler;

    protected function setUp(): void
    {
        $this->setupAdyenClientMocks();

        $this->paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $this->paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $this->refundPaymentRepository = $this->createMock(RefundPaymentRepositoryInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->adyenPaymentMethodChecker = $this->createMock(AdyenPaymentMethodCheckerInterface::class);

        $this->handler = new RefundPaymentGeneratedHandler(
            $this->adyenClientProvider,
            $this->paymentRepository,
            $this->paymentMethodRepository,
            $this->refundPaymentRepository,
            $this->messageBus,
            $this->adyenPaymentMethodChecker,
        );
    }

    #[DataProvider('provideForTestUnacceptable')]
    public function testUnacceptable(?PaymentInterface $payment = null): void
    {
        $this->paymentRepository
            ->method('find')
            ->willReturn($payment);

        if (null !== $payment) {
            $paymentMethod = $payment->getMethod();
            $this->paymentMethodRepository
                ->method('find')
                ->willReturn($paymentMethod)
            ;

            if (null !== $paymentMethod) {
                $this->adyenPaymentMethodChecker->expects($this->once())
                    ->method('isAdyenPaymentMethod')
                    ->with($paymentMethod)
                    ->willReturn(false);
            }
        }

        ($this->handler)(
            new RefundPaymentGenerated(
                1,
                'Brzęczyszczykiewicz',
                10,
                'EUR',
                1,
                1,
            )
        );

        $this->adyenClientProvider
            ->expects($this->never())
            ->method('getForPaymentMethod')
        ;
    }

    public static function provideForTestUnacceptable(): array
    {
        $paymentWithoutPaymentMethod = new Payment();

        $config = new GatewayConfig();
        $nonAdyenPaymentMethod = new PaymentMethod();
        $nonAdyenPaymentMethod->setGatewayConfig($config);
        $paymentWithNonAdyenPaymentMethod = new Payment();
        $paymentWithNonAdyenPaymentMethod->setMethod($nonAdyenPaymentMethod);

        return [
            'no payment provided' => [
                null,
            ],
            'payment without payment method' => [
                $paymentWithoutPaymentMethod,
            ],
            'payment method non-Adyen' => [
                $paymentWithNonAdyenPaymentMethod,
            ],
        ];
    }

    public function testAffirmative(): void
    {
        $config = new GatewayConfig();
        $config->setConfig(['factory_name' => 'adyen']);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setGatewayConfig($config);

        $order = new Order();
        $order->setNumber(self::DUMMY_REFERENCE);

        $payment = new Payment();
        $payment->setMethod($paymentMethod);
        $payment->setDetails([
            'pspReference' => self::PSP_REFERENCE,
        ]);
        $payment->setOrder($order);

        $this->paymentRepository
            ->method('find')
            ->willReturn($payment)
        ;

        $this->paymentMethodRepository
            ->method('find')
            ->willReturn($paymentMethod)
        ;

        $this->adyenPaymentMethodChecker->expects($this->once())
            ->method('isAdyenPaymentMethod')
            ->with($paymentMethod)
            ->willReturn(true);

        $command = new RefundPaymentGenerated(
            42,
            'blah',
            4242,
            'EUR',
            1,
            1,
        );

        $this->adyenClient
            ->expects($this->once())
            ->method('requestRefund')
            ->with(
                $this->equalTo($payment),
                $this->equalTo($command),
            )
            ->willReturn([
                'pspReference' => self::NEW_PSP_REFERENCE,
            ])
        ;

        $this->refundPaymentRepository
            ->method('find')
            ->willReturn($this->createMock(RefundPayment::class))
        ;

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (CreateReferenceForRefund $command) => self::NEW_PSP_REFERENCE === $command->getRefundReference()))
            ->willReturn(Envelope::wrap(new \stdClass(), [new HandledStamp(true, static::class)]))
        ;

        ($this->handler)($command);
    }
}
