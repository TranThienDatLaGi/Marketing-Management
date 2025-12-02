<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'supplier_id'       => $this->supplier_id,
            'account_type_id'   => $this->account_type_id,
            'account_type_name' => $this->accountType->name ?? null,
            'date'              => $this->date,
            'money'             => $this->money,
            'product_type'      => $this->product_type,
            'supplier_rate'     => $this->supplier_rate,
            'customer_rate'     => $this->customer_rate,
            'status'            => $this->status,
            'note'              => $this->note,
        ];
    }
}
