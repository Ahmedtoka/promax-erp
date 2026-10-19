<?php

namespace App\Services\Gl;

use App\Models\Gl\CoaCheckAccept;
use App\Models\Gl\CoaDraftAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * فحص مسودة شجرة الحسابات قبل الاعتماد (D4)
 * ═══════════════════════════════════════════════════════════════
 *
 * الأحمر بيوقف الاعتماد ومالوش قبول — لازم يتصلّح في المسودة نفسها.
 * الأصفر تنبيه بس: المحاسب بيقبله، والقبول بيتحفظ لكل (فحص × صف مسودة)
 * في `coa_check_accepts` فمايرجعش يظهر تاني.
 *
 * ⚠️ لفّة واحدة في الذاكرة: الملف الحقيقي ~٢٧٣ صف — كل الجداول اللي محتاجينها
 * (حسابات الدفتر، سطور القيود، الأصناف، القبولات) بتتقري مرة واحدة، مفيش كويري لكل صف.
 */
class CoaChecks
{
    public const RED = ['dup_code', 'no_code', 'parent_balance', 'no_type', 'code_taken'];

    public const YELLOW = ['truncated_name', 'no_arabic', 'per_product', 'unused'];

    /**
     * نوع QuickBooks (بالحروف الصغيرة) ⇒ نوع حسابنا. المعتمِد (`CoaAdopter`) بيستخدم نفس الجدول.
     */
    public const QB_TYPES = [
        'bank' => 'asset',
        'accounts receivable' => 'asset',
        'other current asset' => 'asset',
        'fixed asset' => 'asset',
        'other asset' => 'asset',
        'accounts payable' => 'liability',
        'credit card' => 'liability',
        'other current liability' => 'liability',
        'long term liability' => 'liability',
        'equity' => 'equity',
        'income' => 'revenue',
        'other income' => 'revenue',
        'cost of goods sold' => 'expense',
        'expense' => 'expense',
        'other expense' => 'expense',
    ];

    /** QuickBooks بيقص اسم الحساب عند ٣١ حرف */
    private const QB_NAME_CUT = 31;

    /**
     * @return array{items: list<array{check:string, level:string, draft_id:?int, code:?string, name:string, path:string, accepted:bool}>, red:int, yellow:int, accepted:int}
     */
    public static function run(): array
    {
        $out = ['items' => [], 'red' => 0, 'yellow' => 0, 'accepted' => 0];
        if (! CoaDraftAccount::ready()) {
            return $out;
        }

        $all = CoaDraftAccount::orderBy('sort')->orderBy('id')->get();
        if ($all->isEmpty()) {
            return $out;
        }
        $byId = $all->keyBy('id');
        $hasKids = $all->whereNotNull('parent_id')->pluck('parent_id')->flip();

        $codeOf = fn (CoaDraftAccount $a) => trim((string) $a->code);
        $codes = $all->map($codeOf)->filter(fn ($c) => $c !== '');
        $dups = $codes->countBy()->filter(fn ($n) => $n > 1);

        // حسابات الدفتر: اللي بنفس الأكواد + اللي اتعتمدت من المسودة — كويري واحد
        $gl = DB::table('gl_accounts')
            ->where(fn ($q) => $q->whereIn('code', $codes->unique()->values()->all())->orWhereNotNull('coa_draft_id'))
            ->get(['id', 'code', 'is_system', 'is_postable', 'coa_draft_id']);
        $glByCode = $gl->groupBy('code');
        $adopted = $gl->whereNotNull('coa_draft_id')->keyBy('coa_draft_id');
        $withLines = $adopted->isEmpty() ? collect() : DB::table('gl_lines')
            ->whereIn('account_id', $adopted->pluck('id')->all())
            ->distinct()->pluck('account_id')->flip();

        $products = self::productNames();
        $accepts = CoaCheckAccept::query()->get(['check', 'draft_account_id'])
            ->mapWithKeys(fn ($r) => [$r->check.'|'.$r->draft_account_id => true]);

        foreach ($all as $a) {
            $code = $codeOf($a);
            $name = trim((string) $a->name);
            $isParent = isset($hasKids[$a->id]);
            $balance = (float) ($a->balance ?? 0);
            $glAcc = $adopted[$a->id] ?? null;
            $glHasLines = $glAcc !== null && isset($withLines[$glAcc->id]);
            $found = [];

            // ═══ الأحمر ═══
            if ($code === '') {
                $found[] = 'no_code';
            } elseif (isset($dups[$code])) {
                $found[] = 'dup_code';
            }
            if ($isParent && round($balance, 2) != 0.0) {
                $found[] = 'parent_balance';
            } elseif ($glAcc !== null && ! $glAcc->is_postable && $glHasLines) {
                $found[] = 'parent_balance';
            }
            if (self::typeFor($a, $byId) === null) {
                $found[] = 'no_type';
            }
            if ($code !== '' && collect($glByCode[$code] ?? [])
                ->contains(fn ($g) => ! $g->is_system && (int) $g->coa_draft_id !== $a->id)) {
                $found[] = 'code_taken';
            }

            // ═══ الأصفر ═══
            if (self::looksTruncated($name)) {
                $found[] = 'truncated_name';
            }
            if (trim((string) $a->name_ar) === '') {
                $found[] = 'no_arabic';
            }
            if ($products !== [] && (isset($products[self::norm($name)]) || isset($products[self::norm((string) $a->name_ar)]))) {
                $found[] = 'per_product';
            }
            if (! $isParent && round($balance, 2) == 0.0 && ! $glHasLines) {
                $found[] = 'unused';
            }

            if ($found === []) {
                continue;
            }
            $path = self::path($a, $byId);
            foreach ($found as $check) {
                $level = in_array($check, self::RED, true) ? 'red' : 'yellow';
                $accepted = $level === 'yellow' && isset($accepts[$check.'|'.$a->id]);
                $out['items'][] = [
                    'check' => $check, 'level' => $level, 'draft_id' => $a->id,
                    'code' => $code === '' ? null : $code, 'name' => $name, 'path' => $path, 'accepted' => $accepted,
                ];
                match (true) {
                    $level === 'red' => $out['red']++,
                    $accepted => $out['accepted']++,
                    default => $out['yellow']++,
                };
            }
        }

        return $out;
    }

