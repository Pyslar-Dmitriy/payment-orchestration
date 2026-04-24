<?php

declare(strict_types=1);

namespace App\Interfaces\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ResetProjectionsCommand extends Command
{
    protected $signature = 'reporting:reset-projections
        {--force : Skip the confirmation prompt}';

    protected $description = 'Truncate all projection read model tables so a Kafka replay can rebuild state from scratch';

    private const TABLES = [
        'inbox_messages',
        'payment_projections',
        'merchant_payment_summaries',
        'provider_performance_summaries',
        'daily_aggregates',
    ];

    public function handle(): int
    {
        if (! $this->option('force')
            && ! $this->confirm('This will delete ALL projection data. Continue?')
        ) {
            $this->info('Reset cancelled.');

            return Command::SUCCESS;
        }

        DB::transaction(function (): void {
            foreach (self::TABLES as $table) {
                DB::table($table)->truncate();
            }
        });

        foreach (self::TABLES as $table) {
            $this->line("  Cleared: {$table}");
        }

        $this->newLine();
        $this->info('All projection tables cleared. Reset the Kafka consumer group offset, then restart reporting:consume-events.');

        Log::info('Projection tables reset', ['tables' => self::TABLES]);

        return Command::SUCCESS;
    }
}
