<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use HasBilingualName, HasFactory;

    public const KEY_ACCOUNT = 'key_account';
    public const ONLINE = 'online';
    public const CASH_VAN = 'cash_van';
    public const WHOLESALE = 'wholesale';

    /**
     * [الكود => [الاسم العربي، الاسم الإنجليزي، اللون]]
     *
     * ⚠️ **مفيش نسبة خصم.** قرار 2026-07-31: القناة بُعد تجميع وتقرير
     * — كام عميل، كام بضاعة، كام مبيعات. النسبة بتتحدد **لكل عميل**
     * من عقده أو خصمه الخاص أو سلسلته.
     *
     * ⚠️ لما كانت القناة بتدي نسبة، عميل جديد اتحط في «كي أكاونت»
     * كان بياخد 50% أوتوماتيك من غير ما حد يتفاوض عليها، وأول فاتورة
     * بتطلع بخصم محدش قرره.
     */
    public const DEFAULTS = [
        self::KEY_ACCOUNT => ['كي أكاونت', 'Key Account', '#7C3AED'],
        self::ONLINE => ['أونلاين', 'Online', '#2563EB'],
        self::CASH_VAN => ['كاش فان', 'Cash Van', '#16A34A'],
        self::WHOLESALE => ['جملة (هول سيل)', 'Wholesale', '#EA8C1C'],
    ];

    /**
     * الأقسام الفرعية — **للكي أكاونت وبس**.
     *
     * ⚠️ الأونلاين والكاش فان والجملة **مالهمش أقسام**. الكي أكاونت
     * وحده اللي بينقسم لأن سلسلة هايبر وكونفينيانس/محطة بنزين بيتعاملوا
     * مختلف تماماً: الأولى فيها رفوف ومساحات عرض وعقود سنوية، والتانية
     * دوران سريع وكميات صغيرة. باقي القنوات متجانسة جواها.
     *
     * ⚠️ القسم على عميل قناته مش كي أكاونت **بيتصفّى أوتوماتيك** في
     * `Client::booted()`. الفلتر في شاشة العملاء بيفلتر بالقسم، وقيمة
     * فاضلة من قناة قديمة كانت بتخلّي عميل كاش فان يطلع في نتيجة
     * «سلاسل هايبر».
     */
    public const SUB_CHANNELS = [
        'chain' => 'سلاسل هايبر وماركت',
        'convenience' => 'كونفينيانس ومحطات',
    ];

    /** القناة دي ليها أقسام؟ — الكي أكاونت وبس */
    public function hasSubChannels(): bool
    {
        return $this->code === self::KEY_ACCOUNT;
    }

    public static function codeHasSubChannels(?string $code): bool
    {
        return $code === self::KEY_ACCOUNT;
    }

    protected $fillable = ['code', 'name', 'name_en', 'color', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** المناديب الشغالين على القناة */
    public function fieldUsers(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** المديرين المسئولين عن القناة */
    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public static function subChannelLabel(?string $sub): ?string
    {
        if ($sub === null) {
            return null;
        }

        // المسمى بييجي من lang/{ar,en}/enums.php — والثابت القديم fallback
        $key = 'enums.sub_channel.'.$sub;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : (self::SUB_CHANNELS[$sub] ?? $sub);
    }

    /** اسم القناة باللغة الحالية — الكود ثابت والمسمى بيتترجم */
    public function label(): string
    {
        $key = 'enums.channel.'.$this->code;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : $this->displayName();
    }

    public function badgeClass(): string
    {
        return match ($this->code) {
            self::KEY_ACCOUNT => 'b-purple',
            self::ONLINE => 'b-blue',
            self::CASH_VAN => 'b-green',
            self::WHOLESALE => 'b-orange',
            default => 'b-gray',
        };
    }
}
