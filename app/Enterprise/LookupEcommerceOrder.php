<?php

namespace App\Enterprise;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

final readonly class LookupEcommerceOrder
{
    public function __construct(private RemoteErpConnection $connection) {}

    /** @return array{id: int, total: string, currency: string, payment: string, shipping: string} */
    public function handle(int $orderId): array
    {
        try {
            $response = $this->connection->get('ecommerce', '/api/orders/'.$orderId);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['order' => 'Ecommerce is temporarily unavailable.']);
        }

        $order = $response->json('data');
        if (! $response->successful() || ! is_array($order) || ($order['id'] ?? null) !== $orderId
            || ! is_numeric($order['total_amount'] ?? null) || ! is_string($order['currency_code'] ?? null)
            || ! is_string($order['payment_status'] ?? null) || ! is_string($order['shipping_status'] ?? '')) {
            throw ValidationException::withMessages(['order' => 'Ecommerce could not return this order for the configured Ecommerce account.']);
        }

        return [
            'id' => $orderId,
            'total' => (string) $order['total_amount'],
            'currency' => $order['currency_code'],
            'payment' => $order['payment_status'],
            'shipping' => $order['shipping_status'] ?? 'Pending',
        ];
    }
}
