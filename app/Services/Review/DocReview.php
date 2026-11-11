<?php

namespace App\Services\Review;

use App\Exceptions\Rejected;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\DocReviewEvent;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReviewCheck;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Pricing;
use App\Services\Reprice\InvoiceGl;
use App\Services\Reprice\InvoiceRepricer;
use App\Services\Reprice\PoRepricer;
use App\Services\Tax;
use App\Support\PeriodGuard;
use App\Support\Scope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * مراجعة المستندات مستند مستند — العلامة والتصليح في المكان (١١/١١)
 * ═══════════════════════════════════════════════════════════════
 *
 * قرارات أحمد (١٠/١١ — «موافق، ابدأ»):
 *   • تعديل البنود بيصلّح **المستند والفلوس بس** — المخزن والعهدة
 *     ماتتحرّكش، لأنهم اتصفّروا واتعدّوا من الورق يوم ٧/١١.
 *   • المحاسب والأدمن بيراجعوا ويعدّلوا؛ الأدمن بس بيفتح ✓ تاني؛
 *     **أي تعديل على مستند متعلّم ✓ بيرجّعه «لسه»**.
 *   • السيريال المكرر تحذير مش منع.
 *
 * التسعير مش هنا: بعد تعديل البنود المستند بيتسعّر من قايمة العميل
 * الصح بنفس الخدمات (`InvoiceRepricer` / `PoRepricer::planLines` /
 * `Tax::line`) — مفيش حسبة أسعار تانية في الملف ده.
 *
 * كل حركة = صف في `doc_review_events` (قبل/بعد + فرق الفلوس) + صف في
 * سجل النشاط، جوه نفس الترانزاكشن.
 */
class DocReview
{
    public const ITEM = 'doc';

    public const PHASE = 'docs';

    // ═══════════════════════ القراءة ═══════════════════════

    public static function find(string $type, int $id): Invoice|PurchaseOrder
    {
        return match ($type) {
            'invoice' => Invoice::findOrFail($id),
            'po' => PurchaseOrder::where('status', '!=', 'cancelled')->findOrFail($id),
            default => abort(404),
        };
    }

    public static function typeOf(Invoice|PurchaseOrder $doc): string
    {
        return $doc instanceof Invoice ? 'invoice' : 'po';
    }

    /** تاريخ المستند اللي بيتراجع على الورق: يوم الفاتورة / يوم تسليم الأمر */
    public static function dateOf(Invoice|PurchaseOrder $doc): ?Carbon
    {
        if ($doc instanceof Invoice) {
            return $doc->created_at;
        }

        return $doc->delivered_at ?? $doc->due_at ?? $doc->created_at;
    }

    public static function repOf(Invoice|PurchaseOrder $doc): ?int
    {
        return $doc instanceof Invoice ? $doc->user_id : $doc->assigned_to;
    }

    public static function check(Invoice|PurchaseOrder $doc): ?ReviewCheck
    {
        return ReviewCheck::with('user:id,name,name_en')->where([
            'entity_type' => self::typeOf($doc), 'entity_id' => $doc->id, 'item' => self::ITEM,
        ])->first();
    }

    /**
     * pending = محدش علّم · ok = ✓ والإجمالي زي ما اتراجع ·
     * changed = ✓ بس الإجمالي اتغيّر بعدها (مش محسوب خلصان) · issue = ✗
     */
    public static function state(?ReviewCheck $c, float $grand): string
    {
        if ($c === null) {
            return 'pending';
        }
        if ($c->status === ReviewCheck::ISSUE) {
            return 'issue';
        }
        if ($c->system_balance !== null && abs((float) $c->system_balance - $grand) >= 0.005) {
            return 'changed';
        }

        return 'ok';
    }

