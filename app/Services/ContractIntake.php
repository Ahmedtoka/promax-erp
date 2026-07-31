<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractClause;
use Illuminate\Http\UploadedFile;

/**
 * ═══════════════════════════════════════════════════════════════
 * بنود العقد الجاهزة + ملف العقد — المكان الوحيد
 * ═══════════════════════════════════════════════════════════════
 *
 * الفورم بيبعت البنود كتشيك بوكس + رقم. الكلاس ده بيحوّلهم لصفوف
 * في `contract_clauses` وبيخلّي `Contract::recalcFromClauses()` هي
 * اللي تحسب النِسَب — مش الفورم.
 *
 * ⚠️ **ممنوع الفورم يكتب `discount` مباشرة على العقد.** لو كتبها
 * وكتب بنود كمان، `recalcFromClauses()` بتيجي بعده وتدوس عليها،
 * والمستخدم بيشوف رقم غير اللي كتبه من غير أي رسالة.
 */
class ContractIntake
{
    /** قواعد التحقق لبلوك البنود — مصدر واحد للفورمين */
    public static function rules(): array
    {
        return [
            'clause' => ['nullable', 'array'],
            'clause.*.on' => ['nullable', 'boolean'],
            // ⚠️ `max` كبير عن قصد: البند ممكن يكون مبلغ سنوي بالآلاف.
            // حدّ الـ100 بتاع النسبة بيتفحص تحت حسب نوع البند.
            // ⚠️ `required_if` مهمة: البند المتعلّم بقيمة فاضية كان
            // بيتمسح في صمت والشاشة بترجع «اتحفظ» خضرا — يعني خصم
            // موجود بينزل صفر لمجرد إن المستخدم عدّى الخانة بالتاب.
            'clause.*.value' => ['nullable', 'numeric', 'min:0', 'max:99999999',
                'required_if:clause.*.on,1'],
            // ⚠️ **خصم الفاتورة إجباري مع العقد.** هو البند الوحيد اللي
            // بينزل على سعر البيع، وعقد اتحفظ من غيره معناه إن أول
            // فاتورة بتطلع بسعر كامل — والعميل بيرفض الاستلام.
            // بيقبل صفر: فيه عملاء فعلاً على سعر القائمة.
            'clause.invoice_discount.value' => ['required_if:has_contract,1',
                'numeric', 'min:0', 'max:100'],
            'clause.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * البنود اللي الفورم **ممنوع** يلمسها.
     *
     * ⚠️ الـ22 عقد الحقيقيين اتقروا من الـPDF وبنودهم اتكتبت بإيد
     * من غير `preset`. لو الفورم كتب بند جاهز من نفس النوع، الاتنين
     * بيتجمعوا في `recalcFromClauses()` والخصم بيتضاعف: On The Run
     * من 15% لـ 30%، وBassem Market من 40% لـ 80% — من غير أي رسالة.
     * فالبند اللي ليه نظير مكتوب بإيد بيتقفل في الشاشة، وبيتعدّل من
     * صفحة العقد اللي فيها البنود بنصها الأصلي.
     *
     * @return array<string, bool>
     */
    public static function lockedPresets(?Contract $contract): array
    {
        $locked = array_fill_keys(array_keys(Contract::CLAUSE_PRESETS), false);

        if ($contract === null) {
            return $locked;
        }

        $manualKinds = $contract->contractClauses
            ->filter(fn (ContractClause $c) => $c->preset === null && $c->counts())
            ->pluck('kind')
            ->unique()
            ->all();

        foreach (Contract::CLAUSE_PRESETS as $preset => $spec) {
            $locked[$preset] = in_array($spec['kind'], $manualKinds, true);
        }

        return $locked;
    }

    /**
     * كتابة البنود الجاهزة على العقد.
     *
     * ⚠️ بيلمس الصفوف اللي `preset` مليان فيها **بس**. البنود اللي
     * اتقريت من الـPDF وكتبت بإيد ماليهاش `preset`، فالفورم مايقدرش
     * يمسحها ولا يدوس عليها.
     *
     * @param  array<string, array<string, mixed>>  $input  المفتاح = اسم البند الجاهز
     */
    public static function syncClauses(Contract $contract, array $input): void
    {
        $sort = 0;
        $locked = self::lockedPresets($contract);

        foreach (Contract::CLAUSE_PRESETS as $preset => $spec) {
            // ⚠️ البند المقفول ليه نظير مكتوب بإيد من العقد الأصلي.
            // اللمس هنا معناه بندين بنفس النوع بيتجمعوا = خصم مضاعف.
            if ($locked[$preset]) {
                continue;
            }

            $row = $input[$preset] ?? [];
            $on = (bool) ($row['on'] ?? false);
            $value = (float) ($row['value'] ?? 0);

            $existing = ContractClause::where('contract_id', $contract->id)
                ->where('preset', $preset)
                ->first();

            // ⚠️ القيمة صفر مع تشيك بوكس متعلّم = بند مالوش أثر.
            // لو سيبناه، بيبان في كارت العميل كأنه شرط حقيقي بـ 0%
            // والمندوب بيقول للعميل "عندك خصم" وهو مفيش.
            if (! $on || $value <= 0) {
                $existing?->delete();

                continue;
            }

            $isPct = Contract::presetIsPct($preset);

            // ⚠️ النسبة بتتقفل على 100%. خصم 150% بيخلّي سعر البيع
            // بالسالب والفاتورة بتطلع بمبلغ سالب من غير ما ترفض.
            if ($isPct) {
                $value = min($value, 100.0);
            }

            $attrs = [
                'contract_id' => $contract->id,
                'preset' => $preset,
                'kind' => $spec['kind'],
                'basis' => $spec['basis'],
                // الاسم بيتخزن باللغتين من ملفات اللغة — مش من الواجهة
                'label' => __('client.preset_'.$preset, [], 'ar'),
                'label_en' => __('client.preset_'.$preset, [], 'en'),
                'pct' => $isPct ? round($value / 100, 4) : null,
                'amount' => $isPct ? null : round($value, 2),
                'note' => ($row['note'] ?? null) ?: null,
                'is_alternative' => false,
                'is_uncertain' => false,
                'sort' => $sort++,
            ];

            if ($existing) {
                $existing->update($attrs);
            } else {
                ContractClause::create($attrs);
            }
        }

        // ⚠️ لازم `fresh` — العقد اللي في الذاكرة لسه شايف العلاقة
        // القديمة، و`recalcFromClauses()` كانت هتجمّع البنود قبل التعديل.
        $contract->load('contractClauses');
        $contract->recalcFromClauses();
    }

    /**
     * البنود الجاهزة الموجودة على عقد — لتعبئة الفورم.
     *
     * @return array<string, array{on: bool, value: float, note: ?string}>
     */
    public static function currentPresets(?Contract $contract): array
    {
        $out = [];

        foreach (array_keys(Contract::CLAUSE_PRESETS) as $preset) {
            $out[$preset] = ['on' => false, 'value' => 0.0, 'note' => null, 'locked' => false];
        }

        if ($contract === null) {
            return $out;
        }

        $locked = self::lockedPresets($contract);

        foreach ($contract->contractClauses as $clause) {
            $preset = (string) $clause->preset;

            if (! isset($out[$preset])) {
                continue;
            }

            $out[$preset] = [
                'on' => true,
                'value' => Contract::presetIsPct($preset)
                    ? round((float) $clause->pct * 100, 2)
                    : round((float) $clause->amount, 2),
                'note' => $clause->note,
                'locked' => false,
            ];
        }

        // ═══ البنود المقفولة: بنعرض قيمتها الحقيقية من العقد الأصلي ═══
        // ⚠️ لازم تتعرض بقيمتها. لو بانت فاضية، المستخدم بيفتكر مفيش
        // خصم ويكتب واحد جديد — ويطلع خصمين على نفس البند.
        foreach ($locked as $preset => $isLocked) {
            if (! $isLocked) {
                continue;
            }

            $spec = Contract::CLAUSE_PRESETS[$preset];
            $rows = $contract->contractClauses
                ->filter(fn (ContractClause $c) => $c->preset === null
                    && $c->kind === $spec['kind'] && $c->counts());

            $out[$preset] = [
                'on' => true,
                'locked' => true,
                'value' => Contract::presetIsPct($preset)
                    ? round($rows->sum(fn ($c) => (float) $c->pct) * 100, 2)
                    : round($rows->sum(fn ($c) => (float) $c->amount), 2),
                'note' => $rows->first()?->displayNote(),
            ];
        }

        // ⚠️ عقود قديمة خصمها متخزن على العقد نفسه من غير أي بند. من
        // غير السطر ده، أول ما حد يفتح العقد ويحفظ، التشيك بوكس بيبقى
        // فاضي والخصم بينزل صفر في صمت — والعميل بياخد سعر كامل تاني
        // يوم. **بس مش لو البند مقفول** — ساعتها الرقم جاي من البند
        // المكتوب بإيد، وإضافة بند جاهز فوقه بتضاعف الخصم.
        if (! $out['invoice_discount']['on'] && (float) $contract->discount > 0) {
            $out['invoice_discount'] = [
                'on' => true,
                'value' => round((float) $contract->discount * 100, 2),
                'note' => null,
                'locked' => false,
            ];
        }

        return $out;
    }

    /**
     * تخزين نسخة العقد على السيرفر.
     *
     * ⚠️ **مش في `storage/app/public`.** العقد فيه أسعار وشروط تجارية،
     * والديسك العام معناه إن أي حد يعرف اسم الملف يفتحه من غير لوجين.
     * الملفات في `storage/app/contracts` وبتتقدّم من راوت جوه `auth`.
     *
     * ⚠️ اسم الملف بيتولّد — ممنوع اسم اليوزر. `../../` في اسم ملف
     * مرفوع بيكتب بره المجلد.
     */
    public static function storeFile(Contract $contract, UploadedFile $file): string
    {
        $dir = storage_path('app/contracts');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // ⚠️ الامتداد من **محتوى** الملف مش من اسمه. اسم الملف بييجي من
        // جهاز المستخدم: PDF حقيقي اسمه `x.phar` كان بيعدّي التحقق
        // (اللي بيفحص المحتوى) ويتخزن باسم `.phar` جوه مجلد بيتقدّم.
        $ext = strtolower($file->guessExtension() ?: 'pdf');
        $name = $contract->number.'-'.now()->format('YmdHis').'.'.$ext;

        // ⚠️ النسخة القديمة بتتمسح. من غير ده كل رفع بيسيب ملف يتيم
        // مفيش أي صف بيشاور عليه — والمجلد بيكبر بعقود محدش يعرف
        // بتاعة مين ولا ينفع يتمسحوا بأمان بعد كده.
        $old = (string) $contract->file_path;

        $file->move($dir, $name);

        if ($old !== '' && $old !== 'contracts/'.$name && str_starts_with($old, 'contracts/')) {
            $realOld = realpath(storage_path('app/'.$old));
            $root = realpath($dir);

            if ($realOld !== false && $root !== false && str_starts_with($realOld, $root)) {
                @unlink($realOld);
            }
        }

        return 'contracts/'.$name;
    }
}
