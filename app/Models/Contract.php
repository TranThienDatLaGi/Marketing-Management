<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    protected $fillable = [
        'date',
        'customer_id',
        'budget_id',
        'product',
        'product_type',
        'total_cost',
        'supplier_rate',
        'customer_rate',
        'note',
        'bill_id',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }
    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }
}
