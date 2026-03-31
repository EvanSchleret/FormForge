<?php

declare(strict_types=1);

namespace EvanSchleret\FormForge\Tests\Fixtures\Controllers;

use EvanSchleret\FormForge\Http\Controllers\FormManagementController;
use EvanSchleret\FormForge\Management\FormMutationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomFormManagementController extends FormManagementController
{
    public function index(Request $request, FormMutationService $mutations): JsonResponse
    {
        return response()->json([
            'data' => [
                'data' => [],
            ],
            'meta' => [
                'custom_controller' => true,
            ],
        ]);
    }
}
