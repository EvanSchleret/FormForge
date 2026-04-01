<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Authorization;

class AuthorizationActionMap
{
    public static function all(): array
    {
        return [
            'schema.latest' => 'schema_latest',
            'schema.versions' => 'schema_versions',
            'schema.show' => 'schema_show',
            'submission.submit_latest' => 'submission_submit_latest',
            'submission.submit_version' => 'submission_submit_version',
            'upload.stage_latest' => 'upload_stage_latest',
            'upload.stage_version' => 'upload_stage_version',
            'resolve.resolve_latest' => 'resolve_resolve_latest',
            'resolve.resolve_version' => 'resolve_resolve_version',
            'draft.save' => 'draft_save',
            'draft.current' => 'draft_current',
            'draft.delete' => 'draft_delete',
            'management.index' => 'management_index',
            'management.categories' => 'management_categories',
            'management.category' => 'management_category',
            'management.category_create' => 'management_category_create',
            'management.category_update' => 'management_category_update',
            'management.category_delete' => 'management_category_delete',
            'management.create' => 'management_create',
            'management.update' => 'management_update',
            'management.publish' => 'management_publish',
            'management.unpublish' => 'management_unpublish',
            'management.delete' => 'management_delete',
            'management.revisions' => 'management_revisions',
            'management.diff' => 'management_diff',
            'management.responses' => 'management_responses',
            'management.response' => 'management_response',
            'management.response_delete' => 'management_response_delete',
        ];
    }

    public static function methodFor(string $endpoint, ?string $action): ?string
    {
        $endpoint = trim($endpoint);
        $action = is_string($action) ? trim($action) : '';

        if ($endpoint === '' || $action === '') {
            return null;
        }

        $key = $endpoint . '.' . $action;
        $map = self::all();

        return $map[$key] ?? null;
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function methods(): array
    {
        return array_values(self::all());
    }
}

