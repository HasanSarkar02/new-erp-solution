<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branch dimension + internal flag on accounting Moves and Move
     * lines (plan §5.5). Additive only; existing rows keep
     * branch_id NULL / is_internal false.
     */
    public function up(): void
    {
        Schema::table('accounts_account_moves', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('company_id');
            $table->boolean('is_internal')->default(false)->after('branch_id');
            $table->unsignedBigInteger('branch_transfer_sale_id')->nullable()->after('is_internal');

            $table->index('branch_id');
            $table->index('branch_transfer_sale_id');
        });

        Schema::table('accounts_account_move_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('company_id');
            $table->boolean('is_internal')->default(false)->after('branch_id');
            $table->unsignedBigInteger('branch_transfer_sale_id')->nullable()->after('is_internal');

            $table->index('branch_id');
            $table->index('branch_transfer_sale_id');
        });
    }

    public function down(): void
    {
        Schema::table('accounts_account_moves', function (Blueprint $table) {
            $table->dropIndex(['branch_id']);
            $table->dropIndex(['branch_transfer_sale_id']);
            $table->dropColumn(['branch_id', 'is_internal', 'branch_transfer_sale_id']);
        });

        Schema::table('accounts_account_move_lines', function (Blueprint $table) {
            $table->dropIndex(['branch_id']);
            $table->dropIndex(['branch_transfer_sale_id']);
            $table->dropColumn(['branch_id', 'is_internal', 'branch_transfer_sale_id']);
        });
    }
};
