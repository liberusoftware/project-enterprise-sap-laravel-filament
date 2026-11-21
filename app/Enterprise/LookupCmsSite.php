<?php

namespace App\Enterprise;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

final readonly class LookupCmsSite
{
    public function __construct(private RemoteErpConnection $connection) {}

    public static function configured(): bool
    {
        return preg_match('/^[1-9][0-9]*$/D', (string) config('services.cms.site_id')) === 1
            && preg_match('/^[A-Za-z0-9_-]+$/D', (string) config('services.cms.site_key')) === 1;
    }

    /** @return array{name: string, status: string, locale: string, channels: list<string>} */
    public function handle(): array
    {
        abort_unless(self::configured(), 403);
        $key = (string) config('services.cms.site_key');
        $id = (string) config('services.cms.site_id');

        try {
            $response = $this->connection->get('cms', '/api/v1/sites/'.$key);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['site' => 'CMS is temporarily unavailable.']);
        }

        $site = $response->json('data');
        if (! $response->successful() || ! is_array($site) || ($site['type'] ?? null) !== 'cms-site'
            || ($site['id'] ?? null) !== $id || ($site['key'] ?? null) !== $key
            || ! is_string($site['name'] ?? null) || ! is_string($site['status'] ?? null)
            || ! is_string($site['default_locale'] ?? null) || ! is_array($site['channels'] ?? null)) {
            throw ValidationException::withMessages(['site' => 'CMS could not return the configured site.']);
        }

        $channels = [];
        foreach ($site['channels'] as $channel) {
            if (! is_array($channel) || ($channel['type'] ?? null) !== 'cms-channel'
                || ($channel['site_id'] ?? null) !== $id || ! is_string($channel['name'] ?? null)) {
                throw ValidationException::withMessages(['site' => 'CMS returned a channel outside the configured site or with invalid fields.']);
            }

            $channels[] = $channel['name'];
        }

        return ['name' => $site['name'], 'status' => $site['status'], 'locale' => $site['default_locale'], 'channels' => $channels];
    }
}
