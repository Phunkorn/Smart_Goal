<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class WorkOrderListAttachment extends Model
{
    protected $fillable = [
        'work_order_list_id',
        'file_path',
        'original_name',
        'file_type',
        'uploaded_by',
    ];

    protected static function booted(): void
    {
        static::deleting(function (WorkOrderListAttachment $attachment) {
            Storage::disk('public')->delete($attachment->file_path);
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(WorkOrderList::class, 'work_order_list_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
