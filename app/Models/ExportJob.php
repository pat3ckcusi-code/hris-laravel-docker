<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ExportJob extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'type', 'params', 'status',
        'result_path', 'result_filename', 'mime_type',
        'error_message', 'expires_at',
    ];

    protected $casts = [
        'params' => 'array',
        'expires_at' => 'datetime',
    ];

    protected $hidden = ['result_path'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function createPending(int $userId, string $type, array $params): self
    {
        return self::create([
            'id'      => (string) Str::ulid(),
            'user_id' => $userId,
            'type'    => $type,
            'params'  => $params,
            'status'  => 'pending',
        ]);
    }

    public function markProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markCompleted(string $path, string $filename, string $mime): void
    {
        $this->update([
            'status'          => 'completed',
            'result_path'     => $path,
            'result_filename' => $filename,
            'mime_type'       => $mime,
            'expires_at'      => now()->addHours(2),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status'        => 'failed',
            'error_message' => $message,
        ]);
    }
}
