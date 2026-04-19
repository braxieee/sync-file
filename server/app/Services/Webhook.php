<?php

namespace App\Services;

use App\Models\Download;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Webhook
{
    public function downloadCompleted(Download $download): void
    {
        $this->send('download.completed', [
            'download_id' => $download->id,
            'client' => $download->client->name,
            'file_size' => $download->file_size,
            'file_path' => $download->file_path,
            'duration_s' => $download->started_at?->diffInSeconds($download->completed_at),
        ]);
    }

    public function downloadFailed(Download $download): void
    {
        $this->send('download.failed', [
            'download_id' => $download->id,
            'client' => $download->client->name,
            'reason' => $download->error_message,
        ]);
    }

    private function send(string $event, array $payload): void
    {
        $url = config('filesync.webhook_url');
        if (! $url) return;

        $body = array_merge(['event' => $event, 'timestamp' => now()->toIso8601String()], $payload);
        $headers = ['Content-Type' => 'application/json'];

        if ($secret = config('filesync.webhook_secret')) {
            $headers['X-Signature'] = 'sha256=' . hash_hmac('sha256', json_encode($body), $secret);
        }

        try {
            Http::withHeaders($headers)->timeout(10)->retry(3, 500)->post($url, $body);
        } catch (\Throwable $e) {
            Log::error("Webhook error [{$event}]: " . $e->getMessage());
        }
    }
}