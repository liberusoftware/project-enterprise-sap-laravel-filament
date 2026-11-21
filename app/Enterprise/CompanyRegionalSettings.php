<?php

namespace App\Enterprise;

use DateTimeZone;
use Liberu\Foundation\Settings\Contracts\SettingDefinition;

final class CompanyRegionalSettings implements SettingDefinition
{
    public function key(): string
    {
        return 'enterprise.company_region';
    }

    public function validate(mixed $value): bool
    {
        return is_array($value)
            && is_string($value['locale'] ?? null)
            && array_key_exists($value['locale'], self::locales())
            && in_array($value['timezone'] ?? null, DateTimeZone::listIdentifiers(), true);
    }

    public function secret(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    public static function locales(): array
    {
        return config('localization.locales') ?: ['en' => 'English'];
    }

    /** @return array{locale: string, timezone: string} */
    public static function defaults(): array
    {
        $locales = self::locales();
        $locale = (string) (config('localization.site_locale') ?? config('app.fallback_locale'));
        $timezone = config('localization.site_timezone', 'UTC');

        return [
            'locale' => array_key_exists($locale, $locales) ? $locale : array_key_first($locales),
            'timezone' => in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC',
        ];
    }
}
