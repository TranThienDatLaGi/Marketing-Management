<?php

namespace App\Http\Controllers;

use App\Http\Resources\PaymentResource;
use App\Models\Bill;
use App\Models\Payment;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{

    public function store(Request $request)
    {
        $validated = $request->validate([
            'bill_id'     => 'required|exists:bills,id',
            'date'        => 'required|date',
            'amount'      => 'required|numeric|min:0',
            'method'      => 'nullable|in:cash,transfer',
            'note'        => 'nullable|string',
            'is_deposit'  => 'nullable|boolean'
        ]);

        DB::beginTransaction();

        try {
            $bill = Bill::findOrFail($validated['bill_id']);

            $amount = $validated['amount'];

            // Tạo payment trước (lưu nguyên thông tin user gửi)
            $payment = Payment::create($validated);

            // Luôn trừ vào debt trước
            $toReduce = min($amount, $bill->debt_amount);
            $bill->debt_amount = max(0, $bill->debt_amount - $toReduce);

            // Nếu còn dư sau khi trả nợ → chuyển phần dư thành deposit
            $leftover = $amount - $toReduce;
            if ($leftover > 0) {
                $bill->deposit_amount = ($bill->deposit_amount ?? 0) + $leftover;
            }

            // Cập nhật trạng thái theo chuẩn:
            if (($bill->deposit_amount ?? 0) > 0) {
                $bill->status = 'deposit';
            } else {
                if (($bill->debt_amount ?? 0) <= 0) {
                    $bill->status = 'completed';
                } else {
                    $bill->status = 'debt';
                }
            }

            $bill->save();

            DB::commit();

            $payment->load('bill');
            return new PaymentResource($payment);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $payment = Payment::findOrFail($id);

            $validated = $request->validate([
                'date'       => 'sometimes|date',
                'amount'     => 'sometimes|numeric|min:0',
                'method'     => 'nullable|in:cash,transfer',
                'note'       => 'nullable|string',
                'is_deposit' => 'nullable|boolean'
            ]);

            // Lưu oldAmount để dùng nếu user không thay amount
            $oldAmount = $payment->amount;

            // Bill của payment
            $bill = $payment->bill;
            if (!$bill) {
                DB::rollBack();
                return response()->json(['message' => 'Bill not found'], 404);
            }

            // Tính lại debt & deposit từ đầu: bắt đầu với tổng tiền của bill
            $totalMoney = $bill->total_money ?? 0;
            $debt = $totalMoney;
            $deposit = 0;

            // Lấy tất cả payment của bill trừ payment hiện tại, sắp xếp theo date (tuỳ ý)
            $otherPayments = $bill->payments()
                ->where('id', '!=', $payment->id)
                ->orderBy('date')
                ->get();

            foreach ($otherPayments as $p) {
                $amt = $p->amount;
                // trừ nợ trước
                $toReduce = min($amt, $debt);
                $debt -= $toReduce;
                $left = $amt - $toReduce;
                if ($left > 0) {
                    $deposit += $left;
                }
            }

            // Amount mới sẽ là value được gửi nếu có, nếu không giữ amount cũ
            $newAmount = $validated['amount'] ?? $oldAmount;

            // Áp payment mới vào (trừ nợ trước, sau đó deposit)
            $toReduce = min($newAmount, $debt);
            $debt -= $toReduce;
            $left = $newAmount - $toReduce;
            if ($left > 0) {
                $deposit += $left;
            }

            // Không để âm (phòng hờ do float)
            $debt = max(0, $debt);
            $deposit = max(0, $deposit);

            // Cập nhật trạng thái theo quy tắc
            if ($deposit > 0) {
                $bill->status = 'deposit';
            } else {
                if ($debt <= 0) {
                    $bill->status = 'completed';
                } else {
                    $bill->status = 'debt';
                }
            }

            // Gán giá trị mới vào bill
            $bill->debt_amount = $debt;
            $bill->deposit_amount = $deposit;
            $bill->save();

            // Cập nhật payment (lưu các trường user gửi)
            $payment->update($validated);

            DB::commit();

            $payment->load('bill');
            return new PaymentResource($payment);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }


    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $payment = Payment::findOrFail($id);
            $bill = $payment->bill;

            if (!$bill) {
                DB::rollBack();
                return response()->json(['message' => 'Bill not found'], 404);
            }

            // Xóa payment
            $payment->delete();

            // =============================
            //  TÍNH LẠI BILL TỪ ĐẦU
            // =============================

            $totalMoney = $bill->total_money ?? 0;
            $debt = $totalMoney;
            $deposit = 0;

            // Lấy tất cả payment còn lại
            $payments = $bill->payments()->orderBy('date')->get();

            foreach ($payments as $p) {
                $amt = $p->amount;

                // Trả nợ trước
                $reduce = min($amt, $debt);
                $debt -= $reduce;

                // Phần dư thành deposit
                $left = $amt - $reduce;
                if ($left > 0) {
                    $deposit += $left;
                }
            }

            // Không để âm
            $debt = max(0, $debt);
            $deposit = max(0, $deposit);

            // =============================
            //  Cập nhật trạng thái bill
            // =============================
            if ($deposit > 0) {
                $bill->status = 'deposit';
            } else {
                if ($debt <= 0) {
                    $bill->status = 'completed';
                } else {
                    $bill->status = 'debt';
                }
            }

            // Lưu bill
            $bill->debt_amount = $debt;
            $bill->deposit_amount = $deposit;
            $bill->save();

            DB::commit();

            return response()->json([
                'message' => 'Payment deleted successfully'
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
