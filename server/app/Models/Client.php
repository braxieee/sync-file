<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Client extends Model
{
    protected $fillable = ['name', 'api_token', 'last_seen_at'];
    protected $hidden = ['api_token'];
    protected $casts = ['last_seen_at' => 'datetime'];

    public function download(): HasMany
    {
        return $this->hasMany(Download::class);
    }

    public static function generateToken(): string
    {
        return Str::random(60);
    }

    public static function findByToken(string $plainToken): ?static
    {
        return static::where('api_token', hash('sha256', $plainToken))->first();
    }

    public function touchLastSeen(): void
    {
        $this->update(['last_seen_at' => now()]);
    }
}