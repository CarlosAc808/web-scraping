<?php

namespace App\Console\Commands;

use App\Models\Robot;
use App\Services\Scraping\ExecutionRunner;
use Illuminate\Console\Command;

class PollMonitoredRobotsCommand extends Command
{
    protected $signature = 'robots:poll';
    protected $description = 'Consulta robots que tienen monitoreo activo y cuyo intervalo ha transcurrido';

    public function handle(ExecutionRunner $runner): int
    {
        $robots = Robot::where('monitor_enabled', true)->get();
        $count = 0;

        foreach ($robots as $robot) {
            $lastExecution = $robot->executions()->latest()->first();
            $interval = (int) ($robot->interval_minutes ?: 15);

            $shouldRun = ! $lastExecution
                || $lastExecution->started_at->addMinutes($interval)->isPast();

            if ($shouldRun) {
                $this->info("Consultando robot {$robot->id}: {$robot->name}");
                $runner->run($robot);
                $count++;
            }
        }

        $this->info("Se ejecutaron {$count} consultas.");

        return self::SUCCESS;
    }
}
