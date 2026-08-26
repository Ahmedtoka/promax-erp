<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** مرفق على المهمة نفسها — اتحمّل مع الإنشاء (Task Management ٢٦/٨) */
class TaskFile extends Model
{
    protected $fillable = ['task_id', 'uploaded_by', 'path', 'name'];

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
