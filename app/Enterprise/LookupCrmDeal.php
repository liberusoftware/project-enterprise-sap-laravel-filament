<?php

namespace App\Enterprise;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

final readonly class LookupCrmDeal
{
    public function __construct(private RemoteErpConnection $connection) {}

    /** @return array{id: int, name: string, stage: string|null} */
    public function handle(int $dealId): array
    {
        $teamId = (int) config('services.crm.remote_team_id');
        abort_unless($teamId > 0, 403);

        try {
            $response = $this->connection->get('crm', '/api/v1/deals/'.$dealId);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['deal' => 'CRM is temporarily unavailable.']);
        }

        $deal = $response->json();
        if (! $response->successful() || ! is_array($deal) || ($deal['id'] ?? null) !== $dealId
            || ($deal['team_id'] ?? null) !== $teamId || ! is_string($deal['name'] ?? null)
            || ! is_string($deal['stage'] ?? '')) {
            throw ValidationException::withMessages(['deal' => 'CRM could not return this deal for the configured team.']);
        }

        return ['id' => $dealId, 'name' => $deal['name'], 'stage' => $deal['stage'] ?? null];
    }
}
