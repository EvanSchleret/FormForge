<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Resources;

use EvanSchleret\FormForge\Models\FormCategory;
use EvanSchleret\FormForge\Models\FormDefinition;
use EvanSchleret\FormForge\Support\FormPublicLinkResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormDefinitionHttpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var FormDefinition $definition */
        $definition = $this->resource;
        $schema = is_array($definition->schema) ? $definition->schema : [];
        $meta = is_array($definition->meta) ? $definition->meta : [];
        $category = $definition->relationLoaded('categoryModel')
            ? $definition->getRelation('categoryModel')
            : $definition->categoryModel()->first();
        $schemaCategory = $this->normalizedOptionalString($schema['category'] ?? null);
        $legacyCategory = $this->normalizedOptionalString($definition->category ?? null);
        $categoryKey = $category instanceof FormCategory
            ? (string) $category->key
            : ($schemaCategory ?? $legacyCategory);
        $categoryItem = $category instanceof FormCategory
            ? [
                'id' => (int) $category->getKey(),
                'key' => (string) $category->key,
                'slug' => is_string($category->slug) ? trim((string) $category->slug) : null,
                'name' => (string) $category->name,
                'description' => $category->description,
                'is_active' => (bool) $category->is_active,
                'is_system' => (bool) $category->is_system,
                'meta' => is_array($category->meta) ? $category->meta : [],
                'owner_type' => $category->owner_type,
                'owner_id' => $category->owner_id,
                'created_at' => $category->created_at?->toIso8601String(),
                'updated_at' => $category->updated_at?->toIso8601String(),
            ]
            : null;

        return [
            'key' => (string) $definition->key,
            'form_uuid' => (string) ($definition->form_uuid ?? ''),
            'revision_id' => (string) ($definition->revision_id ?? ''),
            'version' => (string) $definition->version,
            'version_number' => (int) ($definition->version_number ?? 0),
            'title' => (string) ($definition->title ?? ''),
            'category' => $categoryKey,
            'category_item' => $categoryItem,
            'is_active' => (bool) $definition->is_active,
            'is_published' => (bool) $definition->is_published,
            'published_at' => $definition->published_at?->toIso8601String(),
            'schema_hash' => (string) $definition->schema_hash,
            'schema' => $schema,
            'meta' => $meta,
            'public_url' => $this->resolvePublicUrl($definition, $request),
            'created_by_type' => $definition->created_by_type,
            'created_by_id' => $definition->created_by_id,
            'updated_by_type' => $definition->updated_by_type,
            'updated_by_id' => $definition->updated_by_id,
            'owner_type' => $definition->owner_type,
            'owner_id' => $definition->owner_id,
            'created_at' => $definition->created_at?->toIso8601String(),
            'updated_at' => $definition->updated_at?->toIso8601String(),
            'deleted_at' => $definition->deleted_at?->toIso8601String(),
        ];
    }

    private function normalizedOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function resolvePublicUrl(FormDefinition $definition, Request $request): ?string
    {
        $configured = config('formforge.http.public_link.resolver');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        if (! class_exists($configured) || ! is_subclass_of($configured, FormPublicLinkResolver::class)) {
            return null;
        }

        $resolver = app($configured);

        if (! $resolver instanceof FormPublicLinkResolver) {
            return null;
        }

        return $resolver->resolve([
            'key' => (string) $definition->key,
            'owner_type' => $definition->owner_type,
            'owner_id' => $definition->owner_id,
            'schema' => $definition->schema,
            'meta' => $definition->meta,
        ], $request);
    }
}
