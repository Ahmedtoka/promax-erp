<?php

namespace App\Services\Review;

use App\Exceptions\Rejected;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\DocReviewEvent;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReturnItem;
use App\Models\ReviewCheck;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Pricing;
use App\Services\Reprice\FullResync;
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

    public static function find(string $type, int $id): Invoice|PurchaseOrder|ClientReturn
    {
        return match ($type) {
            'invoice' => Invoice::findOrFail($id),
            'po' => PurchaseOrder::where('status', '!=', 'cancelled')->findOrFail($id),
            'return' => ClientReturn::findOrFail($id),
            default => abort(404),
        };
    }

    public static function typeOf(Invoice|PurchaseOrder|ClientReturn $doc): string
    {
        return $doc instanceof Invoice ? 'invoice' : ($doc instanceof ClientReturn ? 'return' : 'po');
    }

    /** مفتاح الصف في الطابور: inv-12 / po-7 / ret-3 */
    public static function keyOf(Invoice|PurchaseOrder|ClientReturn $doc): string
    {
        return self::keyFor(self::typeOf($doc), (int) $doc->id);
    }

    public static function keyFor(string $type, int $id): string
    {
        return ['invoice' => 'inv-', 'po' => 'po-', 'return' => 'ret-'][$type].$id;
    }

    /** «inv-12» ← ['invoice', 12] */
    public static function parseKey(string $key): array
    {
        $type = match (true) {
            str_starts_with($key, 'inv-') => 'invoice',
            str_starts_with($key, 'ret-') => 'return',
            default => 'po',
        };

        return [$type, (int) substr($key, strpos($key, '-') + 1)];
    }

    /**
     * دفتر السيريال: الفواتير والأوامر دفتر واحد، وأذون المرتجع دفتر لوحده —
     * «١٢» على إذن مرتجع مش مكرر مع فاتورة «١٢».
     */
    public static function serialKey(string $type, ?string $serial): ?string
    {
        return $serial === null ? null : ($type === 'return' ? 'R:'.$serial : $serial);
    }

    /** تاريخ المستند اللي بيتراجع على الورق: يوم الفاتورة / يوم تسليم الأمر */
    public static function dateOf(Invoice|PurchaseOrder|ClientReturn $doc): ?Carbon
    {
        if ($doc instanceof Invoice || $doc instanceof ClientReturn) {
            return $doc->created_at;
        }

        return $doc->delivered_at ?? $doc->due_at ?? $doc->created_at;
    }

    public static function repOf(Invoice|PurchaseOrder|ClientReturn $doc): ?int
    {
        return $doc instanceof PurchaseOrder ? $doc->assigned_to : $doc->user_id;
    }

    public static function check(Invoice|PurchaseOrder|ClientReturn $doc): ?ReviewCheck
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
    public static function locks(Invoice|PurchaseOrder|ClientReturn $doc): array
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
        } elseif ($doc instanceof PurchaseOrder) {
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
    public static function snapshot(Invoice|PurchaseOrder|ClientReturn $doc): array
    {
        $doc->load('items.product');

        return [
            'lines' => $doc->items->map(fn ($l) => array_filter([
                'product_id' => $l->product_id,
                'name' => $l->product?->displayName(),
                'qty' => (int) $l->qty,
                'delivered_qty' => $doc instanceof PurchaseOrder && $l->delivered_qty !== null ? (int) $l->delivered_qty : null,
                'condition' => $doc instanceof ClientReturn ? $l->condition : null,
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
    public static function duplicates(Invoice|PurchaseOrder|ClientReturn $doc, ?string $serial = null): array
    {
        $serial = self::normSerial($serial ?? $doc->paper_ref);
        if ($serial === null) {
            return [];
        }
        $type = self::typeOf($doc);
        $out = [];

        // ⚠️ (١٢/١١) المقارنة على الشكل الموحّد مش بالـSQL `=`: «١٣٧٤» و«1374» و«13 74» نفس الورقة
        foreach (self::serialIndex()[self::serialKey($type, $serial)] ?? [] as $d) {
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
        // أذون المرتجع — دفتر لوحده (`serialKey`)
        foreach (DB::table('returns')->whereNotNull('paper_ref')->where('paper_ref', '!=', '')->get(['id', 'number', 'paper_ref']) as $d) {
            if (($k = self::serialKey('return', self::normSerial($d->paper_ref))) !== null) {
                $out[$k][] = ['type' => 'return', 'id' => (int) $d->id, 'number' => (string) $d->number];
            }
        }

        return $out;
    }

    // ═══════════════════════ العلامة ═══════════════════════

    public static function mark(User $user, Invoice|PurchaseOrder|ClientReturn $doc, string $status, ?string $note, ?int $openMs = null): ReviewCheck
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
        if ($status === ReviewCheck::OK && self::proofHard($doc) && self::needsProof($doc) && ! self::hasProof($doc)) {
            throw new Rejected(__($doc instanceof ClientReturn ? 'review.ret_proof_required' : 'review.proof_required'));
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

    /**
     * (١٣/١١) المراجِع يقدر يتراجع عن علامته هو (✓ أو ✗) في نفس اليوم — دوسة غلط
     * مش لازم تستنى الأدمن. الأقدم من كده أو علامة حد تاني = الأدمن بيفتحها.
     */
    public static function canUndo(User $user, ?ReviewCheck $check): bool
    {
        return $check !== null && (int) $check->user_id === (int) $user->id
            && $check->updated_at !== null && $check->updated_at->isSameDay(now());
    }

    public static function undo(User $user, Invoice|PurchaseOrder|ClientReturn $doc): void
    {
        $current = self::check($doc);
        if (! self::canUndo($user, $current)) {
            throw new Rejected(__('review.undo_denied'));
        }

        DB::transaction(function () use ($user, $doc, $current) {
            $before = ['status' => $current->status, 'by' => $current->user?->displayName(), 'at' => $current->updated_at?->toDateTimeString()];
            $current->delete();
            self::event($user, $doc, 'undo', $before, ['status' => 'pending'], 0, 0, null, null, clearsReview: false);
        });
    }

    /** فتح ✓ تاني — الأدمن بس (الراوت) */
    public static function reopen(User $user, Invoice|PurchaseOrder|ClientReturn $doc, ?string $note): void
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
    public static function setSerial(User $user, Invoice|PurchaseOrder|ClientReturn $doc, ?string $serial): array
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

    /**
     * الأمر اتسلّم فعلاً — قبل التسليم مفيش إثبات يتطلب. المرتجع دايماً: البضاعة
     * رجعت والدائن اتقيّد، فإمضاء/ختم العميل على الإذن هو اللي يثبت إنها رجعت.
     */
    public static function needsProof(Invoice|PurchaseOrder|ClientReturn $doc): bool
    {
        // (١٣/١١) الفاتورة بضاعتها خرجت يوم البيع — إثباتها مطلوب دايماً زي الأمر المتسلّم
        return $doc instanceof Invoice || ($doc instanceof PurchaseOrder && $doc->status === 'delivered') || $doc instanceof ClientReturn;
    }

    /** إذن استلام من العميل أو إمضاء/ختم على ورقتنا — أي واحد يكفي. المرتجع: إمضاء/ختم العميل */
    public static function hasProof(Invoice|PurchaseOrder|ClientReturn $doc): bool
    {
        if ($doc instanceof ClientReturn) {
            return (bool) $doc->paper_signed;
        }

        return (bool) ($doc->receipt_note || $doc->paper_signed);
    }

    /** الإثبات شرط ✓ ولا تحذير — مفتاح لكل نوع في `config/review.php` */
    public static function proofHard(Invoice|PurchaseOrder|ClientReturn $doc): bool
    {
        return match (true) {
            $doc instanceof PurchaseOrder => (bool) config('review.po_proof_required'),
            $doc instanceof ClientReturn => (bool) config('review.return_proof_required'),
            $doc instanceof Invoice => (bool) config('review.invoice_proof_required'),
            default => false,
        };
    }

    /**
     * علامات إثبات الاستلام. شيل الإثبات كله من أمر متعلّم ✓ بيرجّعه «لسه»
     * (اللي اتراجع على أساسه اتشال)؛ إضافة إثبات مابتلمسش ✓.
     */
    public static function setProof(User $user, Invoice|PurchaseOrder|ClientReturn $doc, bool $receipt, ?string $receiptNo, bool $signed): void
    {
        // المرتجع: إمضاء/ختم العميل بس — مفيش «إذن استلام» من العميل على بضاعة راجعة
        if ($doc instanceof ClientReturn) {
            $old = ['paper_signed' => (bool) $doc->paper_signed];
            $new = ['paper_signed' => $signed];
            if ($old === $new) {
                return;
            }
            DB::transaction(function () use ($user, $doc, $old, $new) {
                $doc->update($new);
                self::event($user, $doc, 'proof', $old, $new, 0, 0, clearsReview: $old['paper_signed'] && ! $new['paper_signed']);
            });

            return;
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

    public static function setRep(User $user, Invoice|PurchaseOrder|ClientReturn $doc, int $repId): void
    {
        self::assertUnlocked($doc);

        $rep = User::whereKey($repId)->whereIn('role', User::FIELD_WORK_ROLES)->where('active', true)->first();
        if ($rep === null) {
            throw new Rejected(__('review.rep_invalid'));
        }
        Scope::assertRep($user, $rep, $doc->client);

        $col = $doc instanceof PurchaseOrder ? 'assigned_to' : 'user_id';
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

    public static function setDate(User $user, Invoice|PurchaseOrder|ClientReturn $doc, string $date): void
    {
        self::assertUnlocked($doc);

        $day = Carbon::parse($date)->startOfDay();
        $delivered = ! $doc instanceof PurchaseOrder || $doc->status === 'delivered';

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
            } elseif ($doc instanceof ClientReturn) {
                // المرتجع: نفس الفاتورة — اليوم الجديد بساعته، والدائن (والرد الكاش) معاه
                $new = $day->copy()->setTimeFrom($doc->created_at);
                $doc->forceFill(['created_at' => $new])->save();
                foreach (self::returnTxs($doc) as $tx) {
                    $tx->forceFill(['date' => $new->toDateString()])->save();
                }
                $doc->client?->recalculate();
                self::returnGl($doc);
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
    public static function previewLines(Invoice|PurchaseOrder|ClientReturn $doc, array $qty): array
    {
        $client = Client::findOrFail($doc->client_id);
        $rows = [];
        $lines = [];

        foreach (self::cleanQty($qty) as $pid => $q) {
            $product = Product::find($pid);
            if ($product === null) {
                continue;
            }
            if ($doc instanceof ClientReturn) {
                [$price, $rate] = self::returnPrice($doc, $client, $product);
            } else {
                $price = $doc instanceof Invoice
                    ? (float) Pricing::quote($client, $product, null, $q)['unit_price']
                    : (float) $client->priceFor($product);
                $rate = Tax::rate($client, $product);
            }
            if ($price <= 0) {
                return ['lines' => [], 'net' => 0, 'tax' => 0, 'grand' => 0, 'not_priced' => $product->displayName()];
            }
            $calc = Tax::line($price, $q, $rate);
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
    public static function saveLines(User $user, Invoice|PurchaseOrder|ClientReturn $doc, array $qty, ?string $note = null): void
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
            match (true) {
                $doc instanceof Invoice => self::writeInvoiceLines($doc, $target),
                $doc instanceof ClientReturn => self::writeReturnLines($doc, $target),
                default => self::writePoLines($doc, $target),
            };
            $doc->refresh();
            $after = self::snapshot($doc);

            self::event($user, $doc, 'lines', $before, $after,
                round($after['net'] - $before['net'], 2), round($after['grand'] - $before['grand'], 2), $note,
                extra: ['stock_untouched' => true, 'after_reset' => self::dateOf($doc)?->gte(Carbon::parse(self::STOCK_RESET_DAY)) ?? false]);
        });
    }

    /** يوم تصفير المخزن والعهد وعدّهم من الورق — قبله البضاعة اتعدّت خلاص */
    public const STOCK_RESET_DAY = '2026-11-07';

    // ═══════════════════════ أدوات حل مشاكل المراجعة (١٣/١١ — ملاحظات سهيلة) ═══════════════════════

    /**
     * قيمة المسلَّم فعلاً لأمر التوريد — **نفس قاعدة قيد التسليم بالظبط**
     * (`FieldApiController::deliver`): السطر الكامل بأرقامه المخزنة، والجزئي
     * `Tax::line(price, dq)`. قبل التسليم = المطلوب (ده اللي على الورقة).
     *
     * @return array{net: float, tax: float, grand: float}
     */
    public static function poDelivered(PurchaseOrder $po, ?iterable $items = null): array
    {
        $items ??= $po->relationLoaded('items') ? $po->items : $po->items()->get();
        if ($po->status !== 'delivered') {
            return ['net' => (float) $po->total, 'tax' => (float) $po->tax_total, 'grand' => (float) $po->grand_total];
        }

        return self::deliveredFromItems($items);
    }

    /** @return array{net: float, tax: float, grand: float} */
    public static function deliveredFromItems(iterable $items): array
    {
        $net = $tax = 0.0;
        foreach ($items as $it) {
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

        return ['net' => round($net, 2), 'tax' => round($tax, 2), 'grand' => round(round($net, 2) + round($tax, 2), 2)];
    }

    /**
     * (١٣/١١) «اتسلّم ٢٤ ومحسوب ٣٦» — تعديل **المسلَّم** لكل بند في أمر اتسلّم،
     * من غير ما المطلوب يتغيّر. الأسعار من قايمة العميل (`PoRepricer`) وقيد
     * البيع = المسلَّم بنفس قاعدة التسليم. ⚠️ العهدة ماتتحرّكش (قرار ١٠/١١).
     *
     * @param  array<int,int>  $dq  item_id => المسلَّم (صفر = متسلّمش)
     */
    public static function saveDelivered(User $user, PurchaseOrder $po, array $dq, ?string $note = null): void
    {
        if ($po->status !== 'delivered') {
            throw new Rejected(__('review.dlv_not_delivered'));
        }
        self::assertUnlocked($po);
        $po->load('items.product');

        $target = [];
        foreach ($po->items as $it) {
            $want = array_key_exists($it->id, $dq) ? (int) $dq[$it->id] : ($it->delivered_qty ?? (int) $it->qty);
            if ($want < 0 || $want > (int) $it->qty) {
                throw new Rejected(__('review.dlv_over', ['name' => $it->product?->displayName() ?? '#'.$it->product_id, 'max' => (int) $it->qty]));
            }
            $target[$it->id] = (int) $want;
        }
        if (array_sum($target) === 0) {
            throw new Rejected(__('review.dlv_all_zero'));
        }
        if ($po->items->every(fn ($it) => $target[$it->id] === ($it->delivered_qty ?? (int) $it->qty))) {
            throw new Rejected(__('review.lines_no_change'));
        }

        DB::transaction(function () use ($user, $po, $target, $note) {
            $before = self::snapshot($po) + ['delivered' => self::poDelivered($po)];
            foreach ($po->items as $it) {
                $it->forceFill(['delivered_qty' => $target[$it->id]])->save();
            }
            self::repricePo($po->fresh(['items.product', 'client']));
            $po->refresh();
            $after = self::snapshot($po) + ['delivered' => self::poDelivered($po)];

            self::event($user, $po, 'delivered', ['grand' => $before['delivered']['grand'], 'lines' => $before['lines']],
                ['grand' => $after['delivered']['grand'], 'lines' => $after['lines']],
                round($after['delivered']['net'] - $before['delivered']['net'], 2), round($after['delivered']['grand'] - $before['delivered']['grand'], 2), $note,
                extra: ['stock_untouched' => true]);
        });
    }

    /**
     * (١٣/١١) «المستند مكرر هو نفس رقم …» — إلغاء النسخة المكررة بالكامل:
     * قيودها صف صف (الشجرة بتتشال مع كل صف)، رصيد العميل، والعهدة لو المستند
     * بعد تصفير ٧/١١ (قبله البضاعة اتعدّت من الورق). السجل بيحفظ لقطة كاملة
     * والأصل. أدمن بس (الراوت).
     */
    public static function voidDuplicate(User $user, Invoice|ClientReturn $doc, string $originalNumber, ?string $note = null): Invoice|ClientReturn
    {
        $orig = ($doc instanceof Invoice ? Invoice::query() : ClientReturn::query())->where('number', trim($originalNumber))->first();
        if ($orig === null || $orig->id === $doc->id) {
            throw new Rejected(__('review.dup_orig_missing'));
        }
        if ((int) $orig->client_id !== (int) $doc->client_id) {
            throw new Rejected(__('review.dup_orig_other_client', ['number' => $orig->number]));
        }
        self::assertUnlocked($doc);
        if ($doc instanceof Invoice && DB::table('return_items')->where('invoice_id', $doc->id)->exists()) {
            throw new Rejected(__('ops.del_inv_has_returns'));
        }
        $txs = $doc instanceof Invoice
            ? Transaction::where('source_type', Invoice::class)->where('source_id', $doc->id)->get()
            : self::returnTxs($doc);
        foreach ($txs as $tx) {
            PeriodGuard::assertOpen($tx->date, 'delete');
        }

        DB::transaction(function () use ($user, $doc, $orig, $note, $txs) {
            $before = self::snapshot($doc) + ['ledger' => $txs->map(fn ($t) => ['id' => $t->id, 'kind' => $t->kind, 'date' => (string) $t->date,
                'debit' => (float) $t->debit, 'credit' => (float) $t->credit])->values()->all()];
            $custody = self::dateOf($doc)?->gte(Carbon::parse(self::STOCK_RESET_DAY)) ?? false;
            $extra = ['duplicate_of' => self::keyOf($orig), 'original' => $orig->number, 'custody_reversed' => $custody];

            self::event($user, $doc, 'void_dup', $before, ['grand' => 0.0], -(float) $doc->total, -(float) $doc->grand_total, $note, extra: $extra);
            self::event($user, $orig, 'dup_voided', null, ['duplicate' => $doc->number, 'grand' => (float) $orig->grand_total], 0, 0, $note, clearsReview: false);

            if ($custody) {
                $doc instanceof Invoice ? self::custodyBackForInvoice($doc) : self::custodyBackForReturn($doc);
            }

            foreach ($txs as $tx) {
                $tx->delete();   // صف صف — TransactionObserver بيشيل قيد الشجرة
            }
            ReviewCheck::where(['entity_type' => self::typeOf($doc), 'entity_id' => $doc->id])->delete();
            $doc->items()->delete();
            $doc->delete();
            Client::find($doc->client_id)?->recalculate();
        });

        return $orig;
    }

    /**
     * (١٣/١١) تصحيح سعر بند المرتجع — «الأسعار غلط ومفيش تعديل». قبل كده
     * السعر كان محسوب دايماً (سطر الفاتورة اللي رجع منه، وإلا قايمة النهارده)
     * ومفيش ولا طريقة تغيّره. دلوقتي:
     *   `invoice` = اربط البند بسطر فاتورة للعميل نفسه بنفس الصنف ← سعره
     *   `manual`  = سعر يدوي (غير شامل) + سبب إجباري — بيعيش بعد أي إعادة تسعير
     *   `list`    = شيل الربط واليدوي ← قايمة العميل
     * والدائن والرد الكاش ورصيد العميل والشجرة بيمشوا وراه.
     */
    public static function setReturnPrice(User $user, ClientReturn $ret, int $itemId, string $mode, ?int $invoiceItemId = null, ?float $price = null, ?string $reason = null): void
    {
        self::assertUnlocked($ret);
        $line = ReturnItem::where('return_id', $ret->id)->whereKey($itemId)->first();
        if ($line === null) {
            throw new Rejected(__('review.retp_bad_line'));
        }

        $set = match ($mode) {
            'invoice' => (function () use ($ret, $line, $invoiceItemId) {
                $src = InvoiceItem::with('invoice')->find($invoiceItemId);
                if ($src === null || $src->invoice === null || (int) $src->invoice->client_id !== (int) $ret->client_id || (int) $src->product_id !== (int) $line->product_id) {
                    throw new Rejected(__('review.retp_bad_invoice'));
                }
                $taken = (int) ReturnItem::where('invoice_item_id', $src->id)->where('id', '!=', $line->id)->sum('qty');
                if ($taken + (int) $line->qty > (int) $src->qty) {
                    throw new Rejected(__('review.retp_over_sold', ['number' => $src->invoice->number, 'sold' => (int) $src->qty, 'taken' => $taken]));
                }

                return ['invoice_id' => $src->invoice_id, 'invoice_item_id' => $src->id, 'manual_price' => null, 'manual_reason' => null];
            })(),
            'manual' => (function () use ($price, $reason) {
                if ($price === null || $price <= 0 || $price > 100000) {
                    throw new Rejected(__('review.retp_bad_price'));
                }
                if (mb_strlen(trim((string) $reason)) < 3) {
                    throw new Rejected(__('review.retp_reason'));
                }

                return ['manual_price' => round($price, 4), 'manual_reason' => mb_substr(trim($reason), 0, 190)];
            })(),
            'list' => ['invoice_id' => null, 'invoice_item_id' => null, 'manual_price' => null, 'manual_reason' => null],
            default => throw new Rejected(__('review.retp_bad_line')),
        };

        DB::transaction(function () use ($user, $ret, $line, $set, $reason, $mode) {
            $before = self::snapshot($ret);
            $line->forceFill($set)->save();
            $ret->refresh();
            $res = FullResync::clientReturn($ret);
            if (isset($res['skip'])) {
                throw new Rejected(__('review.ret_not_priced', ['why' => $res['skip']]));
            }
            $ret->refresh();
            self::syncReturnTxs($ret);
            $after = self::snapshot($ret);
            $line->refresh()->load('product');

            self::event($user, $ret, 'ret_price', $before, $after + ['mode' => $mode, 'product' => $line->product?->displayName(), 'price' => (float) $line->price],
                round($after['net'] - $before['net'], 2), round($after['grand'] - $before['grand'], 2), $mode === 'manual' ? $reason : null);
        });
    }

    /**
     * سطور فواتير العميل اللي ممكن بند المرتجع يترجع منها — للاختيار في اللوحة.
     *
     * @return array<int, list<array{id:int, number:string, date:string, qty:int, price:float, left:int}>>  product_id => سطور
     */
    public static function returnSources(ClientReturn $ret): array
    {
        $pids = $ret->items->pluck('product_id')->unique()->all();
        $taken = ReturnItem::whereNotNull('invoice_item_id')->selectRaw('invoice_item_id, SUM(qty) q')->groupBy('invoice_item_id')->pluck('q', 'invoice_item_id');
        $out = [];
        foreach (DB::table('invoice_items as ii')->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->where('i.client_id', $ret->client_id)->whereIn('ii.product_id', $pids)
            ->orderByDesc('i.created_at')->limit(300)
            ->get(['ii.id', 'ii.product_id', 'ii.qty', 'ii.price', 'i.number', 'i.created_at']) as $r) {
            $out[(int) $r->product_id][] = ['id' => (int) $r->id, 'number' => (string) $r->number, 'date' => substr((string) $r->created_at, 0, 10),
                'qty' => (int) $r->qty, 'price' => (float) $r->price, 'left' => max(0, (int) $r->qty - (int) ($taken[$r->id] ?? 0))];
        }

        return $out;
    }

    // ═══════════════════════ داخلي ═══════════════════════

    /** أسعار بنود الأمر من قايمة العميل + إجماليه + قيد البيع (بالمسلَّم) لو اتسلّم */
    private static function repricePo(PurchaseOrder $po): void
    {
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

        if ($po->status === 'delivered') {
            // المسلَّم فعلاً — نفس قاعدة قيد التسليم (السطر الكامل بأرقامه، والجزئي بـTax::line)
            $d = self::poDelivered($po, $po->fresh('items')->items);
            $sale = Transaction::where('source_type', PurchaseOrder::class)->where('source_id', $po->id)->where('kind', 'sale')->first();
            $sale?->forceFill(['debit' => $d['grand'], 'tax' => $d['tax']])->save();
            $client->recalculate();
        }
    }

    /** الدائن والرد الكاش = إجمالي المرتجع (صف صف عشان أوبزرفر الدفتر) + الرصيد + الشجرة */
    private static function syncReturnTxs(ClientReturn $ret): void
    {
        foreach (self::returnTxs($ret) as $tx) {
            $col = $tx->kind === 'refund' ? 'debit' : 'credit';
            if (in_array($tx->kind, ['return', 'refund'], true)) {
                $tx->forceFill([$col => (float) $ret->grand_total, 'tax' => (float) $ret->tax_total])->save();
            }
        }
        $ret->client?->recalculate();
        self::returnGl($ret);
    }

    /** نفس `OpsController::destroyInvoice`: البضاعة ترجع لعهدة البايع الحالية سطر بسطر */
    private static function custodyBackForInvoice(Invoice $inv): void
    {
        $custody = $inv->user?->currentCustody();
        foreach ($inv->items()->lockForUpdate()->get() as $it) {
            $row = $custody === null ? null : (\App\Models\CustodyItem::where('custody_id', $custody->id)->where('product_id', $it->product_id)
                ->when($it->batch_id !== null, fn ($w) => $w->where('batch_id', $it->batch_id))
                ->where('sold', '>=', (int) $it->qty)->lockForUpdate()->first()
                ?? \App\Models\CustodyItem::where('custody_id', $custody->id)->where('product_id', $it->product_id)
                    ->where('sold', '>=', (int) $it->qty)->lockForUpdate()->first());
            if ($row === null) {
                throw new Rejected(__('ops.del_inv_no_custody'));
            }
            $row->decrement('sold', (int) $it->qty);
        }
    }

    /** نفس `ReturnController::destroy`: البضاعة الراجعة تطلع من عهدة المرتجع */
    private static function custodyBackForReturn(ClientReturn $ret): void
    {
        $custody = $ret->custody_id !== null ? \App\Models\Custody::find($ret->custody_id) : null;
        if ($custody === null) {
            return;   // مرتجع مكتبي مادخلش عهدة
        }
        if ((string) $custody->status === 'closed') {
            throw new Rejected(__('ops.del_ret_custody_closed'));
        }
        $by = [];
        foreach ($ret->items as $it) {
            $by[(int) $it->product_id][$it->condition] = ($by[(int) $it->product_id][$it->condition] ?? 0) + (int) $it->qty;
        }
        foreach ($by as $pid => $cond) {
            $row = \App\Models\CustodyItem::where(['custody_id' => $custody->id, 'product_id' => $pid, 'batch_id' => null, 'source' => 'custody', 'source_ref_id' => 0])
                ->lockForUpdate()->first();
            $good = (int) ($cond[ClientReturn::CONDITION_GOOD] ?? 0);
            $dmg = (int) ($cond[ClientReturn::CONDITION_DAMAGED] ?? 0);
            if ($row === null || (int) $row->returned_in < $good || (int) $row->damaged_in < $dmg) {
                throw new Rejected(__('ops.del_ret_moved'));
            }
            $good > 0 && $row->decrement('returned_in', $good);
            $dmg > 0 && $row->decrement('damaged_in', $dmg);
        }
    }


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

    private static function assertUnlocked(Invoice|PurchaseOrder|ClientReturn $doc, bool $lines = false): void
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

        self::repricePo($po->fresh(['items.product', 'client']));
    }

    /**
     * سعر بند المرتجع — نفس قاعدة `Returns::create` / `FullResync::clientReturn`:
     * سعر سطر الفاتورة اللي رجع منه (سعر يوم البيع)، وإلا سعر العميل الحالي.
     *
     * @return array{0: float, 1: float} [السعر غير شامل، نسبة الضريبة]
     */
    public static function returnPrice(ClientReturn $ret, Client $client, Product $product): array
    {
        $manual = ReturnItem::where('return_id', $ret->id)->where('product_id', $product->id)->whereNotNull('manual_price')->orderBy('id')->first();
        if ($manual !== null && (float) $manual->manual_price > 0) {
            return [(float) $manual->manual_price, (float) Tax::rate($client, $product)];
        }
        $src = ReturnItem::where('return_id', $ret->id)->where('product_id', $product->id)->whereNotNull('invoice_item_id')
            ->orderBy('id')->first()?->invoiceItem;
        if ($src !== null) {
            return [(float) $src->price, (float) $src->tax_rate];
        }

        return [(float) Pricing::unitPrice($client, $product), (float) Tax::rate($client, $product)];
    }

    /** قيود المرتجع: الدائن، والرد الكاش (مصدره ممكن يكون الزيارة) */
    private static function returnTxs(ClientReturn $ret)
    {
        return Transaction::whereIn('id', array_filter([$ret->transaction_id, $ret->refund_transaction_id]))
            ->orWhere(fn ($q) => $q->where('source_type', ClientReturn::class)->where('source_id', $ret->id))
            ->get()->unique('id');
    }

    /** إعادة ترحيل قيود المرتجع في الدفتر — زي `InvoiceGl::repost` (الفشل مايوقفش التصليح) */
    private static function returnGl(ClientReturn $ret): void
    {
        try {
            $ledger = app(\App\Services\Gl\Ledger::class);
            foreach (self::returnTxs($ret) as $tx) {
                $ledger->repost($tx);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('gl.repost failed', ['return' => $ret->id, 'event' => 'reviewReturn', 'error' => $e->getMessage()]);
        }
    }

    /**
     * بنود المرتجع: الكميات بس، والتسعير من `FullResync::clientReturn` (سعر سطر
     * الفاتورة الأصلية، وإلا قايمة العميل) — وبعدين الدائن والرد الكاش ورصيد العميل.
     * ⚠️ العهدة ماتتحرّكش (قرار ١٠/١١) — زي باقي الأنواع.
     */
    private static function writeReturnLines(ClientReturn $ret, array $target): void
    {
        $ret->load('items.product');

        foreach ($target + array_fill_keys($ret->items->pluck('product_id')->all(), 0) as $pid => $want) {
            $want = $target[$pid] ?? 0;
            $lines = $ret->items->where('product_id', $pid)->sortByDesc('id');
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
                if ($first = $lines->sortBy('id')->first()) {
                    $first->update(['qty' => (int) $first->qty + ($want - $have)]);
                } else {
                    // صنف جديد على الإذن: سليم ومن غير سطر فاتورة — بيتسعّر من قايمة العميل
                    ReturnItem::create([
                        'return_id' => $ret->id, 'product_id' => $pid, 'invoice_id' => null, 'invoice_item_id' => null,
                        'qty' => $want, 'condition' => ClientReturn::CONDITION_GOOD,
                        'list_price' => 0, 'price' => 0, 'total' => 0, 'tax_rate' => 0, 'tax' => 0,
                    ]);
                }
            }
        }

        $ret->refresh();
        $res = FullResync::clientReturn($ret);
        if (isset($res['skip'])) {
            throw new Rejected(__('review.ret_not_priced', ['why' => $res['skip']]));
        }
        $ret->refresh()->load('items');

        $ret->forceFill([
            'good_units' => (int) $ret->items->where('condition', ClientReturn::CONDITION_GOOD)->sum('qty'),
            'damaged_units' => (int) $ret->items->where('condition', ClientReturn::CONDITION_DAMAGED)->sum('qty'),
        ])->save();

        // الدائن صف صف (عشان أوبزرفر الدفتر) — والرد الكاش بنفس المبلغ عشان الرصيد مايتغيّرش
        self::syncReturnTxs($ret);
    }

    /**
     * صف السجل + سجل النشاط. أي تعديل على مستند متعلّم ✓ بيرجّعه «لسه».
     */
    private static function event(User $user, Invoice|PurchaseOrder|ClientReturn $doc, string $action, ?array $before, ?array $after,
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
            case 'ok': case 'issue': case 'reopen': case 'undo':
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
                if (array_key_exists('receipt_note', $a) || array_key_exists('receipt_note', $b)) {
                    $out[__('review.proof_receipt')] = [$yn($b['receipt_note'] ?? false), $yn($a['receipt_note'] ?? false)];
                }
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
