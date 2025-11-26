<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountType extends Model
{
    protected $fillable = ['name', 'description', 'note'];

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function budgets()
    {
        return $this->hasMany(Budget::class);
    }
}
