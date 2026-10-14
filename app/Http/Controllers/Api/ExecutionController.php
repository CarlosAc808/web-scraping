<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\Robot;
use App\Services\Scraping\ExecutionRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'robot_id' => ['sometimes', 'integer'],
            'latest_only' => ['sometimes', 'boolean'],
        ]);

        $executions = Execution::query()
            ->with('robot:id,user_id,name,url,output_format,interval_minutes,monitor_enabled')
            ->whereHas('robot', function ($query) use ($request, $validated): void {
                $query->where('user_id', $request->user()->id);
                if (! empty($validated['search'])) {
                    $search = '%'.$validated['search'].'%';
                    $query->where(function ($filter) use ($search): void {
                        $filter->where('name', 'like', $search)
                            ->orWhere('url', 'like', $search);
                    });
                }
            });

        if (! empty($validated['robot_id'])) {
            $executions->where('robot_id', $validated['robot_id']);
        }

        if (! empty($validated['latest_only'])) {
            $latestIds = Execution::query()
                ->whereHas('robot', fn ($q) => $q->where('user_id', $request->user()->id))
                ->selectRaw('MAX(id) as id')
                ->groupBy('robot_id')
                ->pluck('id');

            $executions->whereIn('id', $latestIds);
        }

        $executions = $executions->latest()->paginate($validated['per_page'] ?? 5);

        return response()->json([
            'data' => $executions->getCollection()->map(fn (Execution $execution) => self::payload($execution)),
            'meta' => [
                'current_page' => $executions->currentPage(),
                'last_page' => $executions->lastPage(),
                'total' => $executions->total(),
            ],
        ]);
    }

    public function store(Request $request, Robot $robot, ExecutionRunner $runner): JsonResponse
    {
        abort_unless((int) $robot->user_id === (int) $request->user()->id, 404);

        $execution = $runner->run($robot);

        return response()->json(['data' => self::payload($execution->load('robot'))], 201);
    }

    public function show(Request $request, Execution $execution): JsonResponse
    {
        $execution->load('robot');
        abort_unless((int) $execution->robot->user_id === (int) $request->user()->id, 404);

        return response()->json(['data' => self::payload($execution)]);
    }

    public function destroy(Request $request, Execution $execution): JsonResponse
    {
        abort_unless(
            $execution->robot()->where('user_id', $request->user()->id)->exists(),
            404,
        );
        $execution->delete();

        return response()->json(['message' => 'Ejecución eliminada.']);
    }

    public function priceHistory(Request $request): JsonResponse
    {
        $executions = Execution::query()
            ->whereHas('robot', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('robot:id,name,url')
            ->where('status', 'success')
            ->orderBy('started_at', 'asc')
            ->get();

        $productsHistory = [];

        foreach ($executions as $execution) {
            $date = $execution->started_at?->toIso8601String() ?? $execution->created_at->toIso8601String();
            $products = $execution->products ?? [];

            foreach ($products as $product) {
                $name = trim($product['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $key = ! empty($product['url']) ? strtolower(trim($product['url'])) : strtolower($name);

                if (! isset($productsHistory[$key])) {
                    $productsHistory[$key] = [
                        'key' => $key,
                        'name' => $name,
                        'url' => $product['url'] ?? $execution->robot->url,
                        'image' => $product['image'] ?? null,
                        'currency' => $product['currency'] ?? 'MXN',
                        'robot_id' => $execution->robot->id,
                        'robot_name' => $execution->robot->name,
                        'points' => [],
                    ];
                }

                $rawPrice = $product['price'] ?? null;
                $numericPrice = null;
                if ($rawPrice !== null) {
                    $cleaned = preg_replace('/[^\d.,]/', '', (string) $rawPrice);
                    if ($cleaned !== null && $cleaned !== '') {
                        if (str_contains($cleaned, ',') && str_contains($cleaned, '.')) {
                            $cleaned = str_replace(',', '', $cleaned);
                        } elseif (preg_match('/,\d{2}$/', $cleaned) === 1) {
                            $cleaned = str_replace(',', '.', $cleaned);
                        } else {
                            $cleaned = str_replace(',', '', $cleaned);
                        }
                        $numericPrice = is_numeric($cleaned) ? (float) $cleaned : null;
                    }
                }

                $productsHistory[$key]['points'][] = [
                    'execution_id' => $execution->id,
                    'date' => $date,
                    'price' => $rawPrice,
                    'price_numeric' => $numericPrice,
                    'previous_price' => $product['previous_price'] ?? null,
                    'stock' => $product['stock'] ?? null,
                ];
            }
        }

        $result = array_values(array_map(function ($item) {
            $numericPoints = array_values(array_filter($item['points'], fn ($p) => $p['price_numeric'] !== null));
            $count = count($numericPoints);

            $currentPrice = $count > 0 ? $numericPoints[$count - 1]['price_numeric'] : null;
            $initialPrice = $count > 0 ? $numericPoints[0]['price_numeric'] : null;
            $minPrice = $count > 0 ? min(array_column($numericPoints, 'price_numeric')) : null;
            $maxPrice = $count > 0 ? max(array_column($numericPoints, 'price_numeric')) : null;

            $diff = ($currentPrice !== null && $initialPrice !== null) ? round($currentPrice - $initialPrice, 2) : 0;
            $percent = ($initialPrice && $initialPrice > 0) ? round(($diff / $initialPrice) * 100, 2) : 0;

            $trend = 'stable';
            if ($diff < -0.001) {
                $trend = 'down';
            } elseif ($diff > 0.001) {
                $trend = 'up';
            }

            return array_merge($item, [
                'current_price' => $currentPrice,
                'initial_price' => $initialPrice,
                'min_price' => $minPrice,
                'max_price' => $maxPrice,
                'price_diff' => $diff,
                'price_diff_percent' => $percent,
                'trend' => $trend,
                'total_checks' => count($item['points']),
            ]);
        }, $productsHistory));

        return response()->json(['data' => $result]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(Execution $execution): array
    {
        $robot = $execution->robot;
        $products = $execution->products ?? [];

        return [
            'id' => $execution->id,
            'robot_id' => $robot->id,
            'name' => $robot->name,
            'url' => $robot->url,
            'interval_minutes' => (int) ($robot->interval_minutes ?? 15),
            'monitor_enabled' => (bool) ($robot->monitor_enabled ?? false),
            'format' => strtoupper($robot->output_format),
            'status' => $execution->status === 'success' ? 'Éxito' : ($execution->status === 'failed' ? 'Error' : 'En curso'),
            'status_code' => $execution->status,
            'start' => $execution->started_at?->toIso8601String(),
            'end' => $execution->finished_at?->toIso8601String(),
            'duration_ms' => $execution->duration_ms,
            'count' => count($products),
            'preview_allowed' => $execution->status === 'success',
            'title' => $products[0]['name'] ?? null,
            'description' => $products[0]['description'] ?? null,
            'image' => $products[0]['image'] ?? null,
            'products' => $products,
            'error' => $execution->error_message,
        ];
    }
}
