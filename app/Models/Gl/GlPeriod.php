<?php

namespace App\Models\Gl;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class GlPeriod extends Model
{
    protected $table = 'gl_periods';

    protected $fillable = ['key', 'status', 'closed_at', 'closed_by', 'reopened_at', 'reopened_by'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    public static function keyFor(Carbon $date): string
    {
        return $date->format('Y-m');
    }

    public static function isClosed(Carbon $date): bool
    {
        return static::where('key', self::keyFor($date))->where('status', 'closed')->exists();
    }

    public static function close(string $key, User $by): self
    {
        return static::updateOrCreate(['key' => $key], ['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id]);
    }

    public static function reopen(string $key, User $by): self
    {
        return static::updateOrCreate(['key' => $key], ['status' => 'open', 'reopened_at' => now(), 'reopened_by' => $by->id]);
    }
}
