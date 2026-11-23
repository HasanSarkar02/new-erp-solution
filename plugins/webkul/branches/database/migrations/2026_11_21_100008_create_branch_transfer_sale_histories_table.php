<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for every transfer status change (plan §7.5).
     * The Branch Profile "history" tab reads this table.
     */
    public function up(): void
    {
        Schema::create('branch_transfer_sale_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('branch_transfer_sales')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('transfer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_transfer_sale_histories');
    }
};
