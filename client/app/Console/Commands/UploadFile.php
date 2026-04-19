<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UploadFile extends Command
{
    protected $signature = 'client:poll
                            {--interval=15 : Seconds between polls}
                            {--once : Poll once and exit}';
    protected $description = 'Poll the server for file download requests and fulfil them';

    private string $serverUrl;
    private string $apiToken;
    private string $filePath;
    private int    $consecutiveErrors = 0;

    public function handle(): int
    {
        $this->serverUrl = rtrim(config('filesync.server_url'), '/');
        $this->apiToken = config('filesync.api_token');
        $this->filePath = config('filesync.file_path');

        if (! $this->serverUrl || ! $this->apiToken) {
            $this->error('SERVER_URL and CLIENT_API_TOKEN must be set in .env');
            return self::FAILURE;
        }

        if (! file_exists($this->filePath)) {
            $this->error("File not found: {$this->filePath}");
            return self::FAILURE;
        }

        $interval = (int) $this->option('interval');
        $once = $this->option('once');

        $this->info("Client started. Server: {$this->serverUrl}");
        $this->info("File: {$this->filePath} (" . $this->humanSize(filesize($this->filePath)) . ")");

        do {
            try {
                $this->pollOnce();
                $this->consecutiveErrors = 0;
            } catch (\Throwable $e) {
                $this->consecutiveErrors++;
                $backoff = min(300, $interval * (2 ** $this->consecutiveErrors));
                $this->warn("[" . now()->toTimeString() . "] Error: " . $e->getMessage());
                $this->warn("  Backing off {$backoff}s...");
                Log::error('PollForJobs', ['error' => $e->getMessage()]);
                if (! $once) sleep($backoff);
                continue;
            }

            if (! $once) sleep($interval);
        } while (! $once);

        return self::SUCCESS;
    }

    private function pollOnce(): void
    {
        $this->line("[" . now()->toTimeString() . "] Checking for jobs...");

        $response = Http::withToken($this->apiToken)
            ->timeout(15)
            ->get("{$this->serverUrl}/api/pending");

        if ($response->failed()) {
            throw new \RuntimeException("Server returned HTTP {$response->status()}");
        }

        $req = $response->json('download');

        if (! $req) {
            $this->line('  → No pending request.');
            return;
        }

        $this->info("  → Download request #{$req['id']} found! Uploading...");
        $this->uploadFile($req['id'], $req['upload_url']);
    }

    private function uploadFile(int $downloadId, string $uploadUrl): void
    {
        $fileSize = filesize($this->filePath);
        $originalName = basename($this->filePath);
        $startTime = microtime(true);

        $this->line("  Uploading {$originalName} " . $this->humanSize($fileSize) . " to server...");

        $stream = fopen($this->filePath, 'rb');

        if (! $stream) {
            $this->reportFailure($downloadId, "Cannot open file: {$this->filePath}");
            return;
        }

        try {
            $response = Http::withToken($this->apiToken)
                ->timeout(600)
                ->withHeaders([
                    'X-File-Name' => $originalName,
                ])
                ->withBody(
                    \GuzzleHttp\Psr7\Utils::streamFor($stream),
                    'application/octet-stream'
                )
                ->post($uploadUrl);

            fclose($stream);

            if ($response->successful()) {
                $elapsed = round(microtime(true) - $startTime, 1);
                $speed   = $this->humanSize((int)($fileSize / max($elapsed, 1))) . '/s';
                $this->info("  ✓ Done in {$elapsed}s ({$speed})");
            } else {
                $this->error("  ✗ Upload failed: HTTP " . $response->status());
                $this->error("  " . $response->body());
            }
        } catch (\Throwable $e) {
            if (is_resource($stream)) fclose($stream);
            $this->reportFailure($downloadId, $e->getMessage());
        }
    }

    private function reportFailure(int $downloadId, string $reason): void
    {
        $this->error(" {$reason}");
        try {
            Http::withToken($this->apiToken)
                ->timeout(10)
                ->post("{$this->serverUrl}/api/jobs/{$downloadId}/fail", ['reason' => $reason]);
        } catch (\Throwable) {
        }
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit  = 0;
        $size  = (float) $bytes;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }
        return round($size, 1) . $units[$unit];
    }
}
