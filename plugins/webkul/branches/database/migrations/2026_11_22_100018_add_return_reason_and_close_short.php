<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5: damaged returns + close-short (plan §9 Phase 5, addendum A3).
     *
     * - branch_transfer_sales.return_reason: 'unsold' (default for
     *   return transfers) or 'damaged' — a damaged return credits
     *   only the agreed value, the rest is an Inventory Damage Loss.
     * - branch_transfer_sales.is_closed_short / closed_short_at:
     *   a transfer whose unshipped remainder was closed without
     *   delivery (the goods stay at the source branch).
     * - branch_transfer_sale_lines.credit_value_unit: the agreed
     *   credit per unit on a damaged return line (null = full P).
     */
    public function up(): void
    {
        Schema::table('branch_transfer_sales', function (Blueprint $table) {
            $table->string('return_reason')->nullable()->after('type');
            $table->boolean('is_closed_short')->default(false)->after('status');
            $table->timestamp('closed_short_at')->nullable()->after('is_closed_short');
        });

        Schema::table('branch_transfer_sale_lines', function (Blueprint $table) {
            $table->decimal('credit_value_unit', 15, 4)->nullable()->after('transfer_price_unit');
        });
    }

    public function down(): void
    {
        Schema::table('branch_transfer_sale_lines', function (Blueprint $table) {
            $table->dropColumn('credit_value_unit');
        });

        Schema::table('branch_transfer_sales', function (Blueprint $table) {
            $table->dropColumn(['return_reason', 'is_closed_short', 'closed_short_at']);
        });
    }
};
