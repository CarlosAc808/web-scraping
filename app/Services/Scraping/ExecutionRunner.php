<?php

namespace App\Services\Scraping;

use App\Models\Execution;
use App\Models\Robot;
use Illuminate\Support\Facades\Log;

class ExecutionRunner
{
    public function __construct(private readonly ScraperService $scraper) {}

    public function run(Robot $robot): Execution
    {
        $started = hrtime(true);
        $execution = $robot->executions()->create([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $result = $this->scraper->scrape($robot->url);
            $execution->update([
                'status' => 'success',
                'finished_at' => now(),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'products' => $result['products'],
            ]);
        } catch (ScrapeException $exception) {
            $execution->update([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error_message' => $exception->getMessage(),
            ]);
            Log::warning('Web scraping execution failed', [
                'execution_id' => $execution->id,
                'robot_id' => $robot->id,
                'reason' => $exception->getMessage(),
            ]);
        }

        return $execution->fresh();
    }
}
