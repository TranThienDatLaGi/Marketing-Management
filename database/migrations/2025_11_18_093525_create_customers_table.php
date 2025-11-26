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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('zalo')->nullable();
            $table->string('facebook')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('address')->nullable();
            $table->enum('product_type', ['legal', 'illegal'])->default('legal');
            $table->foreignId('account_type_id')->constrained()->onDelete('cascade');
            $table->text('note')->nullable();
            $table->decimal('rate', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
