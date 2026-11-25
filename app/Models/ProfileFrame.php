<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileFrame extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['animated' => 'boolean', 'is_active' => 'boolean'];
    }
}
