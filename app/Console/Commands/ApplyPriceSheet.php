<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Contract;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Pricing;
use App\Services\Reprice\PriceChange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ═══════════════════════════════════════════════════════════════
 * تطبيق شيت أسعار العملاء — `prices:apply-sheet` (١٠/١١/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * المبدأ (قرار المالك): **السعر المتخزّن غير شامل دايماً** في القوايم
 * والمستندات، والضريبة بتتضاف فوقه مرة واحدة. «شامل» عرض بس.
 *
 * الأمر ده بيصلّح **البيانات الأساسية** بس — مابيلمسش ولا فاتورة ولا
 * أمر توريد (إعادة تسعير المستندات خطوة لوحدها من معاينة PriceChange).
 *
 *   php artisan prices:apply-sheet --plan=../price_plan.json              ← dry-run (الافتراضي)
 *   php artisan prices:apply-sheet --plan=../price_plan.json --apply      ← ترانزاكشن واحدة + snapshot + سجل تغييرات
 *   php artisan prices:apply-sheet --rollback=storage/app/price-sheet/snapshot-….json [--apply]
 *
 * ⚠️ **أسعار وخصومات العملاء مش في الكود** (الريبو عام) — بتيجي من ملف
 * الخطة (`--plan`) اللي بيتحفظ برا الريبو. الكود فيه القواعد العامة بس:
 *   rounding  أسعار المستهلك أرقام صحيحة → new/old = round(صحيح ÷ 1.14، 2)
 *   incl      بند في قايمة صافية = سعر المستهلك الشامل بالظبط → يتحوّل لغير شامل
 *   items     بنود من الخطة (أسعار الشيت غير الشاملة) — بتكسب على اللي فوق
 *   taxable   كل العملاء والسلاسل خاضعين (قرار ١٠/١١)
 *   reactivate فروع اتقفلت غلط كـ«مكرر» ترجع active (من الخطة)
 *   link      فروع سايبة تتربط بسلستها (من الخطة)
 *   assign    قايمة + خصم لكل عميل/سلسلة — عميل، عقده السارٍ، السلسلة وعقدها (من الخطة)
 *   lists     مفيش عميل من غير قايمة: قايمة السلسلة، وإلا الافتراضية
 *   retire    توقيف/مسح القوايم اللي فضيت (من الخطة) — بعد النقل، ولو لسه عليها حد بتتساب
 *   rename    أسماء القوايم (من الخطة، لو `renames_approved`) — الكود مابيتغيرش أبداً
 *
 * سجل التغييرات (dry-run و apply): صف لكل حقل اتغيّر — قبل ← بعد — في
 * `storage/app/price-sheet/changelog-*.json` (ونسخة في `--log` لو اتبعت).
 * ده اللي بيغذّي تقرير PRICE_FIX_SUMMARY.
 */
class ApplyPriceSheet extends Command
{
    protected $signature = 'prices:apply-sheet
        {--plan= : ملف الخطة (JSON برا الريبو)}
        {--apply : اكتب فعلاً (من غيره dry-run)}
        {--user= : اليوزر اللي التعديلات تتسجّل باسمه (الافتراضي أول أدمن)}
        {--log= : مسار نسخة إضافية من سجل التغييرات}
        {--rollback= : رجّع snapshot اتعمل قبل كده}
        {--only= : خطوات معيّنة: rounding,incl,items,taxable,reactivate,link,assign,lists,retire,rename}';

    protected $description = 'تطبيق شيت أسعار العملاء على البيانات الأساسية (dry-run افتراضياً)';

    private const VAT = 1.14;

    /** برا النطاق — مابتتلمسش (قرار ١٠/١١: الكاش فان لوحده بعدين) */
    private const OUT_OF_SCOPE = ['CashVan', 'totalfranchise'];

    /** @var list<array<string, mixed>> سجل التغييرات */
    private array $log = [];

    /** @var array<string, mixed> قيم قبل التعديل — للرجوع */
    private array $snapshot = ['items' => [], 'clients' => [], 'groups' => [], 'contracts' => [], 'lists' => []];

    private array $plan = [];

    private bool $apply = false;

    public function handle(): int
    {
        if ($rb = $this->option('rollback')) {
            return $this->rollback((string) $rb);
        }

        $this->apply = (bool) $this->option('apply');
        if ($p = $this->option('plan')) {
            $this->plan = json_decode((string) @file_get_contents((string) $p), true) ?: [];
            if ($this->plan === []) {
                $this->error('ملف الخطة مش موجود أو مش JSON: '.$p);

                return self::FAILURE;
            }
        }

        $only = $this->option('only') ? array_map('trim', explode(',', (string) $this->option('only'))) : null;
        $want = fn (string $s) => $only === null || in_array($s, $only, true);

        $this->info($this->apply ? '══ APPLY ══' : '══ DRY-RUN (مفيش كتابة) ══');
        $this->line('DB: '.DB::connection()->getDatabaseName().($this->plan ? ' · plan: '.$this->option('plan') : ' · من غير خطة (القواعد العامة بس)'));

        $by = $this->option('user') ? User::findOrFail((int) $this->option('user'))
            : User::where('role', 'admin')->orderBy('id')->first();

        $run = function () use ($want, $by) {
            if ($want('rounding') || $want('incl') || $want('items')) {
                $this->listItems($want, $by);
            }
            if ($want('taxable')) {
                $this->taxable();
            }
            if ($want('reactivate')) {
                $this->reactivate();
            }
            if ($want('link')) {
                $this->links();
            }
            if ($want('assign')) {
                $this->assign();
            }
            if ($want('lists')) {
                $this->everyClientHasList();
            }
            if ($want('retire')) {
                $this->retire();
            }
            if ($want('rename')) {
                $this->rename();
            }
        };

        // ⚠️ الـdry-run بيشغّل نفس الكود جوه ترانزاكشن وبيرجّعها — فالسجل
        // بتاعه = اللي التطبيق هيعمله بالحرف (نفس فكرة معاينة PriceChange).
        DB::beginTransaction();
        try {
            $run();
            $this->apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            PriceList::flushCache();
        }

        $this->report();
        $this->writeFiles();

        return self::SUCCESS;
    }

    // ═══════════════════════ بنود القوايم ═══════════════════════

    private function listItems(callable $want, ?User $by): void
    {
        $retiring = array_merge($this->plan['deactivate'] ?? [], $this->plan['delete'] ?? []);
        $target = []; // list_id => [product_id => [price, why]]

        if ($want('rounding')) {
            foreach (PriceList::whereIn('code', ['new', 'old'])->get() as $list) {
                foreach ($list->items()->get() as $it) {
                    $p = (float) $it->price;
                    $incl = $p * self::VAT;
                    if ($p <= 0 || abs($incl - round($incl)) >= 0.02) {
                        continue;
                    }
                    $t = round(round($incl) / self::VAT, 2);
                    if (abs($t - $p) >= 0.005) {
                        $target[$list->id][$it->product_id] = [$t, 'rounding'];
                    }
                }
            }
        }

        if ($want('incl') && ($new = PriceList::where('code', 'new')->first())) {
            $consumerIncl = [];
            foreach ($new->items()->get() as $it) {
                $consumerIncl[$it->product_id] = round((float) $it->price * self::VAT);
            }
            $lists = PriceList::whereNotIn('code', array_merge(['new', 'old'], self::OUT_OF_SCOPE, $retiring))->get();
            foreach ($lists as $list) {
                foreach ($list->items()->get() as $it) {
                    $ci = $consumerIncl[$it->product_id] ?? 0;
                    if ($ci > 0 && abs((float) $it->price - $ci) < 0.005) {
                        $target[$list->id][$it->product_id] = [round($ci / self::VAT, 2), 'incl→excl'];
                    }
                }
            }
        }

        if ($want('items')) {
            foreach ($this->plan['items'] ?? [] as $row) {
                $list = PriceList::where('code', $row['list'])->first();
                $pid = Product::where('barcode', $row['barcode'])->value('id');
                if (! $list || ! $pid) {
                    $this->logRow('items', 'price_list_item', null, $row['list'].' · '.$row['barcode'], 'price', null, null, '⚠️ القايمة أو الصنف مش موجود');

                    continue;
                }
                $target[$list->id][$pid] = [round((float) $row['price'], 2), $row['why'] ?? 'plan'];
            }
        }

        foreach ($target as $listId => $map) {
            $list = PriceList::find($listId);
            $prices = [];
            foreach ($map as $pid => [$price, $why]) {
                $cur = PriceListItem::where(['price_list_id' => $listId, 'product_id' => $pid])->value('price');
                if ($cur !== null && abs((float) $cur - $price) < 0.005) {
                    continue;
                }
                $prices[$pid] = $price;
                $this->snapshot['items'][] = ['price_list_id' => $listId, 'product_id' => $pid, 'price' => $cur === null ? null : (float) $cur];
                $this->logRow($why === 'rounding' ? 'rounding' : ($why === 'incl→excl' ? 'incl' : 'items'), 'price_list_item', $listId,
                    $list->code.' · '.$this->pname($pid), 'price', $cur === null ? null : (float) $cur, $price, $why);
            }
            if ($prices === []) {
                continue;
            }
            // محرك تعديل الأسعار «للبيع الجديد بس» — سجل في price_change_runs
            // + كتابة عكسية لعمودي الصنف للقايمتين old/new
            if ($by) {
                $run = PriceChange::draft($by, 'list', ['price_list_id' => $listId, 'prices' => $prices]);
                PriceChange::applyMasterOnly($run, $by);
            } else {
                PriceChange::writeListPrices($list, $prices);
            }
        }
    }

    // ═══════════════════════ العملاء والسلاسل ═══════════════════════

    private function taxable(): void
    {
        foreach (Client::where(fn ($q) => $q->where('taxable', false)->orWhereNull('taxable'))->get() as $c) {
            $this->setClient($c, ['taxable' => true], 'taxable', 'الكل خاضع (قرار ١٠/١١)');
        }
        foreach (ClientGroup::where(fn ($q) => $q->where('taxable', false)->orWhereNull('taxable'))->get() as $g) {
            $this->setGroup($g, ['taxable' => true], 'taxable', 'الكل خاضع (قرار ١٠/١١)');
        }
    }

    private function reactivate(): void
    {
        foreach ($this->plan['reactivate'] ?? [] as $row) {
            $c = Client::where('code', $row['code'])->first();
            if (! $c) {
                continue;
            }
            $f = ['status' => 'active'];
            if (! empty($row['note_remove']) && $c->notes) {
                $f['notes'] = trim(str_replace($row['note_remove'], '', (string) $c->notes)) ?: null;
            }
            $this->setClient($c, $f, 'reactivate', 'مش مكرر — فرع مختلف (قرار ١٠/١١)');
        }
    }

    private function links(): void
    {
        foreach ($this->plan['links'] ?? [] as $code => $groupName) {
            $c = Client::where('code', $code)->first();
            // ⚠️ أحياناً فيه سلسلتين بنفس الاسم (ديلي مارت، واي تو جو) — الفرع
            // بيتربط بالأكبر (اللي عليها فروع أكتر). الاتنين بياخدوا نفس السعر من `assign`.
            $g = ClientGroup::where('name', $groupName)->withCount('clients')
                ->orderByDesc('clients_count')->orderBy('id')->first();
            if (! $c || ! $g) {
                $this->logRow('link', 'client', null, $code, 'group_id', null, $groupName, '⚠️ العميل أو السلسلة مش موجود');

                continue;
            }
            $this->setClient($c, ['group_id' => $g->id], 'link', 'ربط بالسلسلة «'.$g->name.'»');
        }
    }

    /**
     * قايمة + خصم — بنفس طبقات `listRowFor`/`effectiveDiscount`:
     * العميل نفسه، وعقده الشخصي السارٍ (بيكسب عليه)، وبيانات السلسلة
     * وعقدها السارٍ (عشان أي فرع جديد ياخدهم).
     */
    private function assign(): void
    {
        foreach ($this->plan['assign'] ?? [] as $a) {
            $list = PriceList::where('code', $a['list'])->first();
            if (! $list) {
                $this->logRow('assign', 'price_list', null, $a['list'], 'code', null, null, '⚠️ القايمة مش موجودة');

                continue;
            }
            $d = round((float) $a['discount'], 4);
            $why = ($a['source'] ?? 'sheet') === 'sheet' ? 'الشيت يكسب · '.$a['label'] : 'مش في الشيت: السيستم + قايمة غير شاملة · '.$a['label'];
            $text = in_array($list->code, Pricing::LISTS, true) ? $list->code : $list->code;

            foreach (Client::whereIn('code', $a['clients'] ?? [])->get() as $c) {
                $this->setClient($c, ['price_list_id' => $list->id, 'price_list' => $text, 'discount' => $d], 'assign', $why);
                $own = $c->contract;
                if ($own && $own->active && ! $own->isExpired()) {
                    $this->setContract($own, ['discount' => $d, 'price_list_id' => $list->id, 'price_list' => $list->code], $why.' (عقد الفرع)');
                }
            }
            foreach ($a['groups'] ?? [] as $gName) {
                foreach (ClientGroup::where('name', $gName)->get() as $g) {
                    $this->setGroup($g, ['price_list_id' => $list->id, 'default_discount' => $d], 'assign', $why);
                    $ct = $g->contract;
                    if ($ct && $ct->active && ! $ct->isExpired()) {
                        $this->setContract($ct, ['discount' => $d, 'price_list_id' => $list->id, 'price_list' => $list->code], $why.' (عقد السلسلة)');
                    }
                }
            }
        }
    }

    private function everyClientHasList(): void
    {
        $default = PriceList::default();
        foreach (Client::whereNull('price_list_id')->get() as $c) {
            $listId = ($c->group_id ? ClientGroup::whereKey($c->group_id)->value('price_list_id') : null) ?: $default?->id;
            if ($listId) {
                $code = PriceList::whereKey($listId)->value('code');
                $this->setClient($c, ['price_list_id' => $listId, 'price_list' => $code], 'lists', 'مفيش عميل من غير قايمة');
            }
        }
    }

    // ═══════════════════════ القوايم نفسها ═══════════════════════

    private function retire(): void
    {
        foreach (['deactivate' => false, 'delete' => true] as $key => $hard) {
            foreach ($this->plan[$key] ?? [] as $code) {
                $l = PriceList::where('code', $code)->first();
                if (! $l) {
                    continue;
                }
                // العقود المنتهية تاريخ — `listRowFor` مابيقراهاش، فمابتمنعش التوقيف
                $liveContracts = Contract::where('price_list_id', $l->id)->where('active', true)->get()
                    ->filter(fn ($ct) => ! $ct->isExpired())->count();
                $refs = Client::where('price_list_id', $l->id)->count()
                    + ClientGroup::where('price_list_id', $l->id)->count()
                    + $liveContracts;
                if ($refs > 0) {
                    $this->logRow('retire', 'price_list', $l->id, $code, 'active', (bool) $l->active, (bool) $l->active, "⚠️ لسه عليها {$refs} (فرع/سلسلة/عقد) — اتسابت");

                    continue;
                }
                $docs = DB::table('invoices')->where('price_list', $code)->count() + DB::table('purchase_orders')->where('price_mode', $code)->count();
                if ($hard && $docs === 0) {
                    $this->snapshot['lists'][] = ['deleted' => $l->toArray(), 'items' => $l->items()->get(['product_id', 'price'])->toArray()];
                    $this->logRow('retire', 'price_list', $l->id, $code, 'deleted', false, true, 'مش مربوطة بحاجة ومفيش مستند عليها');
                    $l->items()->delete();
                    $l->delete();
                } elseif ($l->active || $l->is_default) {
                    $this->snapshot['lists'][] = ['id' => $l->id, 'active' => (bool) $l->active];
                    $this->logRow('retire', 'price_list', $l->id, $code, 'active', true, false, $docs ? "توقيف بس: عليها {$docs} مستند قديم" : 'توقيف');
                    $l->forceFill(['active' => false])->save();
                }
            }
        }
    }

    private function rename(): void
    {
        // `renames_approved`: true = الكل، أو قايمة أكواد اتوافق عليها بس
        $ok = $this->plan['renames_approved'] ?? false;
        $approved = fn (string $code) => $ok === true || (is_array($ok) && in_array($code, $ok, true));

        foreach ($this->plan['renames'] ?? [] as $code => $name) {
            if (! $approved($code)) {
                $this->logRow('rename', 'price_list', null, $code, 'name', PriceList::where('code', $code)->value('name'), $name, 'TODO(answer) مستني موافقة على الاسم');

                continue;
            }
            $l = PriceList::where('code', $code)->first();
            if ($l && $l->name !== $name) {
                $this->snapshot['lists'][] = ['id' => $l->id, 'name' => $l->name];
                $this->logRow('rename', 'price_list', $l->id, $code, 'name', $l->name, $name, 'الاسم بس — الكود ثابت (المستندات شايلاه)');
                $l->forceFill(['name' => $name])->save();
            }
        }
    }

    // ═══════════════════════ الكتابة + السجل ═══════════════════════

    private function setClient(Client $c, array $fields, string $step, string $why): void
    {
        $changed = $this->diff($c, $fields);
        if ($changed === []) {
            return;
        }
        $this->snapshot['clients'][$c->id] = array_merge($c->only(array_keys($changed)), $this->snapshot['clients'][$c->id] ?? []);
        foreach ($changed as $k => $v) {
            $this->logRow($step, 'client', $c->id, $c->code.' '.$c->name, $k, $c->getOriginal($k), $v, $why);
        }
        $c->forceFill($changed)->save();
    }

    private function setGroup(ClientGroup $g, array $fields, string $step, string $why): void
    {
        $changed = $this->diff($g, $fields);
        if ($changed === []) {
            return;
        }
        $this->snapshot['groups'][$g->id] = array_merge($g->only(array_keys($changed)), $this->snapshot['groups'][$g->id] ?? []);
        foreach ($changed as $k => $v) {
            $this->logRow($step, 'client_group', $g->id, 'سلسلة '.$g->name, $k, $g->getOriginal($k), $v, $why);
        }
        $g->forceFill($changed)->save();
    }

    private function setContract(Contract $ct, array $fields, string $why): void
    {
        $changed = $this->diff($ct, $fields);
        if ($changed === []) {
            return;
        }
        $this->snapshot['contracts'][$ct->id] = array_merge($ct->only(array_keys($changed)), $this->snapshot['contracts'][$ct->id] ?? []);
        foreach ($changed as $k => $v) {
            $this->logRow('assign', 'contract', $ct->id, 'عقد #'.$ct->id.' '.($ct->chain ?? ''), $k, $ct->getOriginal($k), $v, $why);
        }
        $ct->forceFill($changed)->save();
    }

    /** الحقول اللي فعلاً هتتغيّر (أرقام بتتقارن بفرق صغير) */
    private function diff($model, array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            $cur = $model->getAttribute($k);
            $same = is_numeric($cur) && is_numeric($v) && ! is_bool($v)
                ? abs((float) $cur - (float) $v) < 0.00005
                : (is_bool($v) ? (bool) $cur === $v : (string) $cur === (string) $v);
            if (! $same) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private function logRow(string $step, string $entity, ?int $id, string $label, string $field, mixed $before, mixed $after, string $why): void
    {
        $this->log[] = compact('step', 'entity', 'id', 'label', 'field', 'before', 'after', 'why');
    }

    private function report(): void
    {
        foreach (collect($this->log)->groupBy('step') as $step => $rows) {
            $this->newLine();
            $this->info("── {$step} (".count($rows).') ──');
            $this->table(['البند', 'الحقل', 'قبل', 'بعد', 'ليه'], $rows->take(60)->map(fn ($r) => [
                mb_substr($r['label'], 0, 46), $r['field'], $this->fmt($r['before']), $this->fmt($r['after']), mb_substr($r['why'], 0, 40),
            ])->all());
            if (count($rows) > 60) {
                $this->line('… و'.(count($rows) - 60).' صف كمان في ملف السجل');
            }
        }
    }

    private function writeFiles(): void
    {
        $ts = now()->format('Ymd-His');
        $mode = $this->apply ? 'apply' : 'dry';
        $payload = json_encode([
            'mode' => $mode, 'at' => now()->toIso8601String(), 'db' => DB::connection()->getDatabaseName(),
            'plan' => $this->option('plan'), 'rows' => $this->log,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $file = "price-sheet/changelog-{$ts}-{$mode}.json";
        Storage::put($file, $payload);
        $this->info("سجل التغييرات ({$mode}): storage/app/{$file} — ".count($this->log).' صف');
        if ($extra = $this->option('log')) {
            @file_put_contents((string) $extra, $payload);
            $this->info('نسخة: '.$extra);
        }
        if ($this->apply) {
            $snap = "price-sheet/snapshot-{$ts}.json";
            Storage::put($snap, json_encode($this->snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->info('snapshot للرجوع: storage/app/'.$snap);
        } else {
            $this->warn('dry-run: مفيش حاجة اتكتبت. شغّل بـ --apply للتنفيذ.');
        }
    }

    private function fmt(mixed $v): string
    {
        return match (true) {
            $v === null => '—', is_bool($v) => $v ? 'true' : 'false', default => (string) $v,
        };
    }

    private function pname(int $id): string
    {
        static $names = null;
        $names ??= Product::pluck('name_en', 'id')->all();

        return (string) ($names[$id] ?? '#'.$id);
    }

    private function rollback(string $file): int
    {
        $path = str_starts_with($file, 'storage/app/') ? substr($file, 12) : $file;
        $snap = json_decode((string) Storage::get($path), true);
        if (! is_array($snap)) {
            $this->error('snapshot مش موجود: '.$file);

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->warn(sprintf('dry-run للرجوع: %d بند، %d عميل، %d سلسلة، %d عقد، %d قايمة. شغّل بـ --apply.',
                count($snap['items'] ?? []), count($snap['clients'] ?? []), count($snap['groups'] ?? []), count($snap['contracts'] ?? []), count($snap['lists'] ?? [])));

            return self::SUCCESS;
        }
        DB::transaction(function () use ($snap) {
            foreach ($snap['lists'] ?? [] as $l) {
                if (isset($l['deleted'])) {
                    $row = PriceList::forceCreate(collect($l['deleted'])->except(['items'])->all());
                    foreach ($l['items'] as $it) {
                        PriceListItem::create(['price_list_id' => $row->id] + $it);
                    }
                } else {
                    PriceList::whereKey($l['id'])->update(collect($l)->except('id')->all());
                }
            }
            $byList = [];
            foreach ($snap['items'] ?? [] as $it) {
                if ($it['price'] === null) {
                    PriceListItem::where(['price_list_id' => $it['price_list_id'], 'product_id' => $it['product_id']])->delete();
                } else {
                    $byList[$it['price_list_id']][$it['product_id']] = $it['price'];
                }
            }
            foreach ($byList as $listId => $map) {
                PriceChange::writeListPrices(PriceList::findOrFail($listId), $map);
            }
            foreach (['clients' => Client::class, 'groups' => ClientGroup::class, 'contracts' => Contract::class] as $k => $m) {
                foreach ($snap[$k] ?? [] as $id => $f) {
                    $m::whereKey($id)->update($f);
                }
            }
        });
        PriceList::flushCache();
        $this->info('اترجع.');

        return self::SUCCESS;
    }
}
