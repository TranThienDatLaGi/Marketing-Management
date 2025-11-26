<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    public function toArray($request)
    {
        $customer_cost = $this->total_cost * $this->customer_rate;
        $supplier_cost = $this->total_cost * $this->supplier_rate;

        return [
            'id'                => $this->id,
            'customer_name'     => $this->customer->name ?? null,
            'customer_id'     => $this->customer->id ?? null,
            'supplier_name'     => $this->budget->supplier->name ?? null,
            'supplier_id'     => $this->budget->supplier->id ?? null,
            'account_type_name' => $this->budget->accountType->name ?? null,
            'account_type_id' => $this->budget->accountType->id ?? null,
            'product'           => $this->product,
            'product_type'      => $this->product_type,
            'total_cost'        => $this->total_cost,
            'supplier_rate'     => $this->supplier_rate,
            'customer_rate'     => $this->customer_rate,
            'note'              => $this->note,
            'customer_cost'     => $customer_cost,
            'supplier_cost'     => $supplier_cost,
            'profit'            => $customer_cost - $supplier_cost,
        ];
    }
}
