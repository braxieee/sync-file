<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Download;
use Illuminate\Console\Command;

class DownloadRequest extends Command
{
    protected $signature = 'file:download-request
                            {client_id}
                            {--wait}
                            {--timeout=300}';
    protected $description = 'Request a file download from an on-premise client';

    public function handle(): int
    {
        $client = Client::find($this->argument('client_id'));

        if (! $client) {
            $this->error("Client #{$this->argument('client_id')} not found.");
            return self::FAILURE;
        }

        $existing = $client->downloadJobs()
            ->whereIn('status', [Download::STATUS_PENDING, Download::STATUS_UPLOADING])
            ->first();

        if ($existing) {
            $this->warn("Job #{$existing->id} already {$existing->status} for this client.");
            if (! $this->confirm('Create anyway?')) return self::SUCCESS;
        }

        $download = Download::create([
            'client_id' => $client->id,
            'status' => Download::STATUS_PENDING,
            'requested_by' => 'cli',
        ]);

        $this->info("Download request #{$download->id} created (pending). Client will pick it up on next poll.");

        if ($this->option('wait')) {
            return $this->waitForCompletion($download);
        }

        return self::SUCCESS;
    }

    private function waitForCompletion(Download $download): int
    {
        $timeout = (int) $this->option('timeout');
        $start   = time();

        $this->info("Waiting for completion (timeout: {$timeout}s)...");

        while (true) {
            sleep(3);
            $download->refresh();

            $this->line("  Status: {$download->status}");

            if ($download->isCompleted()) {
                $this->info("✓ Completed! File: {$download->file_path} ({$download->getFileSizeHumanAttribute()})");
                return self::SUCCESS;
            }

            if ($download->status === download::STATUS_FAILED) {
                $this->error("Failed: {$download->error_message}");
                return self::FAILURE;
            }

            if ((time() - $start) > $timeout) {
                $this->error("Timed out after {$timeout}s.");
                return self::FAILURE;
            }
        }
    }
}