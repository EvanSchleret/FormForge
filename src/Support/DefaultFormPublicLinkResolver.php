<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support;

use Illuminate\Http\Request;

class DefaultFormPublicLinkResolver implements FormPublicLinkResolver
{
    public function resolve(array $context, Request $request): ?string
    {
        $key = trim((string) ($context['key'] ?? ''));

        if ($key === '') {
            return null;
        }

        $baseUrl = $this->resolveBaseUrl($request);
        $prefix = trim((string) config('formforge.http.prefix', 'api/formforge/v1'), '/');
        $path = trim($prefix . '/forms/' . $key, '/');

        return rtrim($baseUrl, '/') . '/' . $path;
    }

    private function resolveBaseUrl(Request $request): string
    {
        $configured = config('formforge.http.public_link.base_url');

        if (is_string($configured)) {
            $configured = trim($configured);

            if ($configured !== '') {
                return rtrim($configured, '/');
            }
        }

        return rtrim($request->getSchemeAndHttpHost(), '/');
    }
}
