<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Middleware;

use Closure;
use EvanSchleret\FormForge\FormInstance;
use EvanSchleret\FormForge\FormManager;
use EvanSchleret\FormForge\Exceptions\FormNotFoundException;
use EvanSchleret\FormForge\Http\EndpointRequestGuard;
use EvanSchleret\FormForge\Http\HttpOptionsResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ApplyEndpointOptions
{
    public function __construct(
        private readonly FormManager $forms,
        private readonly HttpOptionsResolver $resolver,
        private readonly EndpointRequestGuard $requestGuard,
    ) {
    }

    public function handle(Request $request, Closure $next, string $endpoint, ?string $action = null): mixed
    {
        try {
            [$form, $options] = $this->resolve($request, $endpoint);
        } catch (FormNotFoundException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }

        if ($form !== null) {
            $request->attributes->set('formforge.form', $form);
        }

        if (is_string($action) && trim($action) !== '') {
            $request->attributes->set('formforge.endpoint.action', trim($action));
        }

        $key = trim((string) $request->route('key'));

        if ($key !== '') {
            $request->attributes->set('formforge.authorization.arguments', [$key]);
        }

        $request->attributes->set('formforge.http.options', $options);

        $this->requestGuard->protect($request, $options);

        return $this->requestGuard->runDynamicMiddleware(
            $request,
            $options['middleware'] ?? [],
            static fn (Request $request): mixed => $next($request),
        );
    }

    private function resolve(Request $request, string $endpoint): array
    {
        if ($endpoint === 'submission') {
            $form = $this->resolveSubmissionForm($request);

            return [$form, $this->resolver->resolve('submission', $form->toArray())];
        }

        if ($endpoint === 'schema') {
            $form = $this->resolveSchemaForm($request);

            return [$form, $this->resolver->resolve('schema', $form?->toArray())];
        }

        if ($endpoint === 'upload') {
            $form = $this->resolveSubmissionForm($request);

            return [$form, $this->resolver->resolve('upload', $form->toArray())];
        }

        if ($endpoint === 'resolve') {
            $form = $this->resolveSubmissionForm($request);

            return [$form, $this->resolver->resolve('resolve')];
        }

        if ($endpoint === 'draft') {
            $form = $this->resolveSubmissionForm($request);

            return [$form, $this->resolver->resolve('draft')];
        }

        if ($endpoint === 'management') {
            return [null, $this->resolver->resolve('management')];
        }

        return [null, $this->resolver->resolve($endpoint)];
    }

    private function resolveSubmissionForm(Request $request): FormInstance
    {
        $key = trim((string) $request->route('key'));

        if ($key === '') {
            throw FormNotFoundException::forKey($key);
        }

        $version = $request->route('version');

        if (! is_string($version) || trim($version) === '') {
            $version = $request->input('version');
        }

        if (! is_string($version) || trim($version) === '') {
            return $this->forms->get($key);
        }

        return $this->forms->get($key, trim($version));
    }

    private function resolveSchemaForm(Request $request): ?FormInstance
    {
        $key = trim((string) $request->route('key'));

        if ($key === '') {
            return null;
        }

        $version = $request->route('version');

        if (! is_string($version) || trim($version) === '') {
            return $this->forms->get($key);
        }

        return $this->forms->get($key, trim($version));
    }
}
