<?php

namespace App\Models\Gl;

use Illuminate\Database\Eloquent\Model;

class GlLineOverride extends Model
{
    public $timestamps = false;

    protected $table = 'gl_line_overrides';

    protected $fillable = ['line_id', 'from_account_id', 'to_account_id', 'user_id', 'at', 'note'];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }
}
