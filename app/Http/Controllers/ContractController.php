<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContractResource;
use App\Models\Bill;
use App\Models\Contract;
use App\Models\Payment;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'customer_actually_paid' => 'nullable|numeric',
        ]);

        // tránh duplicate hợp đồng cùng product - customer - budget
        $existing = Contract::where('product', $validated['product'])
            ->where('customer_id', $validated['customer_id'])
            ->where('budget_id', $validated['budget_id'])
            ->where('supplier_rate', $validated['supplier_rate'])
            ->where('customer_rate', $validated['customer_rate'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Hợp đồng cho khách hàng, sản phẩm và ngân sách này đã tồn tại trước đó'
            ], 400);
        }

        // Tạo contract mới (chưa liên kết bill)
        $contract = null;
        DB::transaction(function () use ($validated, &$contract) {

            $contract = Contract::create($validated);
            $contract->load(['customer', 'budget.supplier', 'budget.accountType']);

            // Tính tổng tiền contract (theo cách bạn đang dùng)
            $contractTotal = ($validated['total_cost'] ?? 0) * ($validated['customer_rate'] ?? 0);
            $customerPaid = $validated['customer_actually_paid'] ?? 0;

            // Tìm contract cũ cùng product - customer - budget (loại contract vừa tạo)
            $existingContract = Contract::where('product', $validated['product'])
                ->where('customer_id', $validated['customer_id'])
                ->where('budget_id', $validated['budget_id'])
                ->where('id', '!=', $contract->id)
                ->first();

            // helper cập nhật status dựa trên debt_amount & deposit_amount
            $updateStatus = function ($bill) {
                if (bccomp($bill->debt_amount, 0, 2) == 0 && bccomp($bill->deposit_amount, 0, 2) == 0) {
                    $bill->status = 'completed';
                } elseif (bccomp($bill->debt_amount, 0, 2) == 0 && bccomp($bill->deposit_amount, 0, 2) == 1) {
                    $bill->status = 'deposit';
                } elseif (bccomp($bill->debt_amount, 0, 2) == 1 && bccomp($bill->deposit_amount, 0, 2) == 0) {
                    $bill->status = 'debt';
                } else {
                    // Trường hợp cả hai > 0 (ít khi xảy ra với logic này) — chọn 'deposit' làm ưu tiên.
                    $bill->status = 'deposit';
                }
            };

            if ($existingContract) {
                // Lấy bill cũ từ existingContract
                $bill = $existingContract->bill;

                // Nếu không có bill (không lường trước) thì tạo bill mới thay thế
                if (!$bill) {
                    $bill = Bill::create([
                        'date' => now(),
                        'customer_id' => $validated['customer_id'],
                        'total_money' => 0,
                        'debt_amount' => 0,
                        'deposit_amount' => 0,
                        'note' => $validated['note'] ?? null,
                        'status' => 'debt',
                    ]);
                }

                // Cộng tổng hợp đồng mới vào bill tổng
                $bill->total_money = bcadd($bill->total_money, $contractTotal, 2);

                // Khi thêm contract mới, ban đầu ta thêm nợ tương ứng vào debt_amount
                $bill->debt_amount = bcadd($bill->debt_amount, $contractTotal, 2);

                // Nếu có tiền khách trả cho contract này, áp dụng vào bill
                if ($customerPaid > 0) {
                    // Nếu có nợ > 0 thì customerPaid sẽ trừ vào nợ trước
                    if (bccomp($bill->debt_amount, 0, 2) == 1) {
                        // Lưu giá trị nợ trước khi trừ (đã chứa contractTotal)
                        $currentDebt = $bill->debt_amount;

                        if (bccomp($customerPaid, $currentDebt, 2) >= 0) {
                            // Khách trả đủ hoặc dư hơn nợ
                            $left = bcsub($customerPaid, $currentDebt, 2);
                            $bill->debt_amount = 0;
                            // deposit_amount tăng thêm phần dư (nếu left > 0)
                            $bill->deposit_amount = bcadd($bill->deposit_amount, $left, 2);
                        } else {
                            // Khách trả chưa đủ, giảm debt
                            $bill->debt_amount = bcsub($currentDebt, $customerPaid, 2);
                            // deposit giữ nguyên
                        }
                    } else {
                        // Nếu debt = 0 thì tiền trả là deposit
                        $bill->deposit_amount = bcadd($bill->deposit_amount, $customerPaid, 2);
                    }

                    // tạo payment ghi nhận customerPaid (lưu nguyên số đã nộp)
                    Payment::create([
                        'bill_id' => $bill->id,
                        'date'    => now(),
                        'amount'  => $customerPaid,
                        'method'  => 'transfer',
                        'note'    => "có cọc. Khách đặt cọc {$customerPaid}",
                        'is_deposit' => 1,
                    ]);
                }

                // Cập nhật status theo quy tắc
                $updateStatus($bill);
                $bill->save();

                // Liên kết contract mới với bill cũ
                $contract->bill_id = $bill->id;
                $contract->save();
            } else {
                // Không có existingContract -> tạo bill mới
                $bill = Bill::create([
                    'date'          => now(),
                    'customer_id'   => $validated['customer_id'],
                    'total_money'   => $contractTotal,
                    // debt & deposit sẽ gán phía dưới tuỳ customerPaid
                    'debt_amount'   => 0,
                    'deposit_amount' => 0,
                    'note'          => $validated['note'] ?? null,
                    'status'        => 'debt',
                ]);

                // Xử lý customerPaid so với total_money
                if ($customerPaid > 0) {
                    $diff = bcsub($customerPaid, $bill->total_money, 2); // customerPaid - total_money

                    if (bccomp($diff, 0, 2) == 1) {
                        // customerPaid > total_money => debt = 0, deposit = diff
                        $bill->debt_amount = 0;
                        $bill->deposit_amount = $diff;
                    } elseif (bccomp($diff, 0, 2) == -1) {
                        // customerPaid < total_money => debt = total_money - customerPaid, deposit = 0
                        $bill->debt_amount = bcsub($bill->total_money, $customerPaid, 2);
                        $bill->deposit_amount = 0;
                    } else {
                        // bằng 0
                        $bill->debt_amount = 0;
                        $bill->deposit_amount = 0;
                    }

                    // tạo payment lưu customerPaid
                    Payment::create([
                        'bill_id' => $bill->id,
                        'date'    => now(),
                        'amount'  => $customerPaid,
                        'method'  => 'transfer',
                        'note'    => "có cọc. Khách đặt cọc {$customerPaid}",
                        'is_deposit' => 1,
                    ]);
                } else {
                    // không có thanh toán trước
                    $bill->debt_amount = $bill->total_money;
                    $bill->deposit_amount = 0;
                }

                // cập nhật status
                $updateStatus($bill);
                $bill->save();

                // Liên kết contract với bill mới
                $contract->bill_id = $bill->id;
                $contract->save();
            }
        });

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
                'customer_actually_paid' => 'nullable|numeric',
            ]);

            /** Lấy dữ liệu cũ */
            $oldTotal = $contract->total_cost * $contract->customer_rate;
            $oldBillId = $contract->bill_id;

            $oldPayment = Payment::where('bill_id', $oldBillId)
                ->where('is_deposit', 1)
                ->first();
            $oldCustomerPaid = $oldPayment->amount ?? 0;

            /** Kiểm tra đổi nhóm bill hay không */
            $isChangedGroup =
                (isset($validated['customer_id']) && $validated['customer_id'] != $contract->customer_id) ||
                (isset($validated['budget_id']) && $validated['budget_id'] != $contract->budget_id) ||
                (isset($validated['product']) && $validated['product'] != $contract->product);

            /** Helper cập nhật trạng thái bill */
            $updateStatus = function ($bill) {
                if ($bill->debt_amount == 0 && $bill->deposit_amount == 0) {
                    $bill->status = 'completed';
                } elseif ($bill->debt_amount == 0 && $bill->deposit_amount > 0) {
                    $bill->status = 'deposit';
                } elseif ($bill->debt_amount > 0 && $bill->deposit_amount == 0) {
                    $bill->status = 'debt';
                } else {
                    $bill->status = 'deposit';
                }
            };

            /** ============================================================
             * A. CONTRACT ĐỔI NHÓM BILL → TÁCH BILL CŨ, TẠO BILL MỚI
             * ============================================================ */
            if ($isChangedGroup) {

                /** 1. CẬP NHẬT BILL CŨ */
                $bill = Bill::find($oldBillId);

                if ($bill) {
                    // trừ tiền contract cũ
                    $bill->total_money -= $oldTotal;
                    $bill->debt_amount -= $oldTotal;

                    // trừ cọc cũ
                    if ($oldCustomerPaid > 0) {
                        $bill->deposit_amount -= $oldCustomerPaid;
                    }

                    $updateStatus($bill);
                    $bill->save();

                    // xoá payment cũ (đã lấy amount ở trên)
                    if ($oldPayment) {
                        $oldPayment->delete();
                    }

                    // IMPORTANT: để tránh cascade delete (contracts -> bill onDelete cascade),
                    // ta phải **ngắt liên kết contract khỏi bill cũ** trước khi xóa bill.
                    // Gán bill_id = null rồi save để controller tiếp theo có thể tạo bill mới và gán lại.
                    $contract->bill_id = null;
                    $contract->save();

                    // nếu bill không còn contract → xoá luôn bill
                    $other = Contract::where('bill_id', $oldBillId)
                        ->where('id', '!=', $contract->id)
                        ->exists();

                    if (!$other) {
                        // xoá mọi payment còn lại (nếu có)
                        Payment::where('bill_id', $oldBillId)->delete();
                        $bill->delete();
                    }
                }

                /** 2. TẠO BILL MỚI (như store) */
                $newTotal = ($validated['total_cost'] ?? $contract->total_cost) *
                    ($validated['customer_rate'] ?? $contract->customer_rate);

                $newPaid = $validated['customer_actually_paid'] ?? 0;

                // bill mới
                $newBill = Bill::create([
                    'date'        => now(),
                    'customer_id' => $validated['customer_id'] ?? $contract->customer_id,
                    'total_money' => $newTotal,
                    'note'        => $validated['note'] ?? $contract->note,
                    'debt_amount' => 0,
                    'deposit_amount' => 0,
                ]);

                /** Áp logic tiền như store() */
                if ($newPaid > 0) {
                    $diff = $newPaid - $newTotal;

                    if ($diff > 0) {
                        $newBill->debt_amount = 0;
                        $newBill->deposit_amount = $diff;
                    } elseif ($diff < 0) {
                        $newBill->debt_amount = $newTotal - $newPaid;
                        $newBill->deposit_amount = 0;
                    } else {
                        $newBill->debt_amount = 0;
                        $newBill->deposit_amount = 0;
                    }
                } else {
                    // không trả trước → toàn bộ thành debt
                    $newBill->debt_amount = $newTotal;
                }

                $updateStatus($newBill);
                $newBill->save();

                /** Tạo payment */
                if ($newPaid > 0) {
                    Payment::create([
                        'bill_id' => $newBill->id,
                        'date' => now(),
                        'amount' => $newPaid,
                        'note' => "có cọc. Khách đặt cọc {$newPaid}",
                        'is_deposit' => 1
                    ]);
                }

                /** Gán bill mới vào contract */
                $validated['bill_id'] = $newBill->id;
                $contract->update($validated);

                DB::commit();
                return new ContractResource($contract);
            }

            /** ============================================================
             * B. KHÔNG ĐỔI NHÓM BILL → CHỈ CẬP NHẬT CONTRACT & BILL CŨ
             * ============================================================ */
            $bill = Bill::find($oldBillId);

            if (!$bill) {
                // hiếm khi xảy ra → tạo bill mới
                $bill = Bill::create([
                    'date' => now(),
                    'customer_id' => $contract->customer_id,
                    'total_money' => 0,
                    'debt_amount' => 0,
                    'deposit_amount' => 0,
                    'status' => 'debt',
                ]);
                $contract->bill_id = $bill->id;
                $contract->save();
            }

            /** Tính total mới */
            $newTotal = ($validated['total_cost'] ?? $contract->total_cost) *
                ($validated['customer_rate'] ?? $contract->customer_rate);

            /** Cập nhật total bill (trừ old, cộng new) */
            $bill->total_money = $bill->total_money - $oldTotal + $newTotal;

            /**
             * Thay vì: $bill->debt_amount -= $oldTotal;
             * và $bill->deposit_amount -= $oldCustomerPaid;
             *
             * Ta dùng logic an toàn: lấy giá trị gốc từ bill, trừ oldTotal vào debt trước,
             * nếu debt không đủ thì phần dư trừ vào deposit.
             */
            $originalDebt = $bill->debt_amount;
            $originalDeposit = $bill->deposit_amount;

            // Remove oldTotal from bill: reduce debt first, then deposit if cần
            if ($originalDebt >= $oldTotal) {
                $bill->debt_amount = $originalDebt - $oldTotal;
                // deposit giữ nguyên
                $bill->deposit_amount = $originalDeposit;
            } else {
                // debt không đủ → debt becomes 0, phần dư trừ vào deposit
                $remaining = $oldTotal - $originalDebt; // phần phải trừ từ deposit
                $bill->debt_amount = 0;
                $bill->deposit_amount = max(0, $originalDeposit - $remaining);
            }

            /**
             * Bây giờ áp dụng customer_actually_paid mới (newPaid).
             * Ý tưởng: newPaid là số tiền khách báo đã trả cho hợp đồng này (cọc).
             * Ta cần cập nhật payment tương ứng (create/update/delete) và cập nhật debt/deposit dựa trên newTotal và tổng newPaid.
             */
            $newPaid = $validated['customer_actually_paid'] ?? null;

            if ($newPaid !== null && $newPaid > 0) {
                // Ta sẽ tính lại dựa trên newTotal và newPaid (không dùng oldPayment trực tiếp để trừ nữa)
                $diff = $newPaid - $newTotal;

                if ($diff > 0) {
                    // trả nhiều hơn -> không nợ, phần thừa thành deposit (lưu vào bill->deposit_amount)
                    $bill->debt_amount = 0;
                    // deposit có thể cộng thêm phần thừa
                    // Lưu ý: hiện tại bill->deposit_amount là giá trị sau khi đã trừ oldTotal,
                    // nên ta cộng thêm phần thừa newDiff
                    $bill->deposit_amount = ($bill->deposit_amount ?? 0) + $diff;
                } elseif ($diff < 0) {
                    // trả ít -> còn nợ
                    $bill->debt_amount = abs($diff); // newTotal - newPaid
                    $bill->deposit_amount = 0;
                } else {
                    // bằng đúng
                    $bill->debt_amount = 0;
                    $bill->deposit_amount = 0;
                }

                // cập nhật hoặc tạo payment is_deposit
                if ($oldPayment) {
                    // nếu đã có payment cũ thì update amount (ghi chú)
                    $oldPayment->amount = $newPaid;
                    $oldPayment->note = "có cọc. Khách đặt cọc {$newPaid}";
                    $oldPayment->date = now();
                    $oldPayment->save();
                } else {
                    // tạo payment mới
                    Payment::create([
                        'bill_id' => $bill->id,
                        'date' => now(),
                        'amount' => $newPaid,
                        'note' => "có cọc. Khách đặt cọc {$newPaid}",
                        'is_deposit' => 1
                    ]);
                }
            } else {
                // newPaid === null hoặc 0 => khách không trả cọc mới cho hợp đồng này
                // Ta đặt debt = newTotal (toàn bộ thành nợ) và giữ/ xoá payment cũ
                $bill->debt_amount = $newTotal;
                $bill->deposit_amount = 0;

                if ($oldPayment) {
                    $oldPayment->delete();
                }
            }

            /** Cập nhật trạng thái bill */
            $updateStatus($bill);
            $bill->save();

            /** Cập nhật contract */
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
        DB::beginTransaction();

        try {
            // 1. Lấy contract
            $contract = Contract::findOrFail($id);
            $bill = $contract->bill;

            if (!$bill) {
                return response()->json(['message' => 'Bill not found'], 404);
            }

            // 2. Tính tiền doanh thu cần trừ
            $subtractMoney = $contract->total_cost * $contract->customer_rate;

            // 3. Lấy payment cọc (nếu có) → theo is_deposit
            $depositPayment = Payment::where('bill_id', $bill->id)
                ->where('is_deposit', 1)
                ->first();

            $oldCustomerPaid = $depositPayment ? $depositPayment->amount : 0;

            // =======================================
            // 4. Cập nhật bill
            // =======================================
            $bill->total_money -= $subtractMoney;
            $bill->debt_amount -= $subtractMoney;

            // Không âm
            $bill->total_money = max(0, $bill->total_money);
            $bill->debt_amount = max(0, $bill->debt_amount);

            // Trừ tiền cọc nếu có
            if ($oldCustomerPaid > 0) {
                $bill->deposit_amount -= $oldCustomerPaid;
                $bill->deposit_amount = max(0, $bill->deposit_amount);
            }

            // Xóa payment cọc
            if ($depositPayment) {
                $depositPayment->delete();
            }

            // Cập nhật lại status bill
            if ($bill->deposit_amount > 0) {
                $bill->status = 'deposit';
            } else if ($bill->debt_amount <= 0) {
                $bill->status = 'completed';
            } else {
                $bill->status = 'debt';
            }

            $bill->save();

            // =======================================
            // 5. Xóa contract
            // =======================================
            $contract->delete();

            // =======================================
            // 6. Nếu bill không còn contract → xóa luôn bill
            // =======================================
            if ($bill->contracts()->count() == 0) {
                Payment::where('bill_id', $bill->id)->delete(); // xoá mọi payment còn sót
                $bill->delete();
            }

            DB::commit();
            return response()->json(['message' => 'Deleted successfully']);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
