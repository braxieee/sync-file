<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Download;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DownloadController extends Controller
{
    public function requestFile(Request $request): JsonResponse
    {
        $request->validate(['client_id' => 'required|exists:clients,id']);

        $client = Client::findOrFail($request->client_id);

        $existing = $client->download()
            ->whereIn('status', [Download::STATUS_PENDING, Download::STATUS_UPLOADING])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'A download request is already pending or uploading for this client.',
                'download' => $existing,
            ], 409);
        }

        $download = Download::create([
            'client_id' => $client->id,
            'status' => Download::STATUS_PENDING,
            'requested_by' => $request->user()?->email ?? 'cli',
        ]);

        return response()->json([
            'message' => 'Download request created. Waiting for client to pick it up.',
            'download' => $download,
        ], 201);
    }

    public function pending(Request $request): JsonResponse
    {
        $client = $request->attributes->get('client');
        $client->touchLastSeen();

        $download = $client->download()
            ->where('status', Download::STATUS_PENDING)
            ->oldest()
            ->first();

        if (! $download) {
            return response()->json(['job' => null]);
        }

        return response()->json([
            'download' => [
                'id' => $download->id,
                'upload_url' => route('api.files.upload', $download->id),
            ],
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json(Download::with('client')->latest()->paginate(20));
    }

    public function show(Download $id): JsonResponse
    {
        return response()->json($id->load('client')->append('file_size_human'));
    }

    public function markFailed(Request $request, Download $download): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:500']);
        $download->markFailed($request->input('reason', 'Client reported failure'));
        return response()->json(['message' => 'Job marked as failed.']);
    }
}