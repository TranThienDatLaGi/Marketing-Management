<?php

use App\Http\Controllers\AuthController;
use App\Models\User;
use App\Notifications\VerifyEmailCustom;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/verify-test', function () {
    $user = User::first();
    $user->sendEmailVerificationNotification();
    return "done";
});
Route::get('/noti-test', function () {
    $user = User::first();
    sendVerifyEmail($user);
    return "OK";
});
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware('signed')
    ->name('verification.verify');