    /**
     * ليه التعديل مقفول — [مفتاح => رسالة]. `all` بيقفل المندوب والتاريخ
     * والبنود؛ `lines` بيقفل البنود بس. السيريال ميتاداتا: مابيتقفلش أبداً.
     *
     * @return array{all: array<string,string>, lines: array<string,string>}
     */
    public static function locks(Invoice|PurchaseOrder $doc): array
    {
        $all = [];
        $lines = [];

        if ($doc instanceof Invoice && in_array((string) $doc->eta_status, ['exported', 'submitted'], true)) {
            $all['eta'] = __('review.lock_eta');
        }
        if (PeriodGuard::isClosed(self::dateOf($doc))) {
            $all['period'] = __('review.lock_period');
        }

        if ($doc instanceof Invoice) {
            $b = InvoiceRepricer::blocker($doc);
            if ($b !== null && $b['key'] !== 'eta_locked') {
                $lines[$b['key']] = __(InvoiceRepricer::BLOCKER_MESSAGES[$b['key']]);
            }
        } else {
            if ($doc->status === 'arrived') {
                $lines['po_in_delivery'] = __('review.lock_po_in_delivery');
            }
            if ($doc->status === 'pending' && $doc->approval_status !== 'pending') {
                $lines['po_approved'] = __('review.lock_po_approved');
            }
        }

        return ['all' => $all, 'lines' => $lines];
    }

    /** لقطة المستند للسجل: البنود والإجماليات */
    public static function snapshot(Invoice|PurchaseOrder $doc): array
    {
        $doc->load('items.product');

        return [
            'lines' => $doc->items->map(fn ($l) => array_filter([
                'product_id' => $l->product_id,
                'name' => $l->product?->displayName(),
                'qty' => (int) $l->qty,
                'delivered_qty' => $doc instanceof PurchaseOrder && $l->delivered_qty !== null ? (int) $l->delivered_qty : null,
                'price' => (float) $l->price,
                'total' => (float) $l->total,
                'tax' => (float) $l->tax,
            ], fn ($v) => $v !== null))->values()->all(),
            'net' => (float) $doc->total,
            'tax' => (float) $doc->tax_total,
            'grand' => (float) $doc->grand_total,
        ];
    }

    /**
     * مستندات تانية بنفس السيريال الورقي (فواتير وأوامر).
     *
     * @return list<array{type: string, id: int, number: string}>
     */
    public static function duplicates(Invoice|PurchaseOrder $doc, ?string $serial = null): array
    {
        $serial = self::normSerial($serial ?? $doc->paper_ref);
        if ($serial === null) {
            return [];
        }
        $type = self::typeOf($doc);
        $out = [];

        // ⚠️ (١٢/١١) المقارنة على الشكل الموحّد مش بالـSQL `=`: «١٣٧٤» و«1374» و«13 74» نفس الورقة
        foreach (self::serialIndex()[$serial] ?? [] as $d) {
            if ($d['type'] === $type && $d['id'] === $doc->id) {
                continue;
            }
            $out[] = $d;
        }

        return $out;
    }

    /**
     * السيريال الورقي بشكل واحد: أرقام عربي/فارسي ← إنجليزي، من غير مسافات،
     * والحروف كابيتال. «١٣٧٤ » و«13 74» و«1374» = «1374». فاضي = null.
     */
    public static function normSerial(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        $s = mb_strtoupper((string) preg_replace('/\s+/u', '', $s));

        return $s === '' ? null : mb_substr($s, 0, 30);
    }

    /**
     * كل السيريالات (فواتير + أوامر مش ملغية) متجمعة بالشكل الموحّد.
     *
     * @return array<string, list<array{type: string, id: int, number: string}>>
     */
    public static function serialIndex(): array
    {
        $out = [];
        foreach (DB::table('invoices')->whereNotNull('paper_ref')->where('paper_ref', '!=', '')->get(['id', 'number', 'paper_ref']) as $d) {
            if (($k = self::normSerial($d->paper_ref)) !== null) {
                $out[$k][] = ['type' => 'invoice', 'id' => (int) $d->id, 'number' => (string) $d->number];
            }
        }
        foreach (DB::table('purchase_orders')->whereNotNull('paper_ref')->where('paper_ref', '!=', '')->where('status', '!=', 'cancelled')->get(['id', 'number', 'paper_ref']) as $d) {
            if (($k = self::normSerial($d->paper_ref)) !== null) {
                $out[$k][] = ['type' => 'po', 'id' => (int) $d->id, 'number' => (string) $d->number];
            }
        }

        return $out;
    }

