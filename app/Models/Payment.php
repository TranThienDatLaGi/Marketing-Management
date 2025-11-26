<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'bill_id',
        'date',
        'amount',
        'method',
        'note'
    ];

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }
}
