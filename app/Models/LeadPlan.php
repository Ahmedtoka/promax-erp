<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** جدولة ليد على يوم بعينه لمندوب (سكشن المحتملين ٢٦/٨) */
class LeadPlan extends Model
{
    protected $fillable = ['user_id', 'lead_id', 'plan_date', 'sort', 'created_by'];

    protected $casts = ['plan_date' => 'date'];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
