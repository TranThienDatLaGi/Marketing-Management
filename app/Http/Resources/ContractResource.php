<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                => $this->id,
            'date'              => $this->date,
            'customer_id'       => $this->customer->id ?? null,
            'customer_name'     => $this->customer->name ?? null,
            'supplier_name'     => $this->budget->supplier->name ?? null,
            'account_type_id'   => $this->budget->accountType->id ?? null,
            'account_type_name' => $this->budget->accountType->name ?? null,
            'product'           => $this->product,
            'product_type'      => $this->product_type,
            'total_cost'        => $this->total_cost,
            'customer_rate'     => $this->customer_rate,
            'supplier_rate'     => $this->supplier_rate,
            'note'              => $this->note,
            'budget_id'         => $this->budget->id ?? null,
            'customer_actually_paid'=> $this->customer_actually_paid
        ];
    }
}
