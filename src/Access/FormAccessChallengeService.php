<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Access;

use EvanSchleret\FormForge\FormInstance;
use EvanSchleret\FormForge\Models\FormDefinition;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use EvanSchleret\FormForge\Persistence\FormDefinitionRepository;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class FormAccessChallengeService
{
    public function __construct(
        private readonly FormDefinitionRepository $definitions,
    ) {
    }

    public function isRequired(FormInstance $form, ?OwnershipReference $owner): bool
    {
        $definition = $this->definition($form, $owner);

        if (! $definition instanceof FormDefinition) {
            throw new FormAccessChallengeException('The form was not found.', 'not_found', 404);
        }

        return $this->accessCodeHash($definition) !== null;
    }

    public function createChallenge(
        FormInstance $form,
        ?OwnershipReference $owner,
        ?string $scopeName,
    ): array {
        $definition = $this->definition($form, $owner);
        $codeHash = $definition instanceof FormDefinition ? $this->accessCodeHash($definition) : null;

        if ($codeHash === null) {
            throw new FormAccessChallengeException('Access code is not enabled for this form.', 'not_found', 404);
        }

        $challengeId = bin2hex(random_bytes(32));
        $ttl = $this->ttl('challenge_ttl_seconds', 300);
        $expiresAt = now()->addSeconds($ttl);
        $this->cache()->put($this->challengeKey($challengeId), [
            'form_key' => $form->key(),
            'scope_name' => $scopeName,
            'owner' => $owner?->toArray(),
            'code_fingerprint' => hash('sha256', $codeHash),
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $ttl);

        return [
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function verifyChallenge(
        string $challengeId,
        string $code,
        FormInstance $form,
        ?OwnershipReference $owner,
        ?string $scopeName,
    ): array {
        $cache = $this->cache();
        $cacheKey = $this->challengeKey($challengeId);
        $maxAttempts = $this->ttl('max_attempts', 5);
        $lock = $cache->lock($cacheKey . ':lock', max(10, $this->ttl('challenge_ttl_seconds', 300)));

        try {
            return $lock->block(3, function () use ($cache, $cacheKey, $challengeId, $code, $form, $owner, $scopeName, $maxAttempts): array {
                $challenge = $cache->get($cacheKey);

                if (! is_array($challenge)) {
                    throw new FormAccessChallengeException('The access challenge has expired.', 'challenge_expired', 410);
                }

                if (! $this->matchesBinding($challenge, $form, $owner, $scopeName)) {
                    throw new FormAccessChallengeException('The access challenge was not found.', 'not_found', 404);
                }

                $definition = $this->definition($form, $owner);
                $codeHash = $definition instanceof FormDefinition ? $this->accessCodeHash($definition) : null;
                $fingerprint = is_string($codeHash) ? hash('sha256', $codeHash) : '';

                if ($codeHash === null || ! hash_equals((string) ($challenge['code_fingerprint'] ?? ''), $fingerprint)) {
                    $cache->forget($cacheKey);
                    throw new FormAccessChallengeException('The access challenge has expired.', 'challenge_expired', 410);
                }

                $attempts = (int) ($challenge['attempts'] ?? 0);

                if ($attempts >= $maxAttempts) {
                    $cache->forget($cacheKey);
                    throw new FormAccessChallengeException('The access challenge has no attempts remaining.', 'attempts_exceeded', 429, 0);
                }

                if (! Hash::check(trim($code), $codeHash)) {
                    $attempts++;
                    $remaining = max(0, $maxAttempts - $attempts);

                    if ($remaining === 0) {
                        $cache->forget($cacheKey);
                        throw new FormAccessChallengeException('The access challenge has no attempts remaining.', 'attempts_exceeded', 429, 0);
                    }

                    $expiresAt = (int) ($challenge['expires_at'] ?? 0);
                    $remainingTtl = max(0, $expiresAt - now()->getTimestamp());

                    if ($remainingTtl === 0) {
                        $cache->forget($cacheKey);
                        throw new FormAccessChallengeException('The access challenge has expired.', 'challenge_expired', 410);
                    }

                    $challenge['attempts'] = $attempts;
                    $cache->put($cacheKey, $challenge, $remainingTtl);

                    throw new FormAccessChallengeException('The access code is incorrect.', 'invalid_code', 422, $remaining);
                }

                $cache->forget($cacheKey);

                $accessToken = bin2hex(random_bytes(32));
                $authorizationTtl = $this->ttl('authorization_ttl_seconds', 900);
                $expiresAt = now()->addSeconds($authorizationTtl);
                $cache->put($this->authorizationKey($accessToken), [
                    'form_key' => $form->key(),
                    'scope_name' => $scopeName,
                    'owner' => $owner?->toArray(),
                    'code_fingerprint' => $fingerprint,
                    'expires_at' => $expiresAt->getTimestamp(),
                ], $authorizationTtl);

                return [
                    'access_token' => $accessToken,
                    'expires_at' => $expiresAt->toIso8601String(),
                ];
            });
        } catch (LockTimeoutException) {
            throw new FormAccessChallengeException('The access challenge is busy. Try again.', 'rate_limited', 429);
        }
    }

    public function authorizationError(
        ?string $accessToken,
        FormInstance $form,
        ?OwnershipReference $owner,
        ?string $scopeName,
    ): ?string {
        $definition = $this->definition($form, $owner);

        if (! $definition instanceof FormDefinition) {
            if (! $owner instanceof OwnershipReference) {
                return null;
            }

            if (is_string($accessToken) && trim($accessToken) !== '') {
                return 'access_invalid';
            }

            $unscopedDefinition = $this->definitions->latestActive($form->key());

            return $unscopedDefinition instanceof FormDefinition && $this->accessCodeHash($unscopedDefinition) !== null
                ? 'access_required'
                : null;
        }

        $codeHash = $this->accessCodeHash($definition);

        if ($codeHash === null) {
            return null;
        }

        if ($accessToken === null || trim($accessToken) === '') {
            return 'access_required';
        }

        $authorization = $this->cache()->get($this->authorizationKey($accessToken));

        if (! is_array($authorization)) {
            return 'access_invalid';
        }

        if (! $this->matchesBinding($authorization, $form, $owner, $scopeName)) {
            return 'access_invalid';
        }

        $fingerprint = hash('sha256', $codeHash);

        if (! hash_equals((string) ($authorization['code_fingerprint'] ?? ''), $fingerprint)) {
            return 'access_invalid';
        }

        return null;
    }

    private function definition(FormInstance $form, ?OwnershipReference $owner): ?FormDefinition
    {
        return $this->definitions->latestActive($form->key(), false, $owner);
    }

    private function accessCodeHash(FormDefinition $definition): ?string
    {
        $meta = is_array($definition->meta) ? $definition->meta : [];
        $hash = $meta['access_code_hash'] ?? null;

        return is_string($hash) && trim($hash) !== '' ? $hash : null;
    }

    private function matchesBinding(array $state, FormInstance $form, ?OwnershipReference $owner, ?string $scopeName): bool
    {
        $expectedOwner = $owner?->toArray();

        return ($state['form_key'] ?? null) === $form->key()
            && ($state['scope_name'] ?? null) === $scopeName
            && ($state['owner'] ?? null) === $expectedOwner;
    }

    private function challengeKey(string $challengeId): string
    {
        return 'formforge:access:challenge:' . hash('sha256', $challengeId);
    }

    private function authorizationKey(string $accessToken): string
    {
        return 'formforge:access:authorization:' . hash('sha256', $accessToken);
    }

    private function cache(): Repository
    {
        $store = config('formforge.access_challenge.cache_store');

        return Cache::store(is_string($store) && trim($store) !== '' ? trim($store) : null);
    }

    private function ttl(string $key, int $fallback): int
    {
        return max(1, (int) config('formforge.access_challenge.' . $key, $fallback));
    }
}
