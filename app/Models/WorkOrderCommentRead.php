<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderCommentRead extends Model
{
    protected $fillable = ['work_order_id', 'user_id', 'last_read_update_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
