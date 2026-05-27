<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support;

use Illuminate\Http\Request;

class ValidationLocaleResolver
{
    public function resolve(?string $locale = null): string
    {
        $supported = $this->supportedLocales();
        $fallback = $this->fallbackLocale($supported);

        $candidates = [
            $locale,
            $this->fromRequest(),
            (string) config('formforge.validation.locale', ''),
            app()->getLocale(),
            $fallback,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalize($candidate);

            if ($normalized === null) {
                continue;
            }

            if (in_array($normalized, $supported, true)) {
                return $normalized;
            }
        }

        return $fallback;
    }

    private function fromRequest(): ?string
    {
        if (! (bool) config('formforge.validation.allow_request_locale', true)) {
            return null;
        }

        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        if (! $request instanceof Request) {
            return null;
        }

        $queryParam = trim((string) config('formforge.validation.locale_query_param', 'formforge_locale'));
        if ($queryParam !== '') {
            $value = $request->query($queryParam);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        $headerName = trim((string) config('formforge.validation.locale_header', 'X-FormForge-Locale'));

        if ($headerName !== '') {
            $value = $request->header($headerName);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        $accept = $request->getPreferredLanguage($this->supportedLocales());

        return is_string($accept) ? $accept : null;
    }

    private function supportedLocales(): array
    {
        $configured = config('formforge.validation.supported_locales', ['en', 'fr']);

        if (! is_array($configured) || $configured === []) {
            return ['en'];
        }

        $normalized = [];

        foreach ($configured as $locale) {
            $value = $this->normalize($locale);

            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        return $normalized === [] ? ['en'] : array_values(array_unique($normalized));
    }

    private function fallbackLocale(array $supported): string
    {
        $fallback = $this->normalize(config('formforge.validation.fallback_locale', 'en'));

        if ($fallback !== null && in_array($fallback, $supported, true)) {
            return $fallback;
        }

        return $supported[0] ?? 'en';
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = strtolower(trim($value));

        if ($trimmed === '') {
            return null;
        }

        if (str_contains($trimmed, '-')) {
            $trimmed = explode('-', $trimmed)[0];
        }

        return $trimmed;
    }
}
