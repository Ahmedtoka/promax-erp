<?php

namespace App\Services\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlLine;
use App\Models\Gl\GlLineOverride;
use App\Models\Gl\GlPeriod;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══ المكان الوحيد اللي بيكتب في دفتر الأستاذ ═══
 * post/unpost/repost للقيود الآلية · manual/reverse/overrideAccount للتصحيح ·
 * rebuild لإعادة البناء. كل كتابة داخل ترانزاكشن وكل قيد لازم يتوازن.
 */
class Ledger
{
    public function __construct(private Rules $rules)
    {
    }

    public function enabledFor(Carbon $date): bool
    {
        if (Setting::read('gl_enabled') !== '1') {
            return false;
        }
        $start = Setting::read('gl_start_date');

        return ! $start || $date->toDateString() >= $start;
    }

    /** تاريخ المستند اللي القيد بياخده */
    public static function dateOf(Model $source): Carbon
    {
        $d = $source->date ?? $source->to_at ?? $source->created_at ?? now();

        return Carbon::parse($d);
    }

    public function post(Model $source, ?User $by = null): ?GlEntry
    {
        $date = self::dateOf($source);
        if (! $this->enabledFor($date)) {
            return null;
        }
        $existing = $this->autoEntryFor($source);
        if ($existing) {
            return $existing->load('lines');
        }
        $spec = $this->rules->linesFor($source);
        if ($spec['lines'] === []) {
            return null;
        }

        return DB::transaction(function () use ($source, $date, $spec, $by) {
            $entry = GlEntry::create([
                'number' => GlEntry::nextNumber(),
                'date' => $date->toDateString(),
                'period_key' => GlPeriod::keyFor($date),
                'memo' => mb_substr($spec['memo'], 0, 250),
                'origin' => 'auto',
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'rule_key' => $spec['rule'],
                'needs_review' => $spec['needs_review'],
                'created_by' => $by?->id,
            ]);
            $this->writeLines($entry, $spec['lines']);

            return $entry->load('lines');
        });
    }

    public function unpost(Model $source): void
    {
        $this->autoEntryFor($source)?->delete();
    }

    public function repost(Model $source, ?User $by = null): ?GlEntry
    {
        // ⚠️ السويتش مقفول = مفيش لمس خالص — أدوات الفاتورة الإدارية
        // (redate/reprice/...) بتنادي repost() دايماً بعد التعديل حتى
        // لو الشجرة أصلاً مقفولة، ولو سبنا unpost() يشتغل هنا كانت
        // هتمسح قيد قديم (لو موجود من قبل ما السويتش يتقفل) من غير
        // بديل — القيد القديم أولى بالبقاء من غير شجرة (مراجعة الجولة ١)
        if (Setting::read('gl_enabled') !== '1') {
            return $this->autoEntryFor($source);
        }

        // unpost+post داخل ترانزاكشن واحد — لو الـpost فشل (مثلاً تاريخ
        // المصدر قبل `gl_start_date`) مايتمسحش القيد القديم من غير بديل
        return DB::transaction(function () use ($source, $by) {
            $this->unpost($source);

            return $this->post($source, $by);
        });
    }

