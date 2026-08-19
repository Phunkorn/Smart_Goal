<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrderCommentRead extends Model
{
    protected $fillable = ['work_order_id', 'user_id', 'last_read_update_id'];
}
