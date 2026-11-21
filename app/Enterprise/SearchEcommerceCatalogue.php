<?php

namespace App\Enterprise;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

final readonly class SearchEcommerceCatalogue
{
    public function __construct(private RemoteErpConnection $connection) {}

    /** @return array{products: list<array{id: int, name: string, price: string|null}>, page: int, last: int} */
    public function handle(string $search, int $page): array
    {
        $storeId = (int) config('services.ecommerce.store_id');
        abort_unless($storeId > 0, 403);

        try {
            $response = $this->connection->get('ecommerce', '/api/products', ['search' => $search, 'page' => $page, 'per_page' => 15]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['search' => 'Ecommerce is temporarily unavailable.']);
        }

        $result = $response->json();
        if (! $response->successful() || ! is_array($result) || ! is_array($result['data'] ?? null)
            || ! array_is_list($result['data']) || count($result['data']) > 15
            || ($result['current_page'] ?? null) !== $page || ! is_int($result['last_page'] ?? null)) {
            throw ValidationException::withMessages(['search' => 'Ecommerce could not return this catalogue page.']);
        }

        $products = [];
        foreach ($result['data'] as $product) {
            if (! is_array($product) || ($product['store_id'] ?? null) !== $storeId
                || ! is_int($product['id'] ?? null) || ! is_string($product['name'] ?? null)
                || (isset($product['price']) && ! is_numeric($product['price']))) {
                throw ValidationException::withMessages(['search' => 'Ecommerce returned an invalid catalogue entry.']);
            }

            $products[] = [
                'id' => $product['id'], 'name' => $product['name'],
                'price' => isset($product['price']) ? (string) $product['price'] : null,
            ];
        }

        return ['products' => $products, 'page' => $page, 'last' => $result['last_page']];
    }
}
