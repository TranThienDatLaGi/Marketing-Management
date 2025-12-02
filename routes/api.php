<?php

use App\Http\Controllers\AccountTypeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SupplierController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::post('/forgot-password', [AuthController::class, 'sendResetLink']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/getListUser', [AuthController::class, 'getListUser']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::post('/update-user', [AuthController::class, 'updateUser']);
    Route::post('/check-password', [AuthController::class, 'checkPassword']);

    Route::apiResource('account-type', AccountTypeController::class);
    Route::apiResource("customer", CustomerController::class);
    Route::get('/budgets-by-supplier/{supplierId}', [BudgetController::class, 'getBudgetBySupplier']);
    Route::apiResource('supplier', SupplierController::class);
    Route::apiResource('budgets', BudgetController::class)->except(['show']);
    Route::get('/budget-contract',[BudgetController::class, 'getBudgetContract']);
    Route::apiResource('contracts', ContractController::class)->only(['store', 'update', 'destroy']);
    Route::get('contracts/filtered', [ContractController::class, 'filteredContract']);
    
    Route::get('/bills/filter', [BillController::class, 'filteredBill']);
    Route::apiResource('bils', BillController::class)->only(['update', 'destroy']);
    
    Route::get('/payments-by-bill/{billId}', [PaymentController::class, 'getPaymentByBill']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::put('/payments/{id}', [PaymentController::class, 'update']);
    Route::delete('/payments/{id}', [PaymentController::class, 'destroy']);
    
    Route::prefix('overview')->group(function () {
        Route::get('/customer/{customerId}/{period}', [OverviewController::class, 'getOverviewCustomer']);
        Route::get('/supplier/{supplierId}/{period}', [OverviewController::class, 'getOverviewSupplier']);
    });
    Route::get('/dashboard/{type}/{value}', [OverviewController::class, 'getDashboard']);
});
Route::post('/send-verify-email', function (Request $request) {
    Log::info("SEND_VERIFY_EMAIL CALLED", ['email' => $request->email]);
    $request->validate(['email' => 'required|email']);
    return sendVerifyEmail($request->email);
});