    // ═══════════════════════ العلامة ═══════════════════════

    public static function mark(User $user, Invoice|PurchaseOrder $doc, string $status, ?string $note, ?int $openMs = null): ReviewCheck
    {
        $current = self::check($doc);
        $state = self::state($current, (float) $doc->grand_total);

        // ✓ اتراجع ولسه زي ما هو — مايتعلّمش تاني ولا يتقلب ✗ إلا بعد فتحه
        if ($state === 'ok') {
            throw new Rejected(__('review.doc_already_ok'));
        }
        if ($status === ReviewCheck::ISSUE && trim((string) $note) === '') {
            throw new Rejected(__('review.doc_issue_note'));
        }
        // ⚠️ (١٢/١١ — أحمد) السيريال الورقي شرط ✓ مش تحذير: مستند من غير ورقة
        // مايتعلّمش «مطابق للورق» حتى لو اتأكد مرتين. ✗ مسموح (ممكن الورقة نفسها ضايعة).
        if ($status === ReviewCheck::OK && self::normSerial($doc->paper_ref) === null) {
            throw new Rejected(__('review.serial_required'));
        }
        // (١٢/١١) إثبات الاستلام: تحذير بالافتراضي — شرط لو `review.po_proof_required`
        if ($status === ReviewCheck::OK && config('review.po_proof_required') && self::needsProof($doc) && ! self::hasProof($doc)) {
            throw new Rejected(__('review.proof_required'));
        }

        return DB::transaction(function () use ($user, $doc, $status, $note, $openMs, $current) {
            $check = ReviewCheck::updateOrCreate(
                ['entity_type' => self::typeOf($doc), 'entity_id' => $doc->id, 'item' => self::ITEM],
                [
                    'phase' => self::PHASE,
                    'status' => $status,
                    'note' => filled($note) ? trim($note) : null,
                    'system_net' => (float) $doc->total,
                    'system_balance' => (float) $doc->grand_total,
                    'user_id' => $user->id,
                ],
            );

            self::event($user, $doc, $status, $current ? ['status' => $current->status, 'grand' => (float) $current->system_balance] : null,
                ['status' => $status, 'grand' => (float) $doc->grand_total], 0, 0, $note, $openMs, clearsReview: false);

            return $check;
        });
    }

    /** فتح ✓ تاني — الأدمن بس (الراوت) */
    public static function reopen(User $user, Invoice|PurchaseOrder $doc, ?string $note): void
    {
        $current = self::check($doc);
        if ($current === null) {
            return;
        }

        DB::transaction(function () use ($user, $doc, $note, $current) {
            $before = ['status' => $current->status, 'by' => $current->user?->displayName(), 'at' => $current->updated_at?->toDateTimeString()];
            $current->delete();
            self::event($user, $doc, 'reopen', $before, ['status' => 'pending'], 0, 0, $note, null, clearsReview: false);
        });
    }

    // ═══════════════════════ التصليح ═══════════════════════

    /** @return list<array{type: string, id: int, number: string}> المكرر (تحذير) */
    public static function setSerial(User $user, Invoice|PurchaseOrder $doc, ?string $serial): array
    {
        $serial = self::normSerial($serial);
        $old = $doc->paper_ref;

        if ((string) self::normSerial($old) === (string) $serial && (string) $old === (string) $serial) {
            return self::duplicates($doc, $serial);
        }

        DB::transaction(function () use ($user, $doc, $serial, $old) {
            $doc->update(['paper_ref' => $serial]);
            self::event($user, $doc, 'serial', ['paper_ref' => $old], ['paper_ref' => $serial], 0, 0);
        });

        return self::duplicates($doc, $serial);
    }

    // ═══════════════════════ إثبات الاستلام (أوامر التوريد بس) ═══════════════════════

    /** الأمر اتسلّم فعلاً — قبل التسليم مفيش إثبات يتطلب */
    public static function needsProof(Invoice|PurchaseOrder $doc): bool
    {
        return $doc instanceof PurchaseOrder && $doc->status === 'delivered';
    }

