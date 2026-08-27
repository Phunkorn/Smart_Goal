<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderList extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'priority',
        'is_visible',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'work_order_list_id', 'id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkOrderListAttachment::class, 'work_order_list_id');
    }

    public function taskRequests(): HasMany
    {
        return $this->hasMany(WorkOrderListTaskRequest::class);
    }
}
