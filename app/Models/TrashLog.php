<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrashLog extends Model
{
    protected $fillable = [
        'entity_type',
        'entity_id',
        'payload_json',
        'deleted_by',
        'deleted_at',
        'purge_after',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'deleted_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
