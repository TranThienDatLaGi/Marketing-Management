<?php

namespace App\Http\Controllers;

use App\Models\AccountType;
use Illuminate\Http\Request;

class AccountTypeController extends Controller
{
    public function index()
    {
        return AccountType::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required',
            'description' => 'nullable',
            'note' => 'nullable'
        ]);

        return AccountType::create($validated);
    }

    public function show($id)
    {
        return AccountType::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required',
            'description' => 'nullable',
            'note' => 'nullable'
        ]);

        $accountType = AccountType::findOrFail($id);
        $accountType->update($validated);
        return $accountType;
    }


    public function destroy($id)
    {
        AccountType::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
}
