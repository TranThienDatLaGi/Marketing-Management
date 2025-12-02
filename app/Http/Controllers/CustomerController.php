<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index()
    {
        return Customer::select(
            'customers.*',
            'account_types.name as account_type_name'
        )
            ->leftJoin('account_types', 'account_types.id', '=', 'customers.account_type_id')
            ->get();
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'zalo' => 'nullable|string|max:255',
            'facebook' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'product_type' => 'nullable|in:legal,illegal,middle-illegal', // có default là legal
            'account_type_id' => 'required|exists:account_types,id',
            'note' => 'nullable|string',
            'rate' => 'nullable|numeric|min:0',

        ]);

        // Nếu product_type không được gửi, mặc định 'legal'
        if (!isset($validated['product_type'])) {
            $validated['product_type'] = 'legal';
        }

        // Nếu rate không được gửi, mặc định 0
        if (!isset($validated['rate'])) {
            $validated['rate'] = 0;
        }

        $customer = Customer::create($validated);
        $customer->refresh();

        // Lấy thêm tên loại tài khoản
        $customer->account_type_name = $customer->accountType->name ?? null;

        // Không trả về object accountType nữa
        unset($customer->accountType);

        return $customer;
    }

    public function show($id)
    {
        return Customer::select(
            'customers.*',
            'account_types.name as account_type_name'
        )
            ->leftJoin('account_types', 'account_types.id', '=', 'customers.account_type_id')
            ->where('customers.id', $id)
            ->firstOrFail();
    }


    public function update(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        $customer->update($request->all());
        $customer->refresh();
        // Lấy lại dữ liệu sau khi update
        $updated = Customer::select(
            'customers.*',
            'account_types.name as account_type_name'
        )
            ->leftJoin('account_types', 'account_types.id', '=', 'customers.account_type_id')
            ->where('customers.id', $id)
            ->first();

        return $updated;
    }


    public function destroy($id)
    {
        Customer::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
}
