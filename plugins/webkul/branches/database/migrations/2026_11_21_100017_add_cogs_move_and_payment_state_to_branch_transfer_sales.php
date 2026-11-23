<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** COGS Move link + payment state on the transfer document (Phase 4). */
    public function up(): void
    {
        Schema::table('branch_transfer_sales', function (Blueprint $table) {
            $table->unsignedBigInteger('cogs_move_id')->nullable()->after('in_invoice_id');
            $table->string('payment_state')->nullable()->default('not_paid')->after('amount_residual');
        });
    }

    public function down(): void
    {
        Schema::table('branch_transfer_sales', function (Blueprint $table) {
            $table->dropColumn(['cogs_move_id', 'payment_state']);
        });
    }
};