    public static function hasRed(): bool
    {
        return self::run()['red'] > 0;
    }

    /** قبول تحذير أصفر واحد — الأحمر مالوش قبول */
    public static function accept(string $check, ?int $draftId, User $by): void
    {
        self::assertYellow($check);
        CoaCheckAccept::firstOrCreate(
            ['check' => $check, 'draft_account_id' => $draftId],
            ['accepted_by' => $by->id, 'accepted_at' => now()],
        );
    }

    /** قبول كل صفوف فحص أصفر معيّن اللي لسه ماتقبلتش — بيرجّع العدد */
    public static function acceptAll(string $check, User $by): int
    {
        self::assertYellow($check);
        $now = now();
        $rows = collect(self::run()['items'])
            ->filter(fn ($i) => $i['check'] === $check && ! $i['accepted'])
            ->map(fn ($i) => ['check' => $check, 'draft_account_id' => $i['draft_id'], 'accepted_by' => $by->id, 'accepted_at' => $now])
            ->values()->all();
        if ($rows !== []) {
            DB::transaction(fn () => collect($rows)->chunk(200)
                ->each(fn ($c) => CoaCheckAccept::insert($c->all())));
        }

        return count($rows);
    }

    /**
     * نوع الحساب: نوع QuickBooks بتاعه، وإلا نوع أقرب جد ليه نوع معروف، وإلا `null` (أحمر `no_type`).
     *
     * @param  Collection<int, CoaDraftAccount>  $byId  كل صفوف المسودة بالـid
     */
    public static function typeFor(CoaDraftAccount $a, Collection $byId): ?string
    {
        $node = $a;
        $guard = 0;
        while ($node !== null && $guard++ < 100) {
            $type = self::QB_TYPES[mb_strtolower(trim((string) $node->qb_type))] ?? null;
            if ($type !== null) {
                return $type;
            }
            $node = $node->parent_id ? $byId->get($node->parent_id) : null;
        }

        return null;
    }

    private static function assertYellow(string $check): void
    {
        if (! in_array($check, self::YELLOW, true)) {
            throw new \InvalidArgumentException(__('coa.check_not_acceptable'));
        }
    }

    /** الاسم بينتهي بـ`...` أو طوله ≥ ٣١ من غير مسافة في آخر ٣ حروف (QuickBooks قصّه) */
    private static function looksTruncated(string $name): bool
    {
        if (str_ends_with($name, '...') || str_ends_with($name, '…')) {
            return true;
        }

        return mb_strlen($name) >= self::QB_NAME_CUT && ! str_contains(mb_substr($name, -3), ' ');
    }

    /** @return array<string, true> أسماء وأكواد الأصناف بعد التنضيف — مرة واحدة */
    private static function productNames(): array
    {
        if (! Schema::hasTable('products')) {
            return [];
        }
        $cols = array_values(array_filter(['name', 'name_en', 'code'], fn ($c) => Schema::hasColumn('products', $c)));
        $set = [];
        foreach (DB::table('products')->get($cols) as $p) {
            foreach ($cols as $c) {
                $v = self::norm((string) $p->{$c});
                if ($v !== '') {
                    $set[$v] = true;
                }
            }
        }

        return $set;
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(trim($s));
    }

    /** المسار من الجذر: «11 CURRENT ASSETS › 111 CASH › 1111 Cash on hand» */
    private static function path(CoaDraftAccount $a, Collection $byId): string
    {
        $parts = [];
        $node = $a;
        $guard = 0;
        while ($node !== null && $guard++ < 100) {
            array_unshift($parts, trim($node->code.' '.$node->name));
            $node = $node->parent_id ? $byId->get($node->parent_id) : null;
        }

        return implode(' › ', $parts);
    }
}
