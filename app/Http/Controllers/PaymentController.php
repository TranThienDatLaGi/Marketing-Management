<?php

namespace App\Http\Controllers;

use App\Http\Resources\PaymentResource;
use App\Models\Bill;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{

    public function store(Request $request)
    {
        $validated = $request->validate([
            'bill_id' => 'required|exists:bills,id',
            'date'    => 'required|date',
            'amount'  => 'required|numeric|min:0',
            'method'  => 'nullable|in:cash,transfer',
            'note'    => 'nullable|string',
            'is_deposit'=> 'nullable|boolean'
        ]);

        // Tìm hóa đơn
        $bill = Bill::findOrFail($validated['bill_id']);

        // Tạo payment
        $payment = Payment::create($validated);

        // Cập nhật debt_amount
        $bill->debt_amount = max(0, $bill->debt_amount - $validated['amount']);

        // Nếu deposit_amount và debt_amount đều = 0 → completed
        if (($bill->deposit_amount ?? 0) == 0 && ($bill->debt_amount ?? 0) == 0) {
            $bill->status = 'completed';
        }

        $bill->save();

        // Load quan hệ nếu muốn
        $payment->load('bill');

        // Trả về resource
        return new PaymentResource($payment);
    }


    public function update(Request $request, $id)
    {
        $payment = Payment::findOrFail($id);

        $validated = $request->validate([
            'date'   => 'sometimes|date',
            'amount' => 'sometimes|numeric|min:0',
            'method' => 'nullable|in:cash,transfer',
            'note'   => 'nullable|string',
            'is_deposit' => 'nullable|boolean'
        ]);

        // Lưu giá trị cũ để tính lại debt_amount
        $oldAmount = $payment->amount;

        // Cập nhật payment
        $payment->update($validated);

        // Nếu amount thay đổi → cập nhật debt_amount của bill
        if (isset($validated['amount'])) {
            $bill = $payment->bill;

            // debt_amount = debt_amount + oldAmount - newAmount
            $bill->debt_amount = max(0, ($bill->debt_amount + $oldAmount - $validated['amount']));

            // Nếu deposit_amount và debt_amount đều = 0 → completed
            if (($bill->deposit_amount ?? 0) == 0 && ($bill->debt_amount ?? 0) == 0) {
                $bill->status = 'completed';
            }

            $bill->save();
        }

        $payment->load('bill');

        return new PaymentResource($payment);
    }

    public function destroy($id)
    {
        $payment = Payment::findOrFail($id);

        // Lưu bill để cập nhật
        $bill = $payment->bill;

        // Lưu amount trước khi xóa
        $amount = $payment->amount;

        // Xóa payment
        $payment->delete();

        // Cập nhật lại debt_amount
        $bill->debt_amount = max(0, ($bill->debt_amount + $amount));

        // Cập nhật trạng thái bill
        if (($bill->deposit_amount ?? 0) == 0 && ($bill->debt_amount ?? 0) == 0) {
            $bill->status = 'completed';
        } elseif ($bill->debt_amount > 0 && $bill->deposit_amount==0) {
            $bill->status = 'debt';
        }

        $bill->save();

        return response()->json([
            'message' => 'Payment deleted successfully',
        ]);
    }

    public function getPaymentByBill($billId)
    {
        // Kiểm tra bill tồn tại
        $bill = Bill::findOrFail($billId);

        // Lấy tất cả payments theo bill_id
        $payments = $bill->payments()->orderBy('date', 'desc')->get();

        // Trả về resource collection
        return PaymentResource::collection($payments);
    }
}
