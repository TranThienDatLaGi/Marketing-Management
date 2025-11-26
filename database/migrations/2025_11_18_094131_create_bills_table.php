<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');

            $table->decimal('total_money', 15, 2)->default(0);      // Tổng tiền hóa đơn
            $table->decimal('deposit_amount', 15, 2)->default(0);   // Tiền đặt cọc
            $table->decimal('debt_amount', 15, 2)->default(0);      // Tiền còn nợ

            $table->enum('status', ['debt', 'deposit', 'completed'])->default('debt');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
