<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'zalo', 'phoneNumber', 'address', 'note'];

    public function budgets()
    {
        return $this->hasMany(Budget::class);
    }
}
