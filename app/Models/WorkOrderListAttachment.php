<?php

namespace App\Models;

use App\Support\ProtectedMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            ProtectedMedia::deleteAttachment($attachment->file_path);
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
