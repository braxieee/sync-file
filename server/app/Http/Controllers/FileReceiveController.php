<?php

namespace App\Http\Controllers;

use App\Models\Download;
use App\Services\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FileReceiveController extends Controller
{
    public function __construct(private Webhook $webhook) {}

    public function upload(Request $request, Download $download): JsonResponse
    {
        $client = $request->attributes->get('client');

        if ($download->client_id !== $client->id) {
            return response()->json(['message' => 'Job does not belong to your client.'], 403);
        }

        if (! in_array($download->status, [Download::STATUS_UPLOADING, Download::STATUS_PENDING])) {
            return response()->json(['message' => "Download is already {$download->status}."], 409);
        }

        try {
            $originalName = $request->header('X-File-Name');
            $filename = $originalName 
                ? pathinfo($originalName, PATHINFO_FILENAME) . "_download-{$download->id}" . '.' . pathinfo($originalName, PATHINFO_EXTENSION) 
                : "job-{$download->id}_" . now()->format('Ymd_His');
            $dir = storage_path("app/downloads/client-{$client->id}");

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $fullPath = "{$dir}/{$filename}";
            $inputStream = fopen('php://input', 'rb');
            $outputStream = fopen($fullPath, 'wb');

            if (! $inputStream || ! $outputStream) {
                throw new \RuntimeException('Could not open I/O streams.');
            }

            $size = stream_copy_to_stream($inputStream, $outputStream);
            fclose($inputStream);
            fclose($outputStream);

            if ($size === false || $size === 0) {
                throw new \RuntimeException('Empty or unreadable upload stream.');
            }

            $relativePath = "downloads/client-{$client->id}/{$filename}";
            $download->markCompleted($relativePath, $size);
            $download->load('client');
            $this->webhook->downloadCompleted($download);

            return response()->json([
                'message' => 'File received successfully.',
                'download_id' => $download->id,
                'file_size' => $size,
                'stored_at' => $relativePath,
            ]);
        } catch (\Throwable $e) {
            $download->markFailed($e->getMessage());
            $download->load('client');
            $this->webhook->downloadFailed($download);
            return response()->json(['message' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }
}
