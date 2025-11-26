<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BillResource extends JsonResource
{
    public function toArray($request)
    {
        // Tổng tiền đã thanh toán
        $paid_amount = $this->payments->sum('amount');

        return [
            'id'              => $this->id,
            'date'            => $this->date,
            'customer_id'     => $this->customer_id,
            'customer_name'   => $this->customer->name ?? null,
            'product'         => $this->product ?? null, // Nếu bạn có cột product trong bills
            'total_money'     => $this->total_money,
            'deposit_amount'  => $this->deposit_amount,
            'debt_amount'     => $this->debt_amount,
            'paid_amount'     => $paid_amount,
            'status'          => $this->status,
            'note'            => $this->note,
        ];
    }
}
