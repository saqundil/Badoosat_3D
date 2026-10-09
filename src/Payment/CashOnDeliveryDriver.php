<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * The customer pays the courier. There is nothing to authorise and nothing to
 * capture, so the order is left unpaid and moves to production on the shop's
 * own decision -- which is the honest model: money has not changed hands.
 */
final class CashOnDeliveryDriver implements PaymentDriverInterface
{
    public function name(): string
    {
        return 'cod';
    }

    public function label(): string
    {
        return 'الدفع عند الاستلام';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function createIntent(array $order): array
    {
        return ['status' => 'pending', 'redirect' => null, 'reference' => null];
    }

    /**
     * There is no gateway to call back. Returning false rather than true is
     * deliberate: a request claiming to be a payment callback for a cash order
     * is either a mistake or an attempt, and neither should be honoured.
     */
    public function verifyCallback(array $payload): bool
    {
        return false;
    }
}
