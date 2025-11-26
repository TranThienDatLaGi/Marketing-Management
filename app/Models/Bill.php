<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    protected $fillable = [
        'date',
        'customer_id',
        'total_money',
        'deposit_amount',
        'debt_amount',
        'status',
        'note'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }
}