    /** إذن استلام من العميل أو إمضاء/ختم على ورقتنا — أي واحد يكفي */
    public static function hasProof(PurchaseOrder|Invoice $doc): bool
    {
        return $doc instanceof PurchaseOrder && ($doc->receipt_note || $doc->paper_signed);
    }

    /**
     * علامات إثبات الاستلام. شيل الإثبات كله من أمر متعلّم ✓ بيرجّعه «لسه»
     * (اللي اتراجع على أساسه اتشال)؛ إضافة إثبات مابتلمسش ✓.
     */
    public static function setProof(User $user, Invoice|PurchaseOrder $doc, bool $receipt, ?string $receiptNo, bool $signed): void
    {
        if (! $doc instanceof PurchaseOrder) {
            throw new Rejected(__('review.proof_po_only'));
        }
        $receiptNo = self::normSerial($receiptNo);
        if ($receiptNo !== null) {
            $receipt = true;   // رقم إذن = فيه إذن
        }
        $old = ['receipt_note' => (bool) $doc->receipt_note, 'receipt_note_no' => $doc->receipt_note_no, 'paper_signed' => (bool) $doc->paper_signed];
        $new = ['receipt_note' => $receipt, 'receipt_note_no' => $receipt ? $receiptNo : null, 'paper_signed' => $signed];
        if ($old === $new) {
            return;
        }
        $hadProof = $old['receipt_note'] || $old['paper_signed'];
        $nowProof = $new['receipt_note'] || $new['paper_signed'];

        DB::transaction(function () use ($user, $doc, $old, $new, $hadProof, $nowProof) {
            $doc->update($new);
            self::event($user, $doc, 'proof', $old, $new, 0, 0, clearsReview: $hadProof && ! $nowProof);
        });
    }

    public static function setRep(User $user, Invoice|PurchaseOrder $doc, int $repId): void
    {
        self::assertUnlocked($doc);

        $rep = User::whereKey($repId)->whereIn('role', User::FIELD_WORK_ROLES)->where('active', true)->first();
        if ($rep === null) {
            throw new Rejected(__('review.rep_invalid'));
        }
        Scope::assertRep($user, $rep, $doc->client);

        $col = $doc instanceof Invoice ? 'user_id' : 'assigned_to';
        $old = $doc->{$col};
        if ((int) $old === $rep->id) {
            return;
        }

        DB::transaction(function () use ($user, $doc, $col, $old, $rep) {
            $doc->update([$col => $rep->id]);
            self::event($user, $doc, 'rep',
                ['rep_id' => $old, 'rep' => $old ? User::find($old)?->displayName() : null],
                ['rep_id' => $rep->id, 'rep' => $rep->displayName()], 0, 0);
        });
    }

    public static function setDate(User $user, Invoice|PurchaseOrder $doc, string $date): void
    {
        self::assertUnlocked($doc);

        $day = Carbon::parse($date)->startOfDay();
        $delivered = $doc instanceof Invoice || $doc->status === 'delivered';

        // اليوم اللي البيع اتقيّد فيه مايبقاش بكرة
        if ($delivered && $day->gt(today())) {
            throw new Rejected(__('review.date_future'));
        }
        PeriodGuard::assertOpen($day, 'date');

        $old = self::dateOf($doc);
        if ($old !== null && $old->toDateString() === $day->toDateString()) {
            return;
        }

        DB::transaction(function () use ($user, $doc, $day, $old, $delivered) {
            if ($doc instanceof Invoice) {
                // نفس `redateInvoice`: اليوم الجديد بساعة الفاتورة، والقيود معاه
                $new = $day->copy()->setTimeFrom($doc->created_at);
                $doc->forceFill(['created_at' => $new])->save();
                Transaction::where('source_type', Invoice::class)->where('source_id', $doc->id)
                    ->update(['date' => $new->toDateString()]);
                $doc->client?->recalculate();
                InvoiceGl::repost($doc, 'reviewRedate');
            } elseif ($delivered) {
                $new = $day->copy()->setTimeFrom($doc->delivered_at ?? now());
                $doc->forceFill(['delivered_at' => $new])->save();
                // صف صف عشان الـobserver يعيد ترحيل الشجرة
                foreach (Transaction::where('source_type', PurchaseOrder::class)->where('source_id', $doc->id)->get() as $tx) {
                    $tx->forceFill(['date' => $new->toDateString()])->save();
                }
                $doc->client?->recalculate();
            } else {
                $doc->update(['due_at' => $day->copy()->setTimeFrom($doc->due_at ?? now())]);
            }

            self::event($user, $doc, 'date', ['date' => $old?->toDateString()], ['date' => $day->toDateString()], 0, 0);
        });
    }

