<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Robot;
use App\Services\Scraping\ExecutionRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RobotController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $robots = $request->user()->robots()
            ->with(['executions' => fn ($query) => $query->latest()->limit(1)])
            ->latest()
            ->get();

        return response()->json(['data' => $robots]);
    }

    public function store(Request $request, ExecutionRunner $runner): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'url' => ['required', 'url', 'max:2048', 'starts_with:https://'],
            'interval_minutes' => ['sometimes', 'integer', Rule::in([1, 2, 5, 10, 15, 30])],
            'output_format' => ['required', Rule::in(['json', 'html', 'csv', 'markdown'])],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'monitor_enabled' => ['sometimes', 'boolean'],
        ]);
        $validated['interval_minutes'] ??= 15;

        $robot = $request->user()->robots()->create($validated);
        $execution = $runner->run($robot);

        return response()->json([
            'data' => [
                'robot' => $robot,
                'execution' => ExecutionController::payload($execution->load('robot')),
            ],
        ], 201);
    }

    public function update(Request $request, Robot $robot): JsonResponse
    {
        abort_unless((int) $robot->user_id === (int) $request->user()->id, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:3', 'max:120'],
            'interval_minutes' => ['sometimes', 'integer', Rule::in([1, 2, 5, 10, 15, 30])],
            'monitor_enabled' => ['sometimes', 'boolean'],
            'instructions' => ['nullable', 'string', 'max:5000'],
        ]);

        $robot->update($validated);

        return response()->json(['data' => $robot->fresh()]);
    }
}
