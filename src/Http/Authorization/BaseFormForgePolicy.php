<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Authorization;

class BaseFormForgePolicy
{
    public function before(mixed $user, FormForgeAuthorizationContext $context): ?bool
    {
        return null;
    }

    public function schema_latest(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function schema_versions(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function schema_show(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function submission_submit_latest(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function submission_submit_version(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function upload_stage_latest(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function upload_stage_version(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function resolve_resolve_latest(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function resolve_resolve_version(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function draft_save(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function draft_current(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function draft_delete(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_index(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_categories(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_category(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_category_create(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_category_update(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_category_delete(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_create(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_update(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_publish(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_unpublish(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_delete(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_revisions(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_diff(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_responses(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_responses_export(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_response(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_response_delete(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_gdpr_policy(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_response_gdpr_anonymize(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_response_gdpr_delete(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }

    public function management_gdpr_run(mixed $user, FormForgeAuthorizationContext $context): bool
    {
        return false;
    }
}
