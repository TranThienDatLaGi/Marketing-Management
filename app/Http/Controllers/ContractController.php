<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContractResource;
use App\Models\Bill;
use App\Models\Contract;
use App\Models\Payment;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContractController extends Controller
{

    public function store(Request $request)
    {
        $validated = $request->validate([
            'date'          => 'required|date',
            'customer_id'   => 'required|exists:customers,id',
            'budget_id'     => 'required|exists:budgets,id',
            'product'       => 'required',
            'product_type'  => 'required|in:legal,illegal,middle-illegal',
            'total_cost'    => 'nullable|numeric',
            'supplier_rate' => 'nullable|numeric',
            'customer_rate' => 'nullable|numeric',
            'note'          => 'nullable|string',
            'customer_paid' => 'nullable|numeric',
        ]);
        $existing = Contract::where('product', $validated['product'])
            ->where('customer_id', $validated['customer_id'])
            ->where('budget_id', $validated['budget_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Hợp đồng cho khách hàng, sản phẩm và ngân sách này đã tồn tại trước đó'
            ], 400);
        }
        // Tạo contract mới
        $contract = Contract::create($validated);
        $contract->load(['customer', 'budget.supplier', 'budget.accountType']);

        // Tính tổng tiền contract
        $contractTotal = ($validated['total_cost'] ?? 0) * ($validated['customer_rate'] ?? 0);
        $customerPaid = $validated['customer_paid'] ?? 0;

        // Tìm contract cũ cùng product
        $existingContract = Contract::where('product', $validated['product'])
            ->where('customer_id', $validated['customer_id'])
            ->where('id', '!=', $contract->id) // loại contract vừa tạo
            ->first();
        if ($existingContract) {
            // Lấy bill cũ
            $bill = $existingContract->bill;

            // Cập nhật bill
            $bill->total_money += $contractTotal;
            $bill->debt_amount += $contractTotal;

            if ($customerPaid > 0) {
                $bill->deposit_amount += $customerPaid;
            }

            $bill->save();

            // Nếu có customer_paid, tạo payment
            if ($customerPaid > 0) {
                Payment::create([
                    'bill_id' => $bill->id,
                    'date'    => now(),
                    'amount'  => $customerPaid,
                    'method'  => 'transfer',
                    'note'    => "có cọc.Khách đặt cọc {$customerPaid}",
                    'is_deposit'=>1,
                ]);
            }
        } else {
            // Tạo bill mới
            $bill = Bill::create([
                'date'          => now(),
                'customer_id'   => $validated['customer_id'],
                'total_money'   => $contractTotal,
                'debt_amount'   => $contractTotal,
                'deposit_amount' => $customerPaid,
                'note'          => $validated['note'] ?? null,
                'status'        => $customerPaid > 0 ? 'deposit' : 'debt',
            ]);

            // Liên kết contract với bill mới
            $contract->bill_id = $bill->id;
            $contract->save();

            // Nếu có customer_paid, tạo payment
            if ($customerPaid > 0) {
                Payment::create([
                    'bill_id' => $bill->id,
                    'date'    => now(),
                    'amount'  => $customerPaid,
                    'method'  => 'transfer',
                    'note'    => "có cọc.Khách đặt cọc {$customerPaid}",
                    'is_deposit' => 1,
                ]);
            }
        }

        return new ContractResource($contract);
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $contract = Contract::findOrFail($id);

            $validated = $request->validate([
                'date'          => 'sometimes|date',
                'customer_id'   => 'sometimes|exists:customers,id',
                'budget_id'     => 'sometimes|exists:budgets,id',
                'product'       => 'sometimes|string',
                'product_type'  => 'sometimes|in:legal,illegal,middle-illegal',
                'total_cost'    => 'sometimes|numeric',
                'supplier_rate' => 'sometimes|numeric',
                'customer_rate' => 'sometimes|numeric',
                'note'          => 'nullable|string',
                'customer_paid' => 'nullable|numeric',
            ]);
            // Log::info("contract ". $contract);
            // Lưu dữ liệu contract cũ để tính toán
            $oldTotal   = $contract->total_cost * $contract->customer_rate;
            $oldBillId  = $contract->bill_id;

            $oldCustomerPaid = 0;

            // Lấy payment cọc nếu có
            $oldPayment = Payment::where('bill_id', $oldBillId)
                ->where('is_deposit', 1)
                ->first();


            if ($oldPayment) {
                $oldCustomerPaid = $oldPayment->amount;
            }

            /** ==========================================
             * 1. XÁC ĐỊNH contract có đổi “nhóm bill” ko?
             * ========================================= */
            $isChangedGroup =
                (isset($validated['customer_id']) && $validated['customer_id'] != $contract->customer_id)
                || (isset($validated['budget_id']) && $validated['budget_id'] != $contract->budget_id)
                || (isset($validated['product']) && $validated['product'] != $contract->product);

            /** =======================================================
             * TRƯỜNG HỢP A: CONTRACT ĐỔI customer_id / budget_id / product
             * ======================================================= */
            if ($isChangedGroup) {

                /** --------------------------
                 * A1. XỬ LÝ BILL CŨ
                 * -------------------------- */

                $bill = Bill::find($oldBillId);

                // Trừ total_money & debt_amount
                $bill->total_money -= $oldTotal;
                $bill->debt_amount -= $oldTotal;

                // Trừ tiền đặt cọc cũ (nếu có)
                if ($oldCustomerPaid > 0) {
                    $bill->deposit_amount -= $oldCustomerPaid;
                }

                $bill->save();

                // Xóa payment đặt cọc cũ
                if ($oldPayment) {
                    $oldPayment->delete();
                }

                // Kiểm tra còn contract nào khác trong bill không
                $other = Contract::where('bill_id', $oldBillId)
                    ->where('id', '!=', $contract->id)
                    ->exists();

                if (!$other) {
                    // Xóa toàn bộ payment và bill
                    Payment::where('bill_id', $oldBillId)->delete();
                    $bill->delete();
                }

                /** --------------------------
                 * A2. TẠO BILL MỚI (logic giống store)
                 * -------------------------- */

                $contractTotal = ($validated['total_cost'] ?? $contract->total_cost)
                    * ($validated['customer_rate'] ?? $contract->customer_rate);

                $newCustomerPaid = $validated['customer_paid'] ?? 0;

                $newBill = Bill::create([
                    'date'          => now(),
                    'customer_id'   => $validated['customer_id'] ?? $contract->customer_id,
                    'total_money'   => $contractTotal,
                    'debt_amount'   => $contractTotal,
                    'deposit_amount' => $newCustomerPaid,
                    'note'          => $validated['note'] ?? $contract->note,
                    'status'        => $newCustomerPaid > 0 ? 'deposit' : 'debt',
                ]);

                // Gán contract vào bill mới
                $validated['bill_id'] = $newBill->id;

                // Nếu có cọc → tạo payment
                if ($newCustomerPaid > 0) {
                    Payment::create([
                        'bill_id' => $newBill->id,
                        'date'    => now(),
                        'amount'  => $newCustomerPaid,
                        'method'  => 'transfer',
                        'note'    => "có cọc. khách đặt cọc {$newCustomerPaid}",
                    ]);
                }

                // Cập nhật contract
                $contract->update($validated);

                DB::commit();
                return new ContractResource($contract);
            }

            /** =======================================================
             * TRƯỜNG HỢP B: KHÔNG ĐỔI nhóm bill → chỉ cập nhật bill hiện tại
             * ======================================================= */
            $bill = Bill::find($oldBillId);

            if (!$bill) {
                // Tự động tạo bill mới cho contract này
                $bill = Bill::create([
                    'date'          => now(),
                    'customer_id'   => $contract->customer_id,
                    'total_money'   => 0,
                    'deposit_amount' => 0,
                    'debt_amount'   => 0,
                    'status'        => 'debt',
                    'note'          => $contract->note,
                ]);

                $contract->bill_id = $bill->id;
                $contract->save();
            }


            // Tính lại total mới
            $newTotal = ($validated['total_cost'] ?? $contract->total_cost)
                * ($validated['customer_rate'] ?? $contract->customer_rate);

            // Cập nhật bill: trừ old + cộng new
            $bill->total_money = $bill->total_money - $oldTotal + $newTotal;
            $bill->debt_amount = $bill->debt_amount - $oldTotal + $newTotal;

            // Trừ tiền cọc cũ
            if ($oldCustomerPaid > 0) {
                $bill->deposit_amount -= $oldCustomerPaid;
            }

            // Nếu người dùng gửi customer_paid mới → cập nhật lại
            $newPaid = $validated['customer_paid'] ?? 0;

            if ($newPaid > 0) {
                $bill->deposit_amount += $newPaid;

                // Update payment cũ hoặc tạo mới
                if ($oldPayment) {
                    $oldPayment->amount = $newPaid;
                    $oldPayment->note   = "có cọc. khách cọc {$newPaid}";
                    $oldPayment->save();
                } else {
                    Payment::create([
                        'bill_id' => $bill->id,
                        'date'    => now(),
                        'amount'  => $newPaid,
                        'method'  => 'transfer',
                        'note'    => "có cọc. khách cọc {$newPaid}",
                    ]);
                }
            } else {
                // Xóa payment nếu newPaid = 0
                if ($oldPayment) $oldPayment->delete();
            }
            if ($bill->deposit_amount > 0) {
                $bill->status = 'deposit';
            } else {
                if ($bill->debt_amount <= 0) {
                    $bill->status = 'completed';
                } else {
                    $bill->status = 'debt';
                }
            }
            $bill->save();

            /** ------------------
             * Cập nhật contract
             * ------------------ */
            $contract->update($validated);

            DB::commit();
            $contract->refresh();
            $contract->load(['customer', 'budget.supplier', 'budget.accountType']);
            return new ContractResource($contract);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function destroy($id)
    {
        // 1. Lấy contract
        $contract = Contract::findOrFail($id);
        $bill = $contract->bill;

        if (!$bill) {
            return response()->json(['message' => 'Bill not found'], 404);
        }

        // 2. Tính số tiền cần trừ (tổng doanh thu contract đóng góp vào bill)
        $subtractMoney = $contract->total_cost * $contract->customer_rate;

        // 3. Cập nhật bill: total_money & debt_amount
        $bill->total_money -= $subtractMoney;
        $bill->debt_amount -= $subtractMoney;

        // Không để âm
        $bill->total_money = max(0, $bill->total_money);
        $bill->debt_amount = max(0, $bill->debt_amount);

        // 4. Kiểm tra payment có note "có cọc.khách đặt cọc"
        $payment = $bill->payments()
            ->where('note', 'có cọc.Khách đặt cọc')
            ->first();

        if ($payment) {
            // Trừ tiền cọc
            $bill->deposit_amount -= $payment->amount;
            $bill->deposit_amount = max(0, $bill->deposit_amount);

            // Xóa payment
            $payment->delete();
        }

        // 5. Cập nhật status của bill
        if ($bill->deposit_amount > 0) {
            $bill->status = 'deposit';
        } else {
            if ($bill->debt_amount == 0) {
                $bill->status = 'completed';
            } else {
                $bill->status = 'debt';
            }
        }

        // 6. Lưu bill
        $bill->save();

        // 7. Xóa contract
        $contract->delete();

        // 8. Nếu bill không còn contract → xóa bill
        if ($bill->contracts()->count() == 0) {
            $bill->delete();
        }

        return response()->json(['message' => 'Deleted successfully']);
    }

    public function filteredContract(Request $request)
    {
        $query = Contract::query()
            ->with(['customer', 'budget.supplier', 'budget.accountType'])
            ->orderBy('date', 'desc'); // Sắp xếp theo ngày mới nhất

        // Lọc theo customer
        if ($request->customer_id && $request->customer_id !== "all") {
            $query->where('customer_id', $request->customer_id);
        }

        // Lọc theo supplier
        if ($request->supplier_id && $request->supplier_id !== "all") {
            $query->whereHas('budget', function ($q) use ($request) {
                $q->where('supplier_id', $request->supplier_id);
            });
        }

        // Lọc theo account_type
        if ($request->account_type_id && $request->account_type_id !== "all") {
            $query->whereHas('budget', function ($q) use ($request) {
                $q->where('account_type_id', $request->account_type_id);
            });
        }

        // ✅ Lọc theo product_type (legal, illegal, middle-illegal)
        if ($request->product_type && $request->product_type !== "all") {
            $query->where('product_type', $request->product_type);
        }

        // Lọc theo ngày
        if ($request->from_date) {
            $query->whereDate('date', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->whereDate('date', '<=', $request->to_date);
        }

        // Phân trang
        $contracts = $query->paginate($request->per_page ?? 15);

        // Resource
        $data = ContractResource::collection($contracts);

        // Trả về JSON
        return response()->json([
            'data' => $data,
            'pagination' => [
                'from'           => $contracts->firstItem(),
                'to'             => $contracts->lastItem(),
                'total'          => $contracts->total(),
                'next_page_url'  => $contracts->nextPageUrl(),
                'prev_page_url'  => $contracts->previousPageUrl(),
                'last_page'      => $contracts->lastPage(),
            ]
        ]);
    }
}
