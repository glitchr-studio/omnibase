<?php

namespace Base\Service;

interface LocalizerInterface
{
    //
    // Locale, lang&country
    public static function __toLocale(string $locale, ?string $separator = Localizer::SEPARATOR): string;

    public static function __toLocaleLang(string $locale): string;

    public static function __toLocaleCountry(string $locale): string;

    public static function normalizeLocale(string|array $locale, string $separator = Localizer::SEPARATOR): string|array;

    public static function getDefaultLocale(): ?string;

    public static function getDefaultLocaleLang(): ?string;

    public static function getDefaultLocaleCountry(): ?string;

    public static function getFallbackLocales(): array;

    public static function getFallbackLocaleLangs(): array;

    public static function getFallbackLocaleCountries(): array;

    public static function getAvailableLocaleLangs(): array;

    public static function getAvailableLocaleCountries(): array;

    public static function getAvailableLocales(): array;

    public static function markAsLate();

    public function markAsChanged();

    public function setCountry(string $countryCode): self;

    public function setTimezone(string $timezone): self;

    public function getLocale(?string $locale = null): string;

    public function setLocale(string $locale);

    public function compatibleLocale(string $locale, string $preferredLocale, ?array $availableLocales = null): bool;

    public function getLocaleLang(?string $locale = null): string;

    public function getLocaleLangName(?string $locale = null): ?string;

    public function getLocaleCountry(?string $locale = null): string;

    public function getLocaleCountryName(?string $locale = null): string;

    public function getCountryName(?string $countryCode, ?string $displayLocale = null): string;

    //
    // Timezone, currency&country
    public static function getDefaultTimezone(): string;

    public static function getAvailableTimezones(): array;

    public function getTimezone(): string;
}
