<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'zalo',
        'facebook',
        'phone_number',
        'address',
        'product_type',
        'account_type_id',
        'note',
        'rate'
    ];

    public function accountType()
    {
        return $this->belongsTo(AccountType::class);
    }

    public function bills()
    {
        return $this->hasMany(Bill::class);
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }
}
