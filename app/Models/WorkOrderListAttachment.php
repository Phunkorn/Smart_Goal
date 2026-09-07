<?php

namespace App\Models;

use App\Models\Concerns\KeepsFileUntilPurged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderListAttachment extends Model
{
    use KeepsFileUntilPurged;

    protected $fillable = [
        'work_order_list_id',
        'file_path',
        'original_name',
        'file_type',
        'uploaded_by',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(WorkOrderList::class, 'work_order_list_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
