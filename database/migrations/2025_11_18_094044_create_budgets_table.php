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
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('cascade');
            $table->foreignId('account_type_id')->constrained('account_types')->onDelete('cascade');
            $table->decimal('money', 15, 2)->default(0);
            $table->enum('product_type',['legal','illegal'])->default('legal');
            $table->decimal('supplier_rate', 10, 2)->default(0);
            $table->decimal('customer_rate', 10, 2)->default(0);
            $table->string('status')->default('active');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
