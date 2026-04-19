<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Download extends Model
{
    protected $fillable = [
        'client_id', 'status', 'file_path', 'file_size',
        'files', 'files_total', 'files_uploaded',
        'requested_by', 'error_message', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'started_at'     => 'datetime',
        'completed_at'   => 'datetime',
        'file_size'      => 'integer',
        'files'          => 'array',
        'files_total'    => 'integer',
        'files_uploaded' => 'integer',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_UPLOADING = 'uploading';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isCompleted(): bool { return $this->status === self::STATUS_COMPLETED; }

    public function markUploading(int $totalFiles): void
    {
        $this->update([
            'status' => self::STATUS_UPLOADING,
            'files_total' => $totalFiles,
            'started_at' => now(),
        ]);
    }

    public function recordFileUploaded(string $originalName, string $storedPath, int $fileSize): void
    {
        $files = $this->files ?? [];
        $files[] = [
            'original_name' => $originalName,
            'stored_path' => $storedPath,
            'file_size' => $fileSize,
            'uploaded_at' => now()->toIso8601String(),
        ];

        $this->update([
            'files' => $files,
            'files_uploaded' => count($files),
            'file_size' => ($this->file_size ?? 0) + $fileSize,
        ]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $reason,
            'completed_at' => now(),
        ]);
    }

    public function getFileSizeHumanAttribute(): string
    {
        if (! $this->file_size) return 'unknown';
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->file_size;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) { $size /= 1024; $unit++; }
        return round($size, 2) . ' ' . $units[$unit];
    }
}