<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branch dimension on the inventory engine tables (plan §4.4).
     *
     * Additive columns only — no core plugin files are touched. The
     * columns link a stock Operation / Move back to its transfer
     * document and the branch whose stock it affects.
     */
    public function up(): void
    {
        Schema::table('inventories_operations', function (Blueprint $table) {
            if (! Schema::hasColumn('inventories_operations', 'branch_transfer_sale_id')) {
                $table->foreignId('branch_transfer_sale_id')->nullable()
                    ->constrained('branch_transfer_sales')->nullOnDelete();
            }

            if (! Schema::hasColumn('inventories_operations', 'source_branch_id')) {
                $table->foreignId('source_branch_id')->nullable()
                    ->constrained('branches')->nullOnDelete();
            }

            if (! Schema::hasColumn('inventories_operations', 'destination_branch_id')) {
                $table->foreignId('destination_branch_id')->nullable()
                    ->constrained('branches')->nullOnDelete();
            }
        });

        Schema::table('inventories_moves', function (Blueprint $table) {
            if (! Schema::hasColumn('inventories_moves', 'branch_transfer_sale_id')) {
                $table->foreignId('branch_transfer_sale_id')->nullable()
                    ->constrained('branch_transfer_sales')->nullOnDelete();
            }

            if (! Schema::hasColumn('inventories_moves', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()
                    ->constrained('branches')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventories_moves', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
            $table->dropForeign(['branch_transfer_sale_id']);
            $table->dropColumn('branch_transfer_sale_id');
        });

        Schema::table('inventories_operations', function (Blueprint $table) {
            $table->dropForeign(['destination_branch_id']);
            $table->dropColumn('destination_branch_id');
            $table->dropForeign(['source_branch_id']);
            $table->dropColumn('source_branch_id');
            $table->dropForeign(['branch_transfer_sale_id']);
            $table->dropColumn('branch_transfer_sale_id');
        });
    }
};
