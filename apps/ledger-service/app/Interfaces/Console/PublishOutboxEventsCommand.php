<?php

declare(strict_types=1);

namespace App\Interfaces\Console;

use App\Infrastructure\Outbox\OutboxPublisherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class PublishOutboxEventsCommand extends Command
{
    protected $signature = 'outbox:publish
                            {--once : Process one batch then exit (useful in tests and one-shot jobs)}';

    protected $description = 'Poll the outbox_messages table and publish pending events to Kafka';

    public function handle(OutboxPublisherService $publisher): int
    {
        $once = (bool) $this->option('once');

        $this->info('Outbox publisher started.');

        do {
            $count = $publisher->processBatch();

            if ($count > 0) {
                $this->info("Published $count message(s).");
                Log::info('outbox.batch_published', ['count' => $count]);
            }

            if (! $once && $count === 0) {
                sleep(1);
            }
        } while (! $once);

        return Command::SUCCESS;
    }
}
