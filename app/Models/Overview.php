<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Overview extends Model
{
    protected $fillable = [
        'type',
        'target_id',
        'period',
        'data'
    ];

    protected $casts = [
        'data' => 'array'
    ];
}
