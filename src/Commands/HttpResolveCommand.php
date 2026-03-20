<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Commands;

use EvanSchleret\FormForge\Exceptions\FormNotFoundException;
use EvanSchleret\FormForge\FormManager;
use EvanSchleret\FormForge\Http\HttpOptionsResolver;
use Illuminate\Console\Command;

class HttpResolveCommand extends Command
{
    protected $signature = 'formforge:http:resolve {formKey} {--form-version=} {--endpoint=submission}';

    protected $description = 'Resolve effective FormForge HTTP options for a form and endpoint';

    public function handle(FormManager $forms, HttpOptionsResolver $resolver): int
    {
        $key = (string) $this->argument('formKey');
        $endpoint = trim((string) $this->option('endpoint'));
        $version = $this->option('form-version');

        if (! is_string($version) || trim($version) === '') {
            $version = null;
        }

        if (! in_array($endpoint, ['schema', 'submission', 'upload', 'management'], true)) {
            $this->error("Unsupported endpoint [{$endpoint}]. Allowed: schema, submission, upload, management.");

            return self::FAILURE;
        }

        try {
            $form = $forms->get($key, $version);
        } catch (FormNotFoundException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $options = $resolver->resolve($endpoint, $form->toArray());

        $this->info("Form: {$form->key()}@{$form->version()}");
        $this->line('Endpoint: ' . $endpoint);
        $this->newLine();

        $json = json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->line($json === false ? '{}' : $json);

        return self::SUCCESS;
    }
}
