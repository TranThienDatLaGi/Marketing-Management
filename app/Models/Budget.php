<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    protected $fillable = [
        'supplier_id',
        'account_type_id',
        'date',
        'money',
        'product_type',
        'supplier_rate',
        'customer_rate',
        'status',
        'note'
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function accountType()
    {
        return $this->belongsTo(AccountType::class);
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->date) {
                $model->date = now()->setTimezone(config('app.timezone'))->toDateString();
            }
        });
    }
}
