<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Allocations of one inter-branch payment across transfers (oldest first by default). */
    public function up(): void
    {
        Schema::create('inter_branch_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('transfer_id');
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            $table->index('payment_id');
            $table->index('transfer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_branch_payment_allocations');
    }
};
