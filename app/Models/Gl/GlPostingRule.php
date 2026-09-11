<?php

namespace App\Models\Gl;

use Illuminate\Database\Eloquent\Model;

class GlPostingRule extends Model
{
    protected $table = 'gl_posting_rules';

    protected $fillable = ['key', 'label', 'debit_key', 'credit_key', 'tax_key', 'active'];

    protected function casts(): array
    {
        return ['active' => 'bool'];
    }

    /** @var array<string, ?GlPostingRule>|null */
    private static ?array $cache = null;

    public static function forKey(string $key): ?self
    {
        if (self::$cache === null) {
            self::$cache = static::all()->keyBy('key')->all();
        }

        return self::$cache[$key] ?? null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flush());
        static::deleted(fn () => self::flush());
    }
}
