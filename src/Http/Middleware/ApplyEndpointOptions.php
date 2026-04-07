<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Middleware;

use Closure;
use EvanSchleret\FormForge\FormInstance;
use EvanSchleret\FormForge\FormManager;
use EvanSchleret\FormForge\Exceptions\FormNotFoundException;
use EvanSchleret\FormForge\Http\Authorization\ScopedRouteAuthorizer;
use EvanSchleret\FormForge\Http\EndpointRequestGuard;
use EvanSchleret\FormForge\Http\HttpOptionsResolver;
use EvanSchleret\FormForge\Ownership\OwnershipManager;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ApplyEndpointOptions
{
    public function __construct(
        private readonly FormManager $forms,
        private readonly HttpOptionsResolver $resolver,
        private readonly EndpointRequestGuard $requestGuard,
        private readonly OwnershipManager $ownership,
        private readonly ScopedRouteAuthorizer $scopedAuthorization,
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

        foreach (['key', 'version', 'categoryKey', 'submissionUuid', 'fromVersion', 'toVersion'] as $parameter) {
            $resolved = $this->routeParameter($request, $parameter);

            if ($resolved !== '') {
                $request->attributes->set('formforge.route.' . $parameter, $resolved);
            }
        }

        $ownership = $this->ownership->resolve($request, $endpoint, $action);
        $request->attributes->set('formforge.ownership', $ownership?->toArray());
        $request->attributes->set('formforge.ownership.reference', $ownership);
        $this->ownership->assertRequestAuthorized($request, $endpoint, $action, $ownership);
        $this->scopedAuthorization->authorize($request, $endpoint, $action, $ownership);

        $key = $this->routeParameter($request, 'key');

        if ($key === '') {
            $key = $this->routeParameter($request, 'categoryKey');
        }

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
        $key = $this->routeParameter($request, 'key');

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
        $key = $this->routeParameter($request, 'key');

        if ($key === '') {
            return null;
        }

        $version = $request->route('version');

        if (! is_string($version) || trim($version) === '') {
            return $this->forms->get($key);
        }

        return $this->forms->get($key, trim($version));
    }

    private function routeParameter(Request $request, string $name): string
    {
        $fromPath = $this->routeParameterFromPath($request, $name);

        if ($fromPath !== '') {
            return $fromPath;
        }

        $route = $request->route();

        if (! is_object($route) || ! method_exists($route, 'parameter')) {
            return '';
        }

        $resolved = $this->normalizeRouteParameterValue($route->parameter($name));

        if ($resolved !== '') {
            return $resolved;
        }

        if (method_exists($route, 'originalParameter')) {
            return $this->normalizeRouteParameterValue($route->originalParameter($name));
        }

        return '';
    }

    private function routeParameterFromPath(Request $request, string $name): string
    {
        $route = $request->route();

        if (! is_object($route) || ! method_exists($route, 'uri')) {
            return '';
        }

        $template = trim((string) $route->uri(), '/');
        $path = trim((string) $request->path(), '/');

        if ($template === '' || $path === '') {
            return '';
        }

        $templateSegments = explode('/', $template);
        $pathSegments = explode('/', $path);

        if (count($templateSegments) !== count($pathSegments)) {
            return '';
        }

        foreach ($templateSegments as $index => $segment) {
            if (! preg_match('/^\{([^}:]+)(?::[^}]+)?\}$/', $segment, $matches)) {
                continue;
            }

            $parameter = trim((string) ($matches[1] ?? ''));

            if ($parameter !== $name) {
                continue;
            }

            $value = $pathSegments[$index] ?? '';

            return trim(urldecode((string) $value));
        }

        return '';
    }

    private function normalizeRouteParameterValue(mixed $value): string
    {
        if ($value instanceof Model) {
            $key = $value->getRouteKey();

            return is_scalar($key) ? trim((string) $key) : '';
        }

        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
