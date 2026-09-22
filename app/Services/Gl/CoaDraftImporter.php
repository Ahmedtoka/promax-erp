<?php

namespace App\Services\Gl;

use App\Models\Gl\CoaDraftAccount;
use App\Services\Sheet;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تنزيل ملف شجرة حسابات العميل **زي ما هو** (٢٢ سبتمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * الملف تقرير Account Listing من QuickBooks: المسار الكامل لكل حساب في عمود
 * «Account» مفصول بـ`:` والكود الرقمي قبل الاسم، وبعده النوع والرصيد والوصف.
 *
 * قرار المالك: «نزّلها زي ما هي، متعملش مابينج لأي شيء، بكل حاجة حتى لو فيه تكرار».
 * فمفيش هنا أي تصحيح:
 *   • الصف المكرر بينزل صفين.
 *   • الحساب اللي من غير كود بينزل من غير كود.
 *   • الحساب اللي مساره في الملف بيبدأ من الجذر بيفضل جذر، حتى لو كوده بيقول
 *     إنه تابع لحساب تاني — ترتيبه في الشجرة شغل المالك من الشاشة.
 *   • النوع بيتسجل بنصّه (Bank / Other Current Asset…) من غير تحويل لأنواعنا.
 *
 * الاستثناء الوحيد المتفق عليه: حسابين الإيراد «Key Accounts» و«Cash Vans»
 * بياخدوا علامة `feed` عشان الشاشة تعرض جنبهم مبيعات السيستم — عرض بس.
 *
 * ⚠️ الملف نفسه مابيتخزنش في الريبو (فيه أرصدة الشركة والريبو عام) — بيترفع
 * من الشاشة.
 */
class CoaDraftImporter
{
    /**
     * @return array{rows: int, roots: int, no_code: int, duplicates: int}
     */
    public static function import(string $path, bool $replace): array
    {
        $rows = Sheet::rows($path);
        $cols = self::columns($rows);

        if ($cols === null) {
            throw new \InvalidArgumentException(__('coa.bad_file'));
        }

        return DB::transaction(function () use ($rows, $cols, $replace) {
            if ($replace) {
                CoaDraftAccount::query()->delete();
            }

            $lastByPath = [];   // المسار ⇒ آخر حساب اتعمل بيه (الولاد بيتعلقوا في آخر نسخة)
            $sortUnder = [];    // الأب ⇒ آخر ترتيب
            $seen = [];
            $stats = ['rows' => 0, 'roots' => 0, 'no_code' => 0, 'duplicates' => 0];

            foreach ($rows as $i => $row) {
                if ($i <= $cols['header']) {
                    continue;
                }

                $full = trim((string) ($row[$cols['account']] ?? ''));

                if ($full === '') {
                    continue;
                }

                $parts = explode(':', $full);
                $leaf = trim((string) array_pop($parts));
                $parentPath = implode(':', $parts);
                $parent = $parentPath === '' ? null : ($lastByPath[$parentPath] ?? null);

                // «1111  Cash on hand» ⇒ كود 1111 واسم Cash on hand؛ من غير أرقام ⇒ كود فاضي
                $code = null;
                $name = $leaf;

                if (preg_match('/^(\d[\d.\-]*)\s+(.+)$/u', $leaf, $m)) {
                    [$code, $name] = [$m[1], trim($m[2])];
                }

                $key = (int) ($parent?->id ?? 0);
                $sortUnder[$key] = ($sortUnder[$key] ?? 0) + 1;

                $balance = $cols['balance'] !== null ? Sheet::number($row[$cols['balance']] ?? null) : null;
                $lower = mb_strtolower($name);

                $node = CoaDraftAccount::create([
                    'parent_id' => $parent?->id,
                    'sort' => $sortUnder[$key],
                    'code' => $code,
                    'name' => mb_substr($name, 0, 190),
                    'qb_type' => self::text($row, $cols['type'], 60),
                    'balance' => $balance,
                    'description' => self::text($row, $cols['description'], 500),
                    'tax_line' => self::text($row, $cols['tax'], 120),
                    'feed' => match (true) {
                        str_contains($lower, 'key account') => 'sales_ka',
                        str_contains($lower, 'cash van') => 'sales_van',
                        default => null,
                    },
                    'source_row' => $i + 1,
                    'source_path' => $full,
                ]);

                $stats['rows']++;
                $stats['roots'] += $parent === null ? 1 : 0;
                $stats['no_code'] += $code === null ? 1 : 0;
                $stats['duplicates'] += isset($seen[$full]) ? 1 : 0;
                $seen[$full] = true;
                $lastByPath[$full] = $node;
            }

            return $stats;
        });
    }

    /**
     * أماكن الأعمدة من صف العناوين — الملف فيه أعمدة فاضية بين كل عمودين.
     *
     * @return ?array{header:int, account:int, type:?int, balance:?int, description:?int, tax:?int}
     */
    private static function columns(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 15, true) as $i => $row) {
            $find = function (string ...$names) use ($row) {
                foreach ($row as $c => $v) {
                    if (in_array(mb_strtolower(trim((string) $v)), $names, true)) {
                        return $c;
                    }
                }

                return null;
            };

            $account = $find('account', 'الحساب');

            if ($account !== null) {
                return [
                    'header' => $i, 'account' => $account, 'type' => $find('type', 'النوع'),
                    'balance' => $find('balance total', 'balance', 'الرصيد'),
                    'description' => $find('description', 'الوصف'), 'tax' => $find('tax line'),
                ];
            }
        }

        return null;
    }

    private static function text(array $row, ?int $col, int $max): ?string
    {
        $v = $col === null ? '' : trim((string) ($row[$col] ?? ''));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
