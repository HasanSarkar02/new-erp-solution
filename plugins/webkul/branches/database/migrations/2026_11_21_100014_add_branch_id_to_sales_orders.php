<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Branch dimension on sales orders (plan §5.5). Guarded: the sales plugin may not be installed. */
    public function up(): void
    {
        if (! Schema::hasTable('sales_orders') || Schema::hasColumn('sales_orders', 'branch_id')) {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('company_id');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_orders') || ! Schema::hasColumn('sales_orders', 'branch_id')) {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
