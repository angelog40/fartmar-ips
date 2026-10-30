<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiInventoryAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAssistantController extends Controller
{
    public function __construct(private readonly AiInventoryAssistantService $aiService) {}

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $result = $this->aiService->ask($validated['question']);

        return response()->json([
            'data' => $result,
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]);
    }
}