    public function autoEntryFor(Model $source): ?GlEntry
    {
        return GlEntry::where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())->where('origin', 'auto')->first();
    }

    /** @param list<array{slot?:string,account_id:int,debit:float,credit:float,memo?:string,rule_account_id?:int,overridden?:bool}> $lines */
    protected function writeLines(GlEntry $entry, array $lines): void
    {
        $dr = round(array_sum(array_column($lines, 'debit')), 2);
        $cr = round(array_sum(array_column($lines, 'credit')), 2);
        if ($dr <= 0) {
            throw new UnbalancedEntry("Entry {$entry->number} has no amount");
        }
        if ($dr !== $cr) {
            throw new UnbalancedEntry("Entry {$entry->number}: debit {$dr} != credit {$cr}");
        }
        foreach ($lines as $l) {
            GlLine::create([
                'entry_id' => $entry->id,
                'account_id' => $l['account_id'],
                'debit' => round($l['debit'], 2),
                'credit' => round($l['credit'], 2),
                'memo' => $l['memo'] ?? null,
                'slot' => $l['slot'] ?? null,
                'rule_account_id' => $l['rule_account_id'] ?? $l['account_id'],
                'overridden' => $l['overridden'] ?? false,
            ]);
        }
    }

    public function assertOpen(Carbon $date): void
    {
        if (GlPeriod::isClosed($date)) {
            throw new ClosedPeriod(__('gl.period_closed', ['period' => GlPeriod::keyFor($date)]));
        }
    }

    /**
     * حساب العملاء/الموردين بيتحرّك من مستنداته بس (فاتورة/تحصيل/PO...) —
     * مش من قيد يدوي ولا تحويل حساب، عشان ده اللي بيحافظ على تطابق
     * الرصيد مع كشف حساب العميل/المورد. الحارس ده مش على `reverse()`
     * ولا على نسخة التصحيح الداخلية (بتنسخ سطور القيد الآلي زي ما هي).
     */
    private function assertNotControl(array $accountIds): void
    {
        $touchesControl = GlAccount::whereIn('id', $accountIds)
            ->whereIn('system_key', ['receivables', 'payables'])
            ->exists();
        if ($touchesControl) {
            throw new \InvalidArgumentException(__('gl.control_account'));
        }
    }

    /** شكل سطور القيد اليدوي: سطرين على الأقل، حساب صحيح، مبالغ رقمية */
    private function validateLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw new \InvalidArgumentException('Manual entry needs at least two lines.');
        }
        foreach ($lines as $l) {
            if (! is_array($l) || ! isset($l['account_id']) || ! is_int($l['account_id'])) {
                throw new \InvalidArgumentException('Manual entry lines: account_id is required and must be an integer.');
            }
            foreach (['debit', 'credit'] as $k) {
                if (isset($l[$k]) && ! is_numeric($l[$k])) {
                    throw new \InvalidArgumentException("Manual entry lines: {$k} must be numeric.");
                }
            }
        }
    }

    /**
     * كتابة قيد فعلياً — رأس + سطور داخل ترانزاكشن. حارس حسابات التحكم
     * اختياري (`$guardControl`) عشان `reverse()` والتصحيح الداخلي في
     * `overrideAccount()` بيعملوا نسخ من قيود آلية معتمدة أصلاً — ماينفعش
     * نرفضهم بنفس حارس القيد اليدوي الطازج.
     */
    private function writeEntry(Carbon $date, string $memo, array $lines, User $by, string $origin, ?int $reversesId, bool $guardControl): GlEntry
    {
        if ($guardControl) {
            $this->assertNotControl(array_column($lines, 'account_id'));
        }

        return DB::transaction(function () use ($date, $memo, $lines, $by, $origin, $reversesId) {
            $entry = GlEntry::create([
                'number' => GlEntry::nextNumber(),
                'date' => $date->toDateString(),
                'period_key' => GlPeriod::keyFor($date),
                'memo' => mb_substr($memo, 0, 250),
                'origin' => $origin,
                'reverses_entry_id' => $reversesId,
                'created_by' => $by->id,
            ]);
            $this->writeLines($entry, array_map(fn ($l) => [
                'account_id' => (int) $l['account_id'],
                'debit' => (float) ($l['debit'] ?? 0),
                'credit' => (float) ($l['credit'] ?? 0),
                'memo' => $l['memo'] ?? null,
                'slot' => $l['slot'] ?? null,
                'rule_account_id' => isset($l['rule_account_id']) ? (int) $l['rule_account_id'] : null,
                'overridden' => $l['overridden'] ?? false,
            ], array_values($lines)));

            return $entry->load('lines');
        });
    }

    /** قيد يدوي — سطور حرة، لازم تتوازن، الفترة لازم تكون مفتوحة */
    public function manual(Carbon $date, string $memo, array $lines, User $by, string $origin = 'manual'): GlEntry
    {
        if (! in_array($origin, ['manual', 'opening'], true)) {
            throw new \InvalidArgumentException("Invalid manual entry origin: {$origin}");
        }
        $this->validateLines($lines);
        $this->assertOpen($date);

        return $this->writeEntry($date, $memo, $lines, $by, $origin, null, true);
    }

    /** قيد عكسي بتاريخ النهاردة (أو تاريخ مفتوح تختاره) */
    public function reverse(GlEntry $entry, User $by, ?Carbon $date = null, ?string $memo = null): GlEntry
    {
        $date ??= today();
        $this->assertOpen($date);
        $lines = $entry->lines->map(fn (GlLine $l) => [
            'account_id' => $l->account_id, 'debit' => (float) $l->credit, 'credit' => (float) $l->debit,
        ])->all();

        return $this->writeEntry($date, $memo ?? __('gl.reversal_of', ['number' => $entry->number]), $lines, $by, 'reversal', $entry->id, false);
    }

    /**
     * تغيير حساب سطر: في الفترة المفتوحة تعديل في مكانه بسجل تدقيق؛ في
     * الفترة المقفولة قيد عكسي + قيد صحيح بتاريخ النهاردة، وبنفس أثر
     * التدقيق (سجل `gl_line_overrides` على السطر الأصلي + السطر المصحَّح
     * في القيد الجديد `overridden=true` و`rule_account_id` = الحساب
     * الأصلي). الأصل مايتلمسش خالص. بيرجّع القيد اللي فيه الترحيل الصحيح دلوقتي.
     */
    public function overrideAccount(GlLine $line, GlAccount $to, User $by, ?string $note = null): GlEntry
    {
        $entry = $line->entry;

        if (! $to->is_postable || ! $to->active) {
            throw new \InvalidArgumentException(__('gl.account_not_postable'));
        }
        // المصدر والهدف مع بعض — نقل من أو إلى حساب تحكم ممنوع بالتساوي
        $this->assertNotControl([$line->account_id, $to->id]);

        if ($line->account_id === $to->id) {
            return $entry->load('lines');
        }

        if (! GlPeriod::isClosed($entry->date)) {
            return DB::transaction(function () use ($line, $to, $by, $note, $entry) {
                GlLineOverride::create([
                    'line_id' => $line->id, 'from_account_id' => $line->account_id, 'to_account_id' => $to->id,
                    'user_id' => $by->id, 'at' => now(), 'note' => $note,
                ]);
                $line->update(['account_id' => $to->id, 'overridden' => true, 'rule_account_id' => $line->rule_account_id ?? $line->account_id]);
                $entry->update(['edited_at' => now(), 'edited_by' => $by->id]);

                return $entry->fresh('lines');
            });
        }

        return DB::transaction(function () use ($line, $to, $by, $note, $entry) {
            $this->reverse($entry, $by);

            // نفس أثر التدقيق اللي بيحصل في الفترة المفتوحة — على السطر
            // الأصلي (اللي فضل زي ما هو) مش على أي سطر في القيد الجديد
            GlLineOverride::create([
                'line_id' => $line->id, 'from_account_id' => $line->account_id, 'to_account_id' => $to->id,
                'user_id' => $by->id, 'at' => now(), 'note' => $note,
            ]);

            $lines = $entry->lines->map(fn (GlLine $l) => [
                'account_id' => $l->id === $line->id ? $to->id : $l->account_id,
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
                'rule_account_id' => $l->id === $line->id ? $l->account_id : null,
                'overridden' => $l->id === $line->id,
            ])->all();

            return $this->writeEntry(today(), __('gl.correction_of', ['number' => $entry->number]).($note ? ' — '.$note : ''), $lines, $by, 'manual', $entry->id, false);
        });
    }

    /** المستندات اللي بتولّد قيود، بترتيب التاريخ */
    public function sources(Carbon $from): \Generator
    {
        $d = $from->toDateString();
        $all = collect()
            ->concat(\App\Models\Transaction::whereDate('date', '>=', $d)->orderBy('date')->orderBy('id')->cursor())
            // ⚠️ `to_at` ممكن يكون فاضي — `dateOf()` بترجع لـ`created_at` في الحالة دي،
            // فلازم الكويري هنا تطابقها بنفس الـCOALESCE، وإلا تصفية من غير `to_at`
            // بتختفي من إعادة البناء من غير ما حد يحس (مش هي اللي بتتعد في الفحص الثابت)
            ->concat(\App\Models\RepSettlement::whereRaw('DATE(COALESCE(to_at, created_at)) >= ?', [$d])
                ->orderByRaw('DATE(COALESCE(to_at, created_at))')->cursor())
            ->concat(\App\Models\SupplierTransaction::whereDate('date', '>=', $d)->orderBy('date')->orderBy('id')->cursor())
            ->concat(\App\Models\Expense::where('status', 'posted')->whereDate('date', '>=', $d)->orderBy('date')->cursor())
            ->concat(\App\Models\CashMovement::where('status', 'posted')->whereDate('date', '>=', $d)->orderBy('date')->cursor())
            ->sortBy(fn ($m) => self::dateOf($m)->format('Y-m-d').'-'.str_pad((string) $m->getKey(), 10, '0', STR_PAD_LEFT));
        foreach ($all as $m) {
            yield $m;
        }
    }

    /** الفحص الثابت: عملاء الشجرة = صافي قيود العملاء من تاريخ البداية · موردون كذلك */
    public function invariants(): array
    {
        $start = Setting::read('gl_start_date') ?: '1970-01-01';
        $recvGl = GlAccount::findKey('receivables')->balanceBetween(null, null);
        $recvExp = round((float) DB::table('transactions')->whereDate('date', '>=', $start)
            ->where('kind', '!=', 'consignment')->selectRaw('COALESCE(SUM(debit - credit),0) v')->value('v'), 2);
        $payGl = GlAccount::findKey('payables')->balanceBetween(null, null);
        $payExp = round((float) DB::table('supplier_transactions')->whereDate('date', '>=', $start)
            ->selectRaw('COALESCE(SUM(credit - debit),0) v')->value('v'), 2);

        return [
            'receivables' => ['gl' => $recvGl, 'expected' => $recvExp, 'ok' => abs($recvGl - $recvExp) < 0.005],
            'payables' => ['gl' => $payGl, 'expected' => $payExp, 'ok' => abs($payGl - $payExp) < 0.005],
        ];
    }

    /**
     * إعادة البناء: مسح القيود الآلية من التاريخ، توليدها تاني بالقواعد الحالية،
     * إعادة تطبيق التعديلات اليدوية (بالمصدر + الخانة)، ثم الفحص. dryRun = rollback
     * في الآخر وبيرجّع التقرير من غير رمي — القيد اليدوي/الافتتاحي مايتلمسش
     * (origin != auto). فشل الفحص في تشغيل حقيقي = rollback + رمي `RebuildFailed`.
     */
    public function rebuild(Carbon $from, bool $keepOverrides = true, bool $dryRun = false, ?User $by = null): RebuildReport
    {
        // حارس الفترة المقفولة — قبل ما نفتح ترانزاكشن نلغيها بعدين. أي شهر
        // من `$from` لحد النهاردة لازم يكون مفتوح، وإلا القيود الآلية اللي
        // هتتمسح فيه مش هينفع تتبدّل
        $fromKey = $from->format('Y-m');
        $closedPeriod = GlPeriod::where('status', 'closed')->where('key', '>=', $fromKey)->orderBy('key')->first();
        if ($closedPeriod) {
            throw new ClosedPeriod(__('gl.rebuild_closed_period', ['period' => $closedPeriod->key]));
        }

        $report = new RebuildReport;
        $report->dryRun = $dryRun;
        $snapshot = fn () => GlAccount::where('is_postable', true)->get()
            ->mapWithKeys(fn ($a) => [$a->code => $a->balanceBetween(null, null)])->all();

        // ⚠️ المستوى قبل ما نفتح ترانزاكشنّا احنا — لو `rebuild()` اتنادت من
        // جوه ترانزاكشن تاني، الـrollback بتاعنا لازم يوقف عند مستوانا بس
        // ومايلمسش ترانزاكشن اللي نادانا (كان بيحصل double rollback قبل كده)
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $before = $snapshot();

            // 1. التعديلات اليدوية على القيود الآلية اللي هتتمسح — بما فيها
            // مين عملها وإمتى وليه، عشان لو اتحفظت لازم يتسجل نفس أثر
            // التدقيق على السطر الجديد (الأصلي هيتمسح مع القيد بالكاسكيد)
            $overrides = [];
            $autoQ = GlEntry::where('origin', 'auto')->whereDate('date', '>=', $from->toDateString());
            $overriddenLines = []; // line_id => ['key' => source|slot, 'to' => account_id]
            foreach ((clone $autoQ)->with('lines')->cursor() as $e) {
                foreach ($e->lines as $l) {
                    if ($l->overridden) {
                        $overriddenLines[$l->id] = ['key' => $e->source_type.'|'.$e->source_id.'|'.$l->slot, 'to' => $l->account_id];
                    }
                }
            }
            $latestOverrideByLine = GlLineOverride::whereIn('line_id', array_keys($overriddenLines))
                ->orderByDesc('id')->get()->groupBy('line_id')->map(fn ($g) => $g->first());
            foreach ($overriddenLines as $lineId => $info) {
                $ov = $latestOverrideByLine->get($lineId);
                $overrides[$info['key']] = [
                    'to' => $info['to'],
                    'user_id' => $ov?->user_id,
                    'at' => $ov?->at,
                    'note' => $ov?->note,
                ];
            }

            $report->deleted = (clone $autoQ)->count();
            (clone $autoQ)->delete(); // gl_lines + gl_line_overrides cascade

            // 2. التوليد
            foreach ($this->sources($from) as $src) {
                $entry = $this->post($src, $by);
                if ($entry === null) {
                    continue;
                }
                $report->created++;
                foreach ($entry->lines as $l) {
                    $k = $entry->source_type.'|'.$entry->source_id.'|'.$l->slot;
                    if (! isset($overrides[$k])) {
                        continue;
                    }
                    if ($keepOverrides) {
                        $ruleAccountId = $l->account_id;
                        $l->update(['account_id' => $overrides[$k]['to'], 'overridden' => true, 'rule_account_id' => $ruleAccountId]);
                        GlLineOverride::create([
                            'line_id' => $l->id,
                            'from_account_id' => $ruleAccountId,
                            'to_account_id' => $overrides[$k]['to'],
                            'user_id' => $overrides[$k]['user_id'],
                            'at' => $overrides[$k]['at'] ?? now(),
                            'note' => $overrides[$k]['note'],
                        ]);
                        $report->overridesKept++;
                    } else {
                        $report->overridesDropped++;
                    }
                    unset($overrides[$k]);
                }
            }
            $report->overridesDropped += count($overrides); // مصدر اتشال

            // 3. الفحص
            $after = $snapshot();
            foreach ($after as $code => $v) {
                if (round(($before[$code] ?? 0.0), 2) !== round($v, 2)) {
                    $report->accountDiff[$code] = ['before' => round($before[$code] ?? 0.0, 2), 'after' => round($v, 2)];
                }
            }
            $report->invariants = $this->invariants();
            // عدد القيود اللي اتمسحت لازم يساوي اللي اتولدت تاني — لو مصدر
            // ضاع (باگ ترتيب/فلترة زي مصدر تاريخه من غير COALESCE) الفحوصات
            // التانية ممكن تعدّي من غيره، ده اللي بيمسكها
            $report->invariants['entries'] = [
                'deleted' => $report->deleted,
                'created' => $report->created,
                'ok' => $report->deleted === $report->created,
            ];
            $report->ok = collect($report->invariants)->every(fn ($i) => $i['ok']);

            if ($dryRun) {
                // dry run مايرميش أبداً — بيرجّع التقرير زي ما هو حتى لو
                // الفحص فشل، عشان اللي بينادي يقدر يعرض النتيجة المتوقعة
                if (DB::transactionLevel() > $level) {
                    DB::rollBack();
                }

                return $report;
            }

            if (! $report->ok) {
                if (DB::transactionLevel() > $level) {
                    DB::rollBack();
                }
                $e = new RebuildFailed(__('gl.rebuild_invariant_failed'));
                $e->report = $report;
                throw $e;
            }

            DB::commit();
        } catch (\Throwable $t) {
            if (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
            throw $t;
        }

        return $report;
    }
}
