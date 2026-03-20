<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Submissions;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\FormInstance;
use EvanSchleret\FormForge\Models\FormDraft;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class DraftStateService
{
    public function save(FormInstance $form, array $payload, array $meta, Model $owner): FormDraft
    {
        $this->assertDraftsEnabled($form);

        [$ownerType, $ownerId] = $this->resolveOwner($owner);
        $normalizedPayload = $this->normalizePayload($form, $payload);

        $draft = FormDraft::query()->updateOrCreate(
            [
                'form_key' => $form->key(),
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
            ],
            [
                'form_version' => $form->version(),
                'payload' => $normalizedPayload,
                'meta' => $meta,
                'expires_at' => $this->resolveExpiration(),
            ],
        );

        return $draft->refresh();
    }

    public function current(FormInstance $form, Model $owner): ?FormDraft
    {
        $this->assertDraftsEnabled($form);

        [$ownerType, $ownerId] = $this->resolveOwner($owner);

        $draft = FormDraft::query()
            ->forForm($form->key())
            ->forOwner($ownerType, $ownerId)
            ->first();

        if (! $draft instanceof FormDraft) {
            return null;
        }

        if ($this->isExpired($draft)) {
            $draft->delete();

            return null;
        }

        return $draft;
    }

    public function delete(FormInstance $form, Model $owner): bool
    {
        $this->assertDraftsEnabled($form);

        [$ownerType, $ownerId] = $this->resolveOwner($owner);

        $deleted = FormDraft::query()
            ->forForm($form->key())
            ->forOwner($ownerType, $ownerId)
            ->delete();

        return $deleted > 0;
    }

    public function cleanupExpired(bool $dryRun = false, int $chunk = 500): array
    {
        $chunk = max(1, $chunk);

        $query = FormDraft::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now());

        $expired = (int) (clone $query)->count();

        $stats = [
            'dry_run' => $dryRun,
            'expired_drafts' => $expired,
            'deleted_drafts' => 0,
            'delete_failed' => 0,
        ];

        if ($dryRun || $expired === 0) {
            return $stats;
        }

        $query
            ->orderBy('id')
            ->chunkById($chunk, function ($drafts) use (&$stats): void {
                foreach ($drafts as $draft) {
                    if (! $draft instanceof FormDraft) {
                        continue;
                    }

                    try {
                        $deleted = $draft->delete();

                        if ($deleted) {
                            $stats['deleted_drafts']++;
                        } else {
                            $stats['delete_failed']++;
                        }
                    } catch (\Throwable) {
                        $stats['delete_failed']++;
                    }
                }
            });

        return $stats;
    }

    private function assertDraftsEnabled(FormInstance $form): void
    {
        if (! $form->draftsEnabled()) {
            throw new FormForgeException('Drafts are disabled for this form.');
        }
    }

    private function resolveOwner(Model $owner): array
    {
        $ownerType = trim((string) $owner->getMorphClass());
        $ownerId = trim((string) $owner->getKey());

        if ($ownerType === '' || $ownerId === '') {
            throw new FormForgeException('Unable to resolve draft owner.');
        }

        return [$ownerType, $ownerId];
    }

    private function resolveExpiration(): ?Carbon
    {
        $ttlDays = (int) config('formforge.drafts.ttl_days', 30);

        if ($ttlDays <= 0) {
            return null;
        }

        return Carbon::now()->addDays($ttlDays);
    }

    private function normalizePayload(FormInstance $form, array $payload): array
    {
        $known = [];

        foreach ($form->fields() as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? ''));

            if ($name !== '') {
                $known[$name] = true;
            }
        }

        if ($known === []) {
            return [];
        }

        $normalized = [];
        $unknown = [];

        foreach ($payload as $key => $value) {
            $name = trim((string) $key);

            if ($name === '') {
                continue;
            }

            if (! array_key_exists($name, $known)) {
                $unknown[] = $name;
                continue;
            }

            $normalized[$name] = $value;
        }

        $rejectUnknown = (bool) config('formforge.validation.reject_unknown_fields', true);

        if ($rejectUnknown && $unknown !== []) {
            throw new FormForgeException('Unknown draft fields: ' . implode(', ', $unknown) . '.');
        }

        return $normalized;
    }

    private function isExpired(FormDraft $draft): bool
    {
        if (! $draft->expires_at instanceof Carbon) {
            return false;
        }

        return $draft->expires_at->lessThanOrEqualTo(Carbon::now());
    }
}
