<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ═══════════════════════════════════════════════════════════════
 * فلتر «من — إلى» الموحّد للشاشات (٩ سبتمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * كان فيه أربع طرق متوازية لقراءة `from`/`to` (`ReportController::range`
 * · `OpsController::boardWindow` · `GroupController::dateOrNull` · قراءة
 * خام في كل كنترولر). ده المكان الواحد لأي شاشة جديدة أو بتتزوّد فلتر:
 *
 *   $range = DateRange::fromRequest($request);           // مفتوح لو فاضي
 *   $range = DateRange::fromRequest($request, 'month');  // افتراضي من أول الشهر للنهارده
 *   $q->tap(fn ($q) => $range->apply($q, 'created_at'));
 *
 * ⚠️ الحراس اللي اتلسعنا منهم (`VisitBoardController::day` و`boardWindow`):
 *   • `Carbon::parse` على نص عبيط أو مصفوفة بترمي 500 — أي قيمة مش نص
 *     صالح بتتعامل كأنها فاضية.
 *   • `to` قبل `from` بيتقلبوا بدل ما يرجّعوا صفر صف في صمت.
 *   • الفلتر بـ`whereDate` على العمود المسمّى — عمود التاريخ ذاته
 *     مسؤولية اللي بينده (الزيارة `checked_in_at` مش `created_at`).
 */
final class DateRange
{
    private function __construct(
        public readonly ?Carbon $from,
        public readonly ?Carbon $to,
    ) {}

    /**
     * @param  'open'|'month'|'today'  $default  لو الاتنين فاضيين:
     *         `open` = بلا حدود · `month` = أول الشهر → النهارده · `today` = النهارده بس
     */
    public static function fromRequest(Request $request, string $default = 'open', string $fromKey = 'from', string $toKey = 'to'): self
    {
        $from = self::day($request->query($fromKey));
        $to = self::day($request->query($toKey));

        if ($from === null && $to === null) {
            [$from, $to] = match ($default) {
                'month' => [today()->startOfMonth(), today()],
                'today' => [today(), today()],
                default => [null, null],
            };
        }

        if ($from !== null && $to !== null && $to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return new self($from?->startOfDay(), $to?->endOfDay());
    }

    /** نص تاريخ صالح → Carbon، وغير كده null (مفيش 500 على باراميتر عبيط) */
    public static function day(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }

    /** الفلتر على عمود تاريخ/وقت — `whereDate` فالوقت مايهمش */
    public function apply(Builder|\Illuminate\Database\Query\Builder $q, string $column): Builder|\Illuminate\Database\Query\Builder
    {
        if ($this->from !== null) {
            $q->whereDate($column, '>=', $this->from->toDateString());
        }
        if ($this->to !== null) {
            $q->whereDate($column, '<=', $this->to->toDateString());
        }

        return $q;
    }

    public function isOpen(): bool
    {
        return $this->from === null && $this->to === null;
    }

    /** للخانات: `value="{{ $range->fromValue() }}"` */
    public function fromValue(): string
    {
        return $this->from?->toDateString() ?? '';
    }

    public function toValue(): string
    {
        return $this->to?->toDateString() ?? '';
    }

    /** للتمرير في لينكات التصدير: `route('x', $range->query())` */
    public function query(): array
    {
        return array_filter(['from' => $this->fromValue(), 'to' => $this->toValue()]);
    }
}
