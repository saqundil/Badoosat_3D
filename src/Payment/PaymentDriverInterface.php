<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * The seam a gateway will be dropped into later.
 *
 * Nothing above this interface knows how money moves. Cash on delivery is the
 * whole of the local market today, so it is the only implementation shipped --
 * but checkout already routes through the interface, which is the difference
 * between adding a gateway later and rewriting checkout later.
 */
interface PaymentDriverInterface
{
    /** Machine name, stored on the order so a refund knows who to ask. */
    public function name(): string;

    /** What the customer sees when choosing how to pay. */
    public function label(): string;

    /** False when the driver's credentials are missing or its extension is not loaded. */
    public function isAvailable(): bool;

    /**
     * Opens payment for an order that has just been written.
     *
     * @param array<string,mixed> $order
     * @return array{status:string,redirect:?string,reference:?string}
     *         status is one of 'paid', 'pending', 'redirect'
     */
    public function createIntent(array $order): array;

    /**
     * Verifies a gateway's callback before a single field of the order moves.
     *
     * @param array<string,mixed> $payload
     */
    public function verifyCallback(array $payload): bool;
}
