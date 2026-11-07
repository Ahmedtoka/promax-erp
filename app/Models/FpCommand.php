<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** أمر مستني المكنة تاخده في getrequest — `C:<id>:<command>` */
class FpCommand extends Model
{
    protected $fillable = ['fp_device_id', 'command', 'status', 'return_code', 'created_by', 'sent_at', 'done_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(FpDevice::class, 'fp_device_id');
    }
}
