<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Http\Middleware;

use Closure;
use EvanSchleret\FormForge\Exceptions\FormForgeException;
use EvanSchleret\FormForge\Http\ScopedRouteManager;
use EvanSchleret\FormForge\Ownership\OwnershipReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ApplyRouteScope
{
    public function __construct(
        private readonly ScopedRouteManager $scopes,
    ) {
    }

    public function handle(Request $request, Closure $next, string $scopeName): mixed
    {
        $scope = $this->scopes->find($scopeName);

        if (! is_array($scope) || ! (bool) ($scope['enabled'] ?? true)) {
            throw new NotFoundHttpException();
        }

        $ownerModel = $this->resolveOwnerModel($request, $scope);
        $ownerReference = $this->resolveOwnerReference($request, $scope, $ownerModel);
        $required = (bool) (($scope['owner']['required'] ?? true) === true);

        if ($required && ! $ownerReference instanceof OwnershipReference) {
            throw new NotFoundHttpException();
        }

        $request->attributes->set('formforge.http.scope.name', $scope['name']);
        $request->attributes->set('formforge.http.scope', $scope);
        $request->attributes->set('formforge.http.scope.owner.model', $ownerModel);
        $request->attributes->set('formforge.http.scope.owner.reference', $ownerReference);
        $request->attributes->set('formforge.ownership.reference', $ownerReference);
        $request->attributes->set('formforge.ownership', $ownerReference?->toArray());

        return $next($request);
    }

    private function resolveOwnerModel(Request $request, array $scope): ?Model
    {
        $owner = $scope['owner'] ?? [];

        if (! is_array($owner)) {
            return null;
        }

        $routeParam = $owner['route_param'] ?? null;

        if (! is_string($routeParam) || trim($routeParam) === '') {
            return null;
        }

        $routeValue = $request->route($routeParam);

        if ($routeValue instanceof Model) {
            return $routeValue;
        }

        $modelClass = $owner['model'] ?? null;

        if (! is_string($modelClass) || trim($modelClass) === '') {
            return null;
        }

        if ($routeValue === null || is_array($routeValue) || is_object($routeValue)) {
            return null;
        }

        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            throw new FormForgeException("Invalid owner model class [{$modelClass}] in scoped route configuration.");
        }

        $routeKey = $owner['route_key'] ?? null;
        $routeKey = is_string($routeKey) && trim($routeKey) !== ''
            ? trim($routeKey)
            : (new $modelClass())->getRouteKeyName();

        /** @var class-string<Model> $modelClass */
        $resolved = $modelClass::query()
            ->where($routeKey, (string) $routeValue)
            ->first();

        if (! $resolved instanceof Model) {
            throw new NotFoundHttpException();
        }

        return $resolved;
    }

    private function resolveOwnerReference(Request $request, array $scope, ?Model $ownerModel): ?OwnershipReference
    {
        if ($ownerModel instanceof Model) {
            return OwnershipReference::from($ownerModel);
        }

        $owner = $scope['owner'] ?? [];

        if (! is_array($owner)) {
            return null;
        }

        $routeParam = $owner['route_param'] ?? null;
        $type = $owner['type'] ?? null;

        if (! is_string($routeParam) || trim($routeParam) === '') {
            return null;
        }

        if (! is_string($type) || trim($type) === '') {
            return null;
        }

        $routeValue = $request->route($routeParam);

        if ($routeValue === null || is_array($routeValue) || is_object($routeValue)) {
            return null;
        }

        return OwnershipReference::from([
            'type' => trim($type),
            'id' => (string) $routeValue,
        ]);
    }
}