    /**
     * معاينة البنود الجديدة بأسعار قايمة العميل — من غير ولا كتابة.
     *
     * @param  array<int,int>  $qty  product_id => قطع
     * @return array{lines: list<array>, net: float, tax: float, grand: float, not_priced: ?string}
     */
    public static function previewLines(Invoice|PurchaseOrder $doc, array $qty): array
    {
        $client = Client::findOrFail($doc->client_id);
        $rows = [];
        $lines = [];

        foreach (self::cleanQty($qty) as $pid => $q) {
            $product = Product::find($pid);
            if ($product === null) {
                continue;
            }
            $price = $doc instanceof Invoice
                ? (float) Pricing::quote($client, $product, null, $q)['unit_price']
                : (float) $client->priceFor($product);
            if ($price <= 0) {
                return ['lines' => [], 'net' => 0, 'tax' => 0, 'grand' => 0, 'not_priced' => $product->displayName()];
            }
            $calc = Tax::line($price, $q, Tax::rate($client, $product));
            $lines[] = ['product_id' => $pid, 'name' => $product->displayName(), 'qty' => $q, 'price' => $price,
                'total' => $calc['net'], 'tax' => $calc['tax']];
            $rows[] = ['total' => $calc['net'], 'tax' => $calc['tax']];
        }

        $sums = Tax::totals($rows);

        return ['lines' => $lines, 'net' => (float) $sums['net'], 'tax' => (float) $sums['tax'], 'grand' => (float) $sums['grand'], 'not_priced' => null];
    }

    /**
     * حفظ البنود (إضافة/حذف/كمية) وإعادة التسعير من قايمة العميل.
     * ⚠️ المخزن والعهدة ماتتحرّكش — قرار أحمد ١٠/١١.
     *
     * @param  array<int,int>  $qty  product_id => قطع (الحذف = صفر أو غياب)
     */
    public static function saveLines(User $user, Invoice|PurchaseOrder $doc, array $qty, ?string $note = null): void
    {
        self::assertUnlocked($doc, lines: true);

        $target = self::cleanQty($qty);
        if ($target === []) {
            throw new Rejected(__('review.lines_empty'));
        }
        foreach (array_keys($target) as $pid) {
            $p = Product::find($pid);
            if ($p === null || $p->kind !== Product::KIND_FINISHED) {
                throw new Rejected(__('review.lines_bad_product'));
            }
        }

        $before = self::snapshot($doc);
        $current = collect($before['lines'])->groupBy('product_id')->map(fn ($g) => (int) $g->sum('qty'))->all();
        $same = count($current) === count($target) && collect($target)->every(fn ($q, $pid) => ($current[$pid] ?? 0) === $q);
        if ($same) {
            throw new Rejected(__('review.lines_no_change'));
        }

        DB::transaction(function () use ($user, $doc, $target, $before, $note) {
            $doc instanceof Invoice ? self::writeInvoiceLines($doc, $target) : self::writePoLines($doc, $target);
            $doc->refresh();
            $after = self::snapshot($doc);

            self::event($user, $doc, 'lines', $before, $after,
                round($after['net'] - $before['net'], 2), round($after['grand'] - $before['grand'], 2), $note,
                extra: ['stock_untouched' => true, 'after_reset' => self::dateOf($doc)?->gte(Carbon::parse(self::STOCK_RESET_DAY)) ?? false]);
        });
    }

    /** يوم تصفير المخزن والعهد وعدّهم من الورق — قبله البضاعة اتعدّت خلاص */
    public const STOCK_RESET_DAY = '2026-11-07';

    // ═══════════════════════ داخلي ═══════════════════════

