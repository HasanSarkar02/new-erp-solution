<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link a transfer to the receipt Operation that put its goods
     * into the destination branch (Phase 3).
     *
     * The outbound Operation is already linked via operation_id.
     * receipt_operation_id is updated on every receive and is what
     * the return flow uses to build the engine-level return
     * operation for the shipped goods.
     */
    public function up(): void
    {
        Schema::table('branch_transfer_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('branch_transfer_sales', 'receipt_operation_id')) {
                $table->foreignId('receipt_operation_id')->nullable()->after('operation_id')
                    ->constrained('inventories_operations')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('branch_transfer_sales', function (Blueprint $table) {
            $table->dropForeign(['receipt_operation_id']);
            $table->dropColumn('receipt_operation_id');
        });
    }
};
