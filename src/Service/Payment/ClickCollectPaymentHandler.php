<?php declare(strict_types=1);

namespace FbClickCollect\Service\Payment;

use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Request;

#[Package('checkout')]
class ClickCollectPaymentHandler extends AbstractPaymentHandler
{
    /**
     * Synchronous offline payment handler for Click & Collect.
     *
     * No redirect, no external capture. We deliberately leave the transaction
     * in its default "open" state so in-store payment can be recorded later.
     */
    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        // We only support the main PAY flow (no refund/recurring)
        return $type === PaymentHandlerType::PAY;
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?\Shopware\Core\Framework\Struct\Struct $validateStruct
    ): ?\Symfony\Component\HttpFoundation\RedirectResponse {
        // Synchronous offline: no redirect, keep transaction open and let checkout complete
        return null;
    }
}
