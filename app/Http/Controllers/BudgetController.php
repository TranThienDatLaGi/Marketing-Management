<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    public function index()
    {
        return Budget::with(['supplier', 'accountType'])->get();
    }
    public function getBudgetBySupplier(Request $request, $supplierId)
    {
        $query = Budget::with('accountType') // <-- load quan hệ
            ->where('supplier_id', $supplierId);

        // ===== FILTER =====

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('product_type')) {
            $query->where('product_type', $request->product_type);
        }

        // ===== SORT =====

        $allowedSort = ['money', 'supplier_rate', 'customer_rate', 'created_at'];

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        if (in_array($sortBy, $allowedSort)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // ===== PAGINATION =====

        $limit = $request->get('limit', 10);
        $page  = $request->get('page', 1);

        $result = $query->paginate($limit, ['*'], 'page', $page);

        // ===== APPEND account_type_name =====
        $result->getCollection()->transform(function ($item) {
            $item->account_type_name = $item->accountType->name ?? null;
            unset($item->accountType); // không trả về object quan hệ
            return $item;
        });

        return response()->json($result);
    }

    public function store(Request $request)
    {
        // ===== VALIDATION =====
        $validated = $request->validate([
            'supplier_id'      => 'required|exists:suppliers,id',
            'account_type_id'  => 'required|exists:account_types,id',
            'money'            => 'required|numeric',
            'product_type'     => 'nullable|in:legal,illegal',
            'supplier_rate'    => 'nullable|numeric',
            'customer_rate'    => 'nullable|numeric',
            'status'           => 'nullable|string',
            'note'             => 'nullable|string',
        ]);

        // Set default nếu client không gửi
        $validated['product_type']  = $validated['product_type']  ?? 'legal';
        $validated['supplier_rate'] = $validated['supplier_rate'] ?? 0;
        $validated['customer_rate'] = $validated['customer_rate'] ?? 0;
        $validated['status']        = $validated['status']        ?? 'active';

        // ===== CREATE =====
        $budget = Budget::create($validated);

        // ===== LOAD RELATION TO GET account_type_name =====
        $budget->load('accountType');

        // ===== FORMAT RESPONSE =====
        return response()->json([
            'id'                => $budget->id,
            'supplier_id'       => $budget->supplier_id,
            'account_type_id'   => $budget->account_type_id,
            'account_type_name' => $budget->accountType->name ?? null,
            'money'             => $budget->money,
            'product_type'      => $budget->product_type,
            'supplier_rate'     => $budget->supplier_rate,
            'customer_rate'     => $budget->customer_rate,
            'status'            => $budget->status,
            'note'              => $budget->note,
            'created_at'        => $budget->created_at,
            'updated_at'        => $budget->updated_at,
        ]);
    }


    public function update(Request $request, $id)
    {
        $budget = Budget::findOrFail($id);

        // ===== VALIDATION =====
        $validated = $request->validate([
            'supplier_id'      => 'nullable|exists:suppliers,id',
            'account_type_id'  => 'nullable|exists:account_types,id',
            'money'            => 'nullable|numeric',
            'product_type'     => 'nullable|in:legal,illegal',
            'supplier_rate'    => 'nullable|numeric',
            'customer_rate'    => 'nullable|numeric',
            'status'           => 'nullable|string',
            'note'             => 'nullable|string',
        ]);

        // ===== UPDATE =====
        $budget->update($validated);

        // ===== LOAD RELATION TO GET account_type_name =====
        $budget->load('accountType');

        // ===== FORMAT RESPONSE =====
        return response()->json([
            'id'                => $budget->id,
            'supplier_id'       => $budget->supplier_id,
            'account_type_id'   => $budget->account_type_id,
            'account_type_name' => $budget->accountType->name ?? null,
            'money'             => $budget->money,
            'product_type'      => $budget->product_type,
            'supplier_rate'     => $budget->supplier_rate,
            'customer_rate'     => $budget->customer_rate,
            'status'            => $budget->status,
            'note'              => $budget->note,
            'created_at'        => $budget->created_at,
            'updated_at'        => $budget->updated_at,
        ]);
    }


    public function destroy($id)
    {
        Budget::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
}
