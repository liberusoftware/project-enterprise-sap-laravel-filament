<?php

namespace App\Enterprise;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Telescope\Telescope;
use Liberu\Foundation\Organizations\Models\Team;

final class RemoteErpConnection
{
    public static function configured(string $service): bool
    {
        $url = (string) config("services.{$service}.url");

        return filled(config("services.{$service}.token")) && filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_SCHEME) === 'https'
            && ! str_contains($url, '@') && ! str_contains($url, '?') && ! str_contains($url, '#');
    }

    public static function allows(string $service, mixed $user): bool
    {
        return self::allowsTeam($service, $user, $user instanceof User ? $user->currentTeam : null);
    }

    public static function allowsTeam(string $service, mixed $user, mixed $team): bool
    {
        return $user instanceof User && $team instanceof Team && $user->ownsTeam($team)
            && (string) $team->getKey() === (string) config("services.{$service}.team_id")
            && self::configured($service);
    }

    public function get(string $service, string $path, array $query = []): Response
    {
        abort_unless(self::configured($service), 403);
        $base = rtrim((string) config("services.{$service}.url"), '/');

        $request = static fn (): Response => Http::acceptJson()->withToken((string) config("services.{$service}.token"))
            ->connectTimeout(3)->timeout(5)->withoutRedirecting()->get($base.$path, $query);

        return class_exists(Telescope::class) ? Telescope::withoutRecording($request) : $request();
    }
}
