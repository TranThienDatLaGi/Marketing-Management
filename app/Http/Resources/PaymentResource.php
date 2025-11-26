<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'        => $this->id,
            'bill_id'   => $this->bill_id,
            'date'      => $this->date,
            'amount'    => $this->amount,
            'method'    => $this->method,
            'note'      => $this->note,
        ];
    }
}
