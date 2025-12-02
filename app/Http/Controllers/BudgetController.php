<?php

namespace App\Http\Controllers;

use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BudgetController extends Controller
{
    public function index()
    {
        return Budget::with(['supplier', 'accountType'])->get();
    }
    public function getBudgetBySupplier(Request $request, $supplierId)
    {
        $query = Budget::with('accountType')
            ->where('supplier_id', $supplierId);

        // ===== FILTER =====
        if ($request->filled('status') && $request->status !== "all") {
            $query->where('status', $request->status);
        }

        if ($request->filled('product_type') && $request->product_type !== "all") {
            $query->where('product_type', $request->product_type);
        }

        // ===== SORT =====
        $allowedSort = ['money', 'supplier_rate', 'customer_rate', 'date'];
        $sortBy = $request->get('sort_by', 'date');
        $sortOrder = $request->get('sort_order', 'desc');

        if (in_array($sortBy, $allowedSort)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // ===== PAGINATION =====
        $limit = $request->get('limit', 10);
        $page  = $request->get('page', 1);
        $result = $query->paginate($limit, ['*'], 'page', $page);

        // ===== RETURN RESOURCE WITH PAGINATION =====
        return BudgetResource::collection($result);
    }
    public function store(Request $request)
    {
        // ===== VALIDATION =====
        $validated = $request->validate([
            'supplier_id'      => 'required|exists:suppliers,id',
            'account_type_id'  => 'required|exists:account_types,id',
            'money'            => 'required|numeric',
            'date'             => 'required|date',
            'product_type'     => 'nullable|in:legal,illegal,middle-illegal',
            'supplier_rate'    => 'nullable|numeric',
            'customer_rate'    => 'nullable|numeric',
            'status'           => 'nullable|string',
            'note'             => 'nullable|string',
        ]);

        // Set default
        $validated['product_type']  = $validated['product_type']  ?? 'legal';
        $validated['supplier_rate'] = $validated['supplier_rate'] ?? 0;
        $validated['customer_rate'] = $validated['customer_rate'] ?? 0;
        $validated['status']        = $validated['status']        ?? 'active';

        // ===== CREATE =====
        $budget = Budget::create($validated);

        // Load relation để có account_type_name
        $budget->load('accountType');

        // ===== RETURN RESOURCE =====
        return new BudgetResource($budget);
    }
    public function update(Request $request, $id)
    {
        $budget = Budget::findOrFail($id);

        // ===== VALIDATION =====
        $validated = $request->validate([
            'supplier_id'      => 'nullable|exists:suppliers,id',
            'account_type_id'  => 'nullable|exists:account_types,id',
            'money'            => 'nullable|numeric',
            'date'             => 'required|date',
            'product_type'     => 'nullable|in:legal,illegal,middle-illegal',
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
        return new BudgetResource($budget);
    }
    public function destroy($id)
    {
        Budget::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
    public function getBudgetContract()
    {
        $budgets = Budget::query()
            ->where('status', 'active')
            ->with([
                'accountType:id,name',
                'supplier:id,name' // thêm supplier
            ])
            ->withSum('contracts as used_budget', 'total_cost')
            ->get();

        // Chuẩn hóa lại dữ liệu trả về
        $result = $budgets->map(function ($budget) {
            return [
                'id'                => $budget->id,
                'account_type_name' => $budget->accountType->name ?? null,
                'supplier_name'     => $budget->supplier->name ?? null, // thêm supplier_name
                'budget_money'      => $budget->money,
                'customer_rate'     => $budget->customer_rate,
                'supplier_rate'     => $budget->supplier_rate,
                'used_budget'       => $budget->used_budget ?? 0,
            ];
        });

        return response()->json([
            'data' => $result
        ]);
    }
}