    /** @return array<int,int> */
    private static function cleanQty(array $qty): array
    {
        $out = [];
        foreach ($qty as $pid => $q) {
            $q = (int) $q;
            if ((int) $pid > 0 && $q > 0) {
                $out[(int) $pid] = ($out[(int) $pid] ?? 0) + min($q, 999999);
            }
        }

        return $out;
    }

    private static function assertUnlocked(Invoice|PurchaseOrder $doc, bool $lines = false): void
    {
        $l = self::locks($doc);
        $msgs = $l['all'] + ($lines ? $l['lines'] : []);
        if ($msgs !== []) {
            throw new Rejected(implode(' · ', $msgs));
        }
    }

    /** الكميات بس — والتسعير كله من `InvoiceRepricer` (قايمة العميل الحالية) */
    private static function writeInvoiceLines(Invoice $inv, array $target): void
    {
        $inv->load('items.product');

        foreach ($target + array_fill_keys($inv->items->pluck('product_id')->all(), 0) as $pid => $want) {
            $want = $target[$pid] ?? 0;
            $lines = $inv->items->where('product_id', $pid)->sortByDesc('id');
            $have = (int) $lines->sum('qty');

            if ($want < $have) {
                $give = $have - $want;
                foreach ($lines as $line) {
                    if ($give <= 0) {
                        break;
                    }
                    $r = min((int) $line->qty, $give);
                    $r >= (int) $line->qty ? $line->delete() : $line->update(['qty' => (int) $line->qty - $r]);
                    $give -= $r;
                }
            } elseif ($want > $have) {
                if ($first = $lines->first()) {
                    $first->update(['qty' => (int) $first->qty + ($want - $have)]);
                } else {
                    $product = Product::findOrFail($pid);
                    InvoiceItem::create([
                        'invoice_id' => $inv->id, 'product_id' => $pid, 'batch_id' => null, 'qty' => $want,
                        'list_price' => 0, 'price' => 0, 'total' => 0, 'tax_rate' => 0, 'tax' => 0,
                        'unit_cost' => Pricing::costFor($product, null),
                    ]);
                }
            }
        }

        $inv->refresh();
        $plan = InvoiceRepricer::plan($inv);
        if ($plan['not_priced'] !== null) {
            throw new Rejected(__('ops.reprice_not_priced', ['product' => $plan['not_priced']]));
        }
        // الكاش: التحصيل المربوط بيمشي مع البيع (زي الزرار الفردي)
        InvoiceRepricer::apply($inv, $plan, adjustCollection: true, recalc: true);

        $inv->refresh()->load('items');
        $inv->update(['cost_total' => round($inv->items->sum(fn ($l) => round((float) $l->unit_cost * (int) $l->qty, 2)), 2)]);
    }

