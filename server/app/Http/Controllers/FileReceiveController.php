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

        if ($download->status === Download::STATUS_PENDING) {
            $totalFiles = (int) $request->header('X-Files-Total', 1);
            $download->markUploading($totalFiles);
        }

        try {
            $originalName = $request->header('X-File-Name', 'file_' . now()->format('Ymd_His'));
            $filename = $this->safeName($originalName, $download->id);
            $dir = storage_path("app/downloads/client-{$client->id}/download-{$download->id}");

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

            $relativePath = "downloads/client-{$client->id}/download-{$download->id}/{$filename}";
            $download->recordFileUploaded($originalName, $relativePath, $size);
            $download->refresh();

            $allDone = $download->files_total > 0 && $download->files_uploaded >= $download->files_total;

            if ($allDone) {
                $download->markCompleted();
                $download->load('client');
                $this->webhook->downloadCompleted($download);
            }

            return response()->json([
                'message' => $allDone ? 'All files received.' : 'File received.',
                'job_id' => $download->id,
                'file_name' => $filename,
                'file_size' => $size,
                'files_uploaded' => $download->files_uploaded,
                'files_total' => $download->files_total,
                'job_complete' => $allDone,
            ]); 
        } catch (\Throwable $e) {
            $download->markFailed($e->getMessage());
            $download->load('client');
            $this->webhook->downloadFailed($download);
            return response()->json(['message' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }

    private function safeName(string $originalName, int $downloadId): string
    {
        $name = preg_replace('/[^a-zA-Z0-9.\-_]/', '_', basename($originalName));
        return $name;
    }
}
