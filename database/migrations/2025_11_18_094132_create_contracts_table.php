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
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('budget_id')->constrained('budgets')->onDelete('cascade');

            // Thêm bill_id (1 Bill - N Contract)
            $table->foreignId('bill_id')->nullable()->constrained('bills')->onDelete('cascade');

            $table->string('product');
            $table->enum('product_type', ['legal', 'illegal', 'middle-illegal'])->default('legal');
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->decimal('supplier_rate', 10, 2)->default(0);
            $table->decimal('customer_rate', 10, 2)->default(0);
            $table->decimal('customer_actually_paid', 15, 2)->default(0);

            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