    /**
     * الكميات (والمسلَّم = الكمية على الأمر اللي اتسلّم — الورق هو المسلَّم)
     * وبعدين الأسعار من `PoRepricer::planLines` وقيد البيع لو اتسلّم.
     */
    private static function writePoLines(PurchaseOrder $po, array $target): void
    {
        $po->load(['items.product', 'client']);
        $delivered = $po->status === 'delivered';

        foreach ($target + array_fill_keys($po->items->pluck('product_id')->all(), 0) as $pid => $want) {
            $want = $target[$pid] ?? 0;
            $lines = $po->items->where('product_id', $pid)->sortByDesc('id');
            $have = (int) $lines->sum('qty');

            if ($want === $have) {
                continue;
            }
            // سطر واحد لكل صنف بعد التعديل — الباقي يتشال
            $keep = $lines->first();
            foreach ($lines->skip(1) as $extra) {
                $extra->delete();
            }
            if ($want === 0) {
                $keep?->delete();
            } elseif ($keep !== null) {
                $keep->update(['qty' => $want, 'delivered_qty' => $delivered ? $want : $keep->delivered_qty]);
            } else {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id, 'product_id' => $pid, 'qty' => $want,
                    'delivered_qty' => $delivered ? $want : null,
                    'price' => 0, 'list_price' => 0, 'discount_pct' => 0, 'total' => 0, 'tax_rate' => 0, 'tax' => 0,
                ]);
            }
        }

        $po->load('items.product');
        $client = $po->client;
        $plan = PoRepricer::planLines($client, $po->items);
        if ($plan['not_priced'] !== null) {
            throw new Rejected(__('stock.po_not_priced', ['name' => $plan['not_priced']]));
        }
        $byItem = collect($plan['lines'])->keyBy('item_id');

        foreach ($po->items as $it) {
            if ($l = $byItem[$it->id] ?? null) {
                $it->forceFill([
                    'list_price' => $l['list_price'], 'price' => $l['price'], 'discount_pct' => $l['discount_pct'],
                    'total' => $l['total'], 'tax_rate' => $l['tax_rate'], 'tax' => $l['tax'],
                ])->save();
            }
        }
        $po->forceFill([
            'total' => $plan['after']['net'], 'tax_total' => $plan['after']['tax'], 'grand_total' => $plan['after']['grand'],
            'price_mode' => $client->priceList(),
            'was_edited' => true, 'edited_by' => auth()->id(), 'edited_at' => now(),
        ])->save();

        if ($delivered) {
            // المسلَّم فعلاً — نفس قاعدة قيد التسليم (السطر الكامل بأرقامه، والجزئي بـTax::line)
            $net = $tax = 0.0;
            foreach ($po->fresh('items')->items as $it) {
                $dq = $it->delivered_qty === null ? (int) $it->qty : (int) $it->delivered_qty;
                if ($dq === (int) $it->qty) {
                    $net += (float) $it->total;
                    $tax += (float) $it->tax;
                } else {
                    $p = Tax::line((float) $it->price, $dq, (float) ($it->tax_rate ?? 0));
                    $net += $p['net'];
                    $tax += $p['tax'];
                }
            }
            $sale = Transaction::where('source_type', PurchaseOrder::class)->where('source_id', $po->id)->where('kind', 'sale')->first();
            $sale?->forceFill(['debit' => round(round($net, 2) + round($tax, 2), 2), 'tax' => round($tax, 2)])->save();
            $client->recalculate();
        }
    }

    /**
     * صف السجل + سجل النشاط. أي تعديل على مستند متعلّم ✓ بيرجّعه «لسه».
     */
    private static function event(User $user, Invoice|PurchaseOrder $doc, string $action, ?array $before, ?array $after,
        float $netDelta, float $grandDelta, ?string $note = null, ?int $openMs = null, bool $clearsReview = true, array $extra = []): DocReviewEvent
    {
        $type = self::typeOf($doc);

        if ($clearsReview) {
            $chk = ReviewCheck::where(['entity_type' => $type, 'entity_id' => $doc->id, 'item' => self::ITEM])->first();
            if ($chk !== null && $chk->status === ReviewCheck::OK) {
                $chk->delete();
                $extra['review_cleared'] = true;
            }
        }

        $seconds = null;
        if (in_array($action, DocReviewEvent::REVIEW_ACTIONS, true)) {
            $prev = DocReviewEvent::where('user_id', $user->id)->whereIn('action', DocReviewEvent::REVIEW_ACTIONS)
                ->latest('id')->value('created_at');
            if ($prev !== null) {
                $gap = (int) Carbon::parse($prev)->diffInSeconds(now(), true);
                $seconds = $gap <= DocReviewEvent::SESSION_GAP ? $gap : null;
            }
        }

        $ev = DocReviewEvent::create([
            'user_id' => $user->id, 'doc_type' => $type, 'doc_id' => $doc->id, 'action' => $action,
            'before' => $before, 'after' => $after === null ? null : $after + $extra,
            'net_delta' => $netDelta, 'grand_delta' => $grandDelta,
            'seconds_since_prev' => $seconds, 'open_ms' => $openMs !== null ? max(0, min($openMs, 86_400_000)) : null,
            'note' => filled($note) ? mb_substr(trim($note), 0, 500) : null,
        ]);

        // ⚠️ (١٢/١١) سطر مقروء في سجل النشاط: اسم الموديل القصير (عشان اللينك
        // واسم «فاتورة» يطلعوا صح)، العنوان فيه رقم المستند والعميل، والتفاصيل
        // «حقل: قبل ← بعد» بالعربي — مش JSON متداخل.
        $doc->loadMissing('client');
        $title = __('review.ev_'.$action, ['number' => $doc->number])
            .($doc->client ? ' · '.trim($doc->client->code.' '.$doc->client->displayName()) : '')
            .($grandDelta != 0.0 ? ' ('.number_format((float) ($before['grand'] ?? 0), 2).' → '.number_format((float) ($after['grand'] ?? 0), 2).')' : '');

        ActivityLog::record('action', [
            'subject_type' => class_basename($doc),
            'subject_id' => $doc->id,
            'title' => mb_substr($title, 0, 190),
            'changes' => self::logChanges($action, $before, $after === null ? null : $after + $extra, $note, $grandDelta),
        ]);

        return $ev;
    }

    /**
     * تفاصيل سطر السجل: «الحقل بالعربي ⇒ [قبل، بعد]» — نفس شكل التعديلات
     * العادية في مركز النشاط، فبيتقري من غير ما حد يفك JSON.
     *
     * @return array<string, mixed>
     */
    private static function logChanges(string $action, ?array $before, ?array $after, ?string $note, float $grandDelta): array
    {
        $b = $before ?? [];
        $a = $after ?? [];
        $st = fn ($s) => $s === null ? null : __('review.st_'.$s);
        $out = [];

        switch ($action) {
            case 'ok': case 'issue': case 'reopen':
                $out[__('review.log_state')] = [$st($b['status'] ?? 'pending'), $st($a['status'] ?? 'pending')];
                if (isset($a['grand'])) {
                    $out[__('review.tot_incl')] = number_format((float) $a['grand'], 2);
                }
                break;
            case 'serial':
                $out[__('review.serial')] = [$b['paper_ref'] ?? null, $a['paper_ref'] ?? null];
                break;
            case 'rep':
                $out[__('review.doc_rep')] = [$b['rep'] ?? null, $a['rep'] ?? null];
                break;
            case 'proof':
                $yn = fn ($v) => $v ? __('review.yes') : __('review.no');
                $out[__('review.proof_receipt')] = [$yn($b['receipt_note'] ?? false), $yn($a['receipt_note'] ?? false)];
                if (($b['receipt_note_no'] ?? null) !== ($a['receipt_note_no'] ?? null)) {
                    $out[__('review.proof_receipt_no')] = [$b['receipt_note_no'] ?? null, $a['receipt_note_no'] ?? null];
                }
                $out[__('review.proof_signed')] = [$yn($b['paper_signed'] ?? false), $yn($a['paper_signed'] ?? false)];
                break;
            case 'date':
                $out[__('review.doc_date')] = [$b['date'] ?? null, $a['date'] ?? null];
                break;
            case 'lines':
                $out[__('review.tot_net')] = [number_format((float) ($b['net'] ?? 0), 2), number_format((float) ($a['net'] ?? 0), 2)];
                $out[__('review.tot_incl')] = [number_format((float) ($b['grand'] ?? 0), 2), number_format((float) ($a['grand'] ?? 0), 2)];
                $q = fn (array $lines) => collect($lines)->groupBy('product_id')
                    ->map(fn ($g) => ['name' => $g->first()['name'] ?? '#'.$g->first()['product_id'], 'qty' => (int) $g->sum(fn ($l) => $l['delivered_qty'] ?? $l['qty'])]);
                $qb = $q($b['lines'] ?? []);
                $qa = $q($a['lines'] ?? []);
                foreach ($qb->keys()->merge($qa->keys())->unique()->take(12) as $pid) {
                    $x = $qb[$pid]['qty'] ?? 0;
                    $y = $qa[$pid]['qty'] ?? 0;
                    if ($x !== $y) {
                        $out[mb_substr($qa[$pid]['name'] ?? $qb[$pid]['name'], 0, 60)] = [$x, $y];
                    }
                }
                if (! empty($a['stock_untouched'])) {
                    $out[__('review.log_stock')] = __('review.log_stock_untouched');
                }
                break;
        }

        if (! empty($a['review_cleared'])) {
            $out[__('review.log_cleared')] = __('review.log_cleared_yes');
        }
        if (filled($note)) {
            $out[__('review.log_note')] = mb_substr(trim($note), 0, 120);
        }

        return $out;
    }
}
