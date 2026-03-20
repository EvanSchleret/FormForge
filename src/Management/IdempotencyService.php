<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Management;

use EvanSchleret\FormForge\Exceptions\FormConflictException;
use EvanSchleret\FormForge\Models\IdempotencyKey;
use Illuminate\Support\Carbon;

class IdempotencyService
{
    public function replay(
        ?string $idempotencyKey,
        string $endpoint,
        string $method,
        string $requestHash,
    ): ?array {
        $key = $this->normalizeKey($idempotencyKey);

        if ($key === null) {
            return null;
        }

        $record = IdempotencyKey::query()
            ->where('idempotency_key', $key)
            ->where('endpoint', $endpoint)
            ->where('method', strtoupper($method))
            ->first();

        if ($record === null) {
            return null;
        }

        if ((string) $record->request_hash !== $requestHash) {
            throw new FormConflictException('Idempotency key cannot be reused with a different payload.');
        }

        $body = is_array($record->response_body) ? $record->response_body : [];

        if (! isset($body['meta']) || ! is_array($body['meta'])) {
            $body['meta'] = [];
        }

        $body['meta']['replayed'] = true;

        return [
            'status_code' => (int) $record->status_code,
            'body' => $body,
            'replayed' => true,
        ];
    }

    public function store(
        ?string $idempotencyKey,
        string $endpoint,
        string $method,
        ?string $resourceKey,
        string $requestHash,
        int $statusCode,
        array $responseBody,
        ?string $revisionId = null,
        ?int $versionNumber = null,
    ): void {
        $key = $this->normalizeKey($idempotencyKey);

        if ($key === null) {
            return;
        }

        $ttl = (int) config('formforge.http.idempotency.ttl_minutes', 1440);
        $expiresAt = $ttl > 0 ? Carbon::now()->addMinutes($ttl) : null;

        IdempotencyKey::query()->updateOrCreate(
            [
                'idempotency_key' => $key,
                'endpoint' => $endpoint,
                'method' => strtoupper($method),
            ],
            [
                'resource_key' => $resourceKey,
                'request_hash' => $requestHash,
                'status_code' => $statusCode,
                'response_body' => $responseBody,
                'response_revision_id' => $revisionId,
                'response_version_number' => $versionNumber,
                'expires_at' => $expiresAt,
            ],
        );
    }

    public function payloadHash(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            $json = '{}';
        }

        return hash('sha256', $json);
    }

    private function normalizeKey(?string $idempotencyKey): ?string
    {
        if (! is_string($idempotencyKey)) {
            return null;
        }

        $idempotencyKey = trim($idempotencyKey);

        return $idempotencyKey === '' ? null : $idempotencyKey;
    }
}
