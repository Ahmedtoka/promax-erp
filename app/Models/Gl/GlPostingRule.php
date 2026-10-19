<?php

namespace App\Models\Gl;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlPostingRule extends Model
{
    protected $table = 'gl_posting_rules';

    protected $fillable = [
        'key', 'label', 'debit_key', 'credit_key', 'tax_key', 'active',
        'debit_account_id', 'credit_account_id', 'tax_account_id',
    ];

    protected function casts(): array
    {
        return ['active' => 'bool'];
    }

    /** خانة مثبّتة على حساب بعينه (بتتقدّم على المفتاح) */
    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'credit_account_id');
    }

    public function taxAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'tax_account_id');
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
