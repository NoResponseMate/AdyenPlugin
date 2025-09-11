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

namespace Sylius\AdyenPlugin\Resolver\Payment;

use Sylius\AdyenPlugin\Resolver\Notification\Struct\NotificationItemData;

interface EventCodeResolverInterface
{
    public const EVENT_CANCEL_OR_REFUND = 'cancel_or_refund';

    public const EVENT_CAPTURE = 'capture';

    public const EVENT_CAPTURE_FAILED = 'capture_failed';

    public const EVENT_AUTHORIZATION = 'authorisation';

    public const EVENT_CANCELLATION = 'cancellation';

    public const EVENT_PAY_BY_LINK_AUTHORISATION = 'pay_by_link_authorisation';

    public const EVENT_REFUND = 'refund';

    public const MODIFICATION_REFUND = 'refund';

    public const MODIFICATION_CANCEL = 'cancel';

    public function resolve(NotificationItemData $notificationData): string;
}
