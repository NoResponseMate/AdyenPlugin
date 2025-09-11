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

namespace Sylius\AdyenPlugin\Client;

use Adyen\Model\Checkout\PaymentCancelRequest;
use Adyen\Model\Checkout\PaymentCaptureRequest;
use Adyen\Model\Checkout\PaymentDetailsRequest;
use Adyen\Model\Checkout\PaymentLinkRequest;
use Adyen\Model\Checkout\PaymentMethodsRequest;
use Adyen\Model\Checkout\PaymentRefundRequest;
use Adyen\Model\Checkout\PaymentRequest;
use Adyen\Model\Checkout\PaymentReversalRequest;
use Adyen\Model\Checkout\PaypalUpdateOrderRequest;
use Adyen\Model\Checkout\UpdatePaymentLinkRequest;
use Payum\Core\Bridge\Spl\ArrayObject;
use Sylius\AdyenPlugin\Entity\ShopperReferenceInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\RefundPlugin\Event\RefundPaymentGenerated;

interface ClientPayloadFactoryInterface
{
    public const NO_COUNTRY_AVAILABLE_PLACEHOLDER = 'ZZ';

    public function createForAvailablePaymentMethods(
        ArrayObject $options,
        OrderInterface $order,
        ?ShopperReferenceInterface $shopperReference = null,
        bool $manualCapture = false,
    ): PaymentMethodsRequest;

    public function createForPaymentDetails(
        array $receivedPayload,
        ?ShopperReferenceInterface $shopperReference = null,
    ): PaymentDetailsRequest;

    public function createForSubmitPayment(
        ArrayObject $options,
        string $url,
        array $receivedPayload,
        OrderInterface $order,
        bool $manualCapture = false,
        ?ShopperReferenceInterface $shopperReference = null,
    ): PaymentRequest;

    public function createForCapture(ArrayObject $options, PaymentInterface $payment): PaymentCaptureRequest;

    public function createForCancel(ArrayObject $options, PaymentInterface $payment): PaymentCancelRequest;

    public function createForTokenRemove(
        ArrayObject $options,
        string $paymentReference,
        ShopperReferenceInterface $shopperReference,
    ): array;

    public function createForRefund(
        ArrayObject $options,
        PaymentInterface $payment,
        RefundPaymentGenerated $refund,
    ): PaymentRefundRequest;

    public function createForReversal(ArrayObject $options, PaymentInterface $payment): PaymentReversalRequest;

    public function createForPaymentLink(ArrayObject $options, PaymentInterface $payment): PaymentLinkRequest;

    public function createForPaymentLinkExpiration(ArrayObject $options, string $paymentLinkId): UpdatePaymentLinkRequest;

    public function createForPaypalPayments(
        ArrayObject $options,
        array $receivedPayload,
        OrderInterface $order,
        string $returnUrl = '',
    ): PaymentRequest;

    public function createPaypalUpdateOrderRequest(string $pspReference, string $paymentData, OrderInterface $order): PaypalUpdateOrderRequest;
}
