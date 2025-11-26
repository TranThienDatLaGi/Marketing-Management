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
        Schema::create('overviews', function (Blueprint $table) {
            $table->id();
            $table->enum('type',['customer','supplier'])->default('customer');
            $table->unsignedBigInteger('target_id');  // Customer / Supplier tuỳ theo type
            $table->string('period');                 // YYYY-MM
            $table->json('data');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overviews');
    }
};
