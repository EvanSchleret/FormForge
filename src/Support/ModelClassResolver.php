<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Support;

use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Models\FormCategory;
use EvanSchleret\FormForge\Models\FormDefinition;
use EvanSchleret\FormForge\Models\FormDraft;
use EvanSchleret\FormForge\Models\FormSubmission;
use EvanSchleret\FormForge\Models\IdempotencyKey;
use EvanSchleret\FormForge\Models\StagedUpload;
use EvanSchleret\FormForge\Models\SubmissionAutomationRun;
use EvanSchleret\FormForge\Models\SubmissionFile;
use EvanSchleret\FormForge\Models\SubmissionPrivacyOverride;
use EvanSchleret\FormForge\Models\SubmissionPrivacyPolicy;

class ModelClassResolver
{
    public static function formDefinition(): string
    {
        return self::resolve('form_definition', FormDefinition::class);
    }

    public static function formCategory(): string
    {
        return self::resolve('form_category', FormCategory::class);
    }

    public static function formSubmission(): string
    {
        return self::resolve('form_submission', FormSubmission::class);
    }

    public static function submissionFile(): string
    {
        return self::resolve('submission_file', SubmissionFile::class);
    }

    public static function stagedUpload(): string
    {
        return self::resolve('staged_upload', StagedUpload::class);
    }

    public static function idempotencyKey(): string
    {
        return self::resolve('idempotency_key', IdempotencyKey::class);
    }

    public static function formDraft(): string
    {
        return self::resolve('form_draft', FormDraft::class);
    }

    public static function submissionAutomationRun(): string
    {
        return self::resolve('submission_automation_run', SubmissionAutomationRun::class);
    }

    public static function submissionPrivacyPolicy(): string
    {
        return self::resolve('submission_privacy_policy', SubmissionPrivacyPolicy::class);
    }

    public static function submissionPrivacyOverride(): string
    {
        return self::resolve('submission_privacy_override', SubmissionPrivacyOverride::class);
    }

    private static function resolve(string $configKey, string $default): string
    {
        $configured = config("formforge.models.{$configKey}", $default);

        if (! is_string($configured) || trim($configured) === '') {
            return $default;
        }

        $class = trim($configured);

        if (! class_exists($class)) {
            throw new FormForgeException("Configured model class [{$class}] for [formforge.models.{$configKey}] does not exist.");
        }

        if ($class !== $default && ! is_subclass_of($class, $default)) {
            throw new FormForgeException("Configured model class [{$class}] for [formforge.models.{$configKey}] must extend [{$default}].");
        }

        return $class;
    }
}
