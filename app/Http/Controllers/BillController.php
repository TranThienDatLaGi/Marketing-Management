<?php

namespace App\Http\Controllers;

use App\Http\Resources\BillResource;
use App\Models\Bill;
use Illuminate\Http\Request;

class BillController extends Controller
{

    public function update(Request $request, $id)
    {
        $bill = Bill::findOrFail($id);

        // Validate chỉ cho phép deposit_amount và note
        $validated = $request->validate([
            'deposit_amount' => 'required|numeric|min:0',
            'note'           => 'nullable|string',
        ]);

        // Update dữ liệu
        $bill->update($validated);

        // Load quan hệ
        $bill->load(['customer', 'payments']);

        // Trả về resource
        return new BillResource($bill);
    }

    public function destroy($id)
    {
        Bill::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
    public function filteredBill(Request $request)
    {
        $query = Bill::query()
            ->with(['customer', 'payments'])
            ->orderBy('date', 'desc'); // Sắp xếp theo ngày mới nhất

        // Lọc theo khách hàng
        if ($request->customer_id) {
            $query->where('customer_id', $request->customer_id);
        }

        // Lọc theo trạng thái
        if ($request->status) {
            $query->where('status', $request->status);
        }

        // Lọc theo ngày
        if ($request->from_date) {
            $query->whereDate('date', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->whereDate('date', '<=', $request->to_date);
        }

        // Phân trang
        $bills = $query->paginate($request->per_page ?? 15);

        // Trả về resource + thông tin pagination
        return response()->json([
            'data' => BillResource::collection($bills),
            'pagination' => [
                'current_page' => $bills->currentPage(),
                'last_page'    => $bills->lastPage(),
                'per_page'     => $bills->perPage(),
                'total'        => $bills->total(),
            ]
        ]);
    }
}
