<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobImage extends Model
{
    protected $fillable = [
        'job_id',
        'file_path',
        'original_name',
        'file_type',
        'uploaded_by',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'job_id', 'job_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
