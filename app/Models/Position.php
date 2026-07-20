<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'position_name'
    ];

    public function users()
    {
        return $this->hasMany(user::class);
    }
}
