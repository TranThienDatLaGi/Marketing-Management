<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Budget;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Overview;
use App\Models\Supplier;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OverviewController extends Controller
{
    public function getOverviewCustomer(Request $request)
    {
        $request->validate([
            'target_id' => 'required|exists:customers,id',
            'period'    => 'required|date_format:Y-m',
        ]);

        $customerId = $request->target_id;
        $period     = $request->period; // YYYY-MM

        // Kiểm tra customer tồn tại
        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        // Lọc contract của customer theo tháng
        $contracts = Contract::with(['budget.accountType', 'bill.payments'])
            ->where('customer_id', $customerId)
            ->whereYear('date', '=', date('Y', strtotime($period . '-01')))
            ->whereMonth('date', '=', date('m', strtotime($period . '-01')))
            ->get();

        if ($contracts->isEmpty()) {
            return response()->json(['error' => 'No contracts found for this customer in the given period'], 404);
        }

        // Tổng số lần chạy quảng cáo
        $total_runs = $contracts->count();

        // Số lần chạy theo account_type kèm tên
        $runs_by_account_type = [];
        foreach ($contracts as $contract) {
            $accountTypeId = $contract->budget->account_type_id ?? null;
            $accountTypeName = $contract->budget->accountType->name ?? null;

            if ($accountTypeId) {
                if (!isset($runs_by_account_type[$accountTypeId])) {
                    $runs_by_account_type[$accountTypeId] = [
                        'id' => $accountTypeId,
                        'name' => $accountTypeName,
                        'count' => 0
                    ];
                }
                $runs_by_account_type[$accountTypeId]['count']++;
            }
        }

        // Số lần chạy theo tên sản phẩm
        $runs_by_product = [];
        foreach ($contracts as $contract) {
            $productName = $contract->product ?? 'Unknown';
            if (!isset($runs_by_product[$productName])) {
                $runs_by_product[$productName] = 0;
            }
            $runs_by_product[$productName]++;
        }

        // Lấy tất cả bill liên quan để tính tổng tiền, nợ, thanh toán
        $billIds = $contracts->pluck('bill_id')->unique();
        $bills   = Bill::with('payments')->whereIn('id', $billIds)->get();

        $total_money = $bills->sum('total_money');
        $total_debt  = $bills->sum('debt_amount');
        $total_paid  = $bills->sum(function ($bill) {
            return $bill->payments->sum('amount');
        });

        // Dữ liệu để lưu vào overview
        $data = [
            'total_runs'           => $total_runs,
            'runs_by_account_type' => $runs_by_account_type,
            'runs_by_product'      => $runs_by_product,
            'total_money'          => $total_money,
            'total_debt'           => $total_debt,
            'total_paid'           => $total_paid,
        ];

        // Lưu hoặc cập nhật overview
        $overview = Overview::updateOrCreate(
            [
                'type'      => 'customer',
                'target_id' => $customerId,
                'period'    => $period
            ],
            [
                'data' => json_encode($data)
            ]
        );

        // Trả về kèm thông tin customer
        return response()->json([
            'overview' => $overview,
            'customer' => $customer
        ]);
    }
    public function getOverviewSupplier(Request $request)
    {
        $request->validate([
            'target_id' => 'required|exists:suppliers,id',
            'period'    => 'required|date_format:Y-m',
        ]);

        $supplierId = $request->target_id;
        $period     = $request->period; // YYYY-MM

        // Kiểm tra supplier tồn tại
        $supplier = Supplier::find($supplierId);
        if (!$supplier) {
            return response()->json(['error' => 'Supplier not found'], 404);
        }

        // Lọc budgets của supplier theo tháng
        $budgets = Budget::with('accountType')
            ->where('supplier_id', $supplierId)
            ->whereYear('created_at', '=', date('Y', strtotime($period . '-01')))
            ->whereMonth('created_at', '=', date('m', strtotime($period . '-01')))
            ->get();

        if ($budgets->isEmpty()) {
            return response()->json(['error' => 'No budgets found for this supplier in the given period'], 404);
        }

        // Tổng số budget và tổng tiền
        $total_budget_count = $budgets->count();
        $total_budget_money = $budgets->sum('money');

        // Tổng số tiền và số lượng theo account_type
        // Tổng số tiền và số lượng theo account_type
        $budget_by_account_type = [];
        foreach ($budgets as $budget) {
            $accountTypeId = $budget->account_type_id;
            $accountTypeName = $budget->accountType->name ?? null;

            if (!isset($budget_by_account_type[$accountTypeId])) {
                $budget_by_account_type[$accountTypeId] = [
                    'id' => $accountTypeId,
                    'name' => $accountTypeName,
                    'count' => 0,
                    'total_money' => 0
                ];
            }
            $budget_by_account_type[$accountTypeId]['count']++;
            $budget_by_account_type[$accountTypeId]['total_money'] += $budget->money;
        }


        // Lấy tất cả contract liên quan đến budgets này để tính tổng tiền phải trả cho supplier
        $budgetIds = $budgets->pluck('id');
        $total_payable = Contract::whereIn('budget_id', $budgetIds)
            ->sum(DB::raw('total_cost * supplier_rate'));

        // Dữ liệu để lưu vào overview
        $data = [
            'total_budget_count'      => $total_budget_count,
            'total_budget_money'      => $total_budget_money,
            'budget_by_account_type'  => $budget_by_account_type,
            'total_payable'           => $total_payable,
        ];

        // Lưu hoặc cập nhật overview
        $overview = Overview::updateOrCreate(
            [
                'type'      => 'supplier',
                'target_id' => $supplierId,
                'period'    => $period
            ],
            [
                'data' => json_encode($data)
            ]
        );

        // Trả về supplier + overview
        return response()->json([
            'overview' => $overview,
            'supplier' => $supplier
        ]);
    }
    public function getDashboard($type, $value)
    {
        // 1) Lấy khoảng thời gian (bao gồm kiểm tra type không hợp lệ)
        [$from, $to] = $this->resolvePeriod($type, $value);

        // 2) Lấy contract theo thời gian
        $contracts = Contract::with(['customer', 'budget.accountType'])
            ->whereBetween('date', [$from, $to])
            ->get();

        return response()->json([
            'period'           => compact('from', 'to'),
            'total_contracts'  => $contracts->count() ?? 0,
            'revenue'          => $this->calcRevenue($contracts),
            'profit'           => $this->calcProfit($contracts),
            'top_account_type' => $this->topAccountType($contracts),
            'account_types'    => $this->countAccountTypes($contracts),
            'products'         => $this->countProducts($contracts),
            'customers'        => $this->countCustomers($contracts),
        ]);
    }

    private function resolvePeriod($type, $value)
    {
        try {
            switch ($type) {
                case 'date':
                    $from = $value;
                    $to   = $value;
                    break;

                case 'week':
                    $from = Carbon::parse($value)->startOfWeek();
                    $to   = Carbon::parse($value)->endOfWeek();
                    break;

                case 'month':
                    $from = Carbon::parse($value . '-01')->startOfMonth();
                    $to   = Carbon::parse($value . '-01')->endOfMonth();
                    break;

                case 'year':
                    $from = Carbon::create($value)->startOfYear();
                    $to   = Carbon::create($value)->endOfYear();
                    break;

                default:
                    return [now(), now()]; // fallback an toàn
            }
        } catch (Exception $e) {
            return [now(), now()];
        }

        return [$from, $to];
    }

    private function calcRevenue($contracts)
    {
        if ($contracts->isEmpty()) return 0;

        return $contracts->sum(function ($c) {
            return ($c->total_cost ?? 0) * ($c->customer_rate ?? 0);
        });
    }

    private function calcProfit($contracts)
    {
        if ($contracts->isEmpty()) return 0;

        return $contracts->sum(function ($c) {
            $customer = ($c->total_cost ?? 0) * ($c->customer_rate ?? 0);
            $supplier = ($c->total_cost ?? 0) * ($c->supplier_rate ?? 0);
            return $customer - $supplier;
        });
    }

    private function topAccountType($contracts)
    {
        if ($contracts->isEmpty()) return null;

        return $contracts
            ->groupBy(fn($c) => $c->budget->accountType->name ?? 'Unknown')
            ->map->count()
            ->sortDesc()
            ->keys()
            ->first() ?? null;
    }

    private function countAccountTypes($contracts)
    {
        if ($contracts->isEmpty()) return [];

        return $contracts
            ->groupBy(fn($c) => $c->budget->accountType->name ?? 'Unknown')
            ->map(fn($group) => [
                'name'  => $group->first()->budget->accountType->name ?? 'Unknown',
                'count' => $group->count() ?? 0,
            ])->values();
    }

    private function countProducts($contracts)
    {
        if ($contracts->isEmpty()) return [];

        return $contracts
            ->groupBy('product')
            ->map(fn($group) => [
                'product' => $group->first()->product ?? 'Unknown',
                'count'   => $group->count() ?? 0,
            ])->values();
    }

    private function countCustomers($contracts)
    {
        if ($contracts->isEmpty()) return [];

        return $contracts
            ->groupBy('customer_id')
            ->map(fn($group) => [
                'customer_id'   => $group->first()->customer_id ?? null,
                'customer_name' => $group->first()->customer->name ?? null,
                'count'         => $group->count() ?? 0,
            ])->values();
    }
}
