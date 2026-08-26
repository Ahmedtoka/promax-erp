<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** رسالة في شات المهمة — نص و/أو مرفق (Task Management ٢٦/٨) */
class TaskComment extends Model
{
    protected $fillable = ['task_id', 'user_id', 'body', 'file_path', 'file_name', 'is_system'];

    protected $casts = ['is_system' => 'bool'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function fileUrl(): ?string
    {
        return $this->file_path ? Storage::disk('public')->url($this->file_path) : null;
    }
}
