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
    private string $syncFolder;
    private int $consecutiveErrors = 0;

    public function handle(): int
    {
        $this->serverUrl = rtrim(config('filesync.server_url'), '/');
        $this->apiToken = config('filesync.api_token');
        $this->syncFolder = config('filesync.sync_folder');

        if (! $this->serverUrl || ! $this->apiToken) {
            $this->error('SERVER_URL and CLIENT_API_TOKEN must be set in .env');
            return self::FAILURE;
        }

        if (! is_dir($this->syncFolder)) {
            $this->error("Sync folder not found: {$this->syncFolder}");
            return self::FAILURE;
        }

        $interval = (int) $this->option('interval');
        $once = $this->option('once');

        $this->info("Client started. Server: {$this->serverUrl}");
        $this->info("Sync folder : {$this->syncFolder}");

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
        $this->uploadFolder($req['id'], $req['upload_url']);
    }

    private function uploadFolder(int $downloadId, string $uploadUrl): void
    {
        $files = collect(scandir($this->syncFolder))
            ->filter(fn($f) => ! in_array($f, ['.', '..']) && is_file("{$this->syncFolder}/{$f}"))
            ->values();

        if ($files->isEmpty()) {
            $this->warn("  No files found in {$this->syncFolder}. Reporting failure.");
            $this->reportFailure($downloadId, 'No files found in sync folder.');
            return;
        }

        $total = $files->count();
        $this->info("  Found {$total} file(s) to upload:");
        $files->each(fn($f) => $this->line("    - {$f} (" . $this->humanSize(filesize("{$this->syncFolder}/{$f}")) . ")"));

        foreach ($files as $index => $filename) {
            $filePath = "{$this->syncFolder}/{$filename}";
            $current  = $index + 1;

            $this->line("  [{$current}/{$total}] Uploading {$filename}...");

            $success = $this->uploadSingleFile(
                downloadId: $downloadId,
                uploadUrl: $uploadUrl,
                filePath: $filePath,
                fileName: $filename,
                totalFiles: $total,
            );

            if (! $success) {
                $this->error("  Stopping upload batch due to error on {$filename}.");
                return;
            }
        }

        $this->info(" All {$total} file(s) uploaded for job #{$downloadId}.");
    }

    private function uploadSingleFile(
        int    $downloadId,
        string $uploadUrl,
        string $filePath,
        string $fileName,
        int    $totalFiles,
    ): bool {
        $fileSize = filesize($filePath);
        $startTime = microtime(true);
        $stream = fopen($filePath, 'rb');

        if (! $stream) {
            $this->reportFailure($downloadId, "Cannot open: {$filePath}");
            return false;
        }

        try {
            $response = Http::withToken($this->apiToken)
                ->timeout(600)
                ->withHeaders([
                    'X-File-Name'   => $fileName,
                    'X-Files-Total' => $totalFiles,
                ])
                ->withBody(
                    \GuzzleHttp\Psr7\Utils::streamFor($stream),
                    'application/octet-stream'
                )
                ->post($uploadUrl);

            fclose($stream);

            if ($response->successful()) {
                $elapsed = round(microtime(true) - $startTime, 1);
                $speed = $this->humanSize((int)($fileSize / max($elapsed, 1))) . '/s';
                $this->info(" {$fileName} done in {$elapsed}s ({$speed})");
                return true;
            }

            $this->error(" Failed: HTTP " . $response->status() . ' — ' . $response->body());
            return false;

        } catch (\Throwable $e) {
            if (is_resource($stream)) fclose($stream);
            $this->error(" Exception: " . $e->getMessage());
            $this->reportFailure($downloadId, $e->getMessage());
            return false;
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
