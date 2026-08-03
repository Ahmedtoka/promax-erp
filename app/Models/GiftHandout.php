<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * توزيعة هدية واحدة — مين اداها لمين وإمتى.
 *
 * ⚠️ **صف لكل توزيعة مش عدّاد.** «صرفنا 200 عينة» رقم مالوش تفصيل؛
 * السؤال بعد الحملة هو «اداها لمين»، والإجابة موجودة هنا بس.
 */
class GiftHandout extends Model
{
    protected $fillable = [
        'custody_id', 'user_id', 'product_id', 'client_id',
        'client_request_id', 'visit_id', 'batch_id', 'qty', 'reason', 'note',
    ];

    protected function casts(): array
    {
        return ['qty' => 'integer'];
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(Custody::class);
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** طلب عميل جديد — الهدية بتتسجل عليه قبل ما يبقى عميل رسمي */
    public function clientRequest(): BelongsTo
    {
        return $this->belongsTo(ClientRequest::class);
    }

    /** اسم المستلم أياً كان نوعه — عميل، طلب جديد، أو توزيع عام */
    public function recipientName(): string
    {
        if ($this->client) {
            return $this->client->displayName();
        }

        if ($this->clientRequest) {
            return $this->clientRequest->name.' ('.__('field.gift_new_request').')';
        }

        return $this->note ?: '—';
    }
}
