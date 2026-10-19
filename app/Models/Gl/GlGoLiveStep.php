<?php

namespace App\Models\Gl;

use Illuminate\Database\Eloquent\Model;

/**
 * حالة خطوة في معالج التشغيل — الصف بيتعمل أول ما الخطوة تتقري.
 */
class GlGoLiveStep extends Model
{
    public const STATUSES = ['todo', 'doing', 'done'];

    protected $table = 'gl_golive_steps';

    protected $fillable = ['key', 'status', 'data', 'done_at', 'done_by'];

    protected $attributes = ['status' => 'todo'];

    protected function casts(): array
    {
        return ['data' => 'array', 'done_at' => 'datetime'];
    }

    public static function for(string $key): self
    {
        return static::firstOrCreate(['key' => $key]);
    }
}
