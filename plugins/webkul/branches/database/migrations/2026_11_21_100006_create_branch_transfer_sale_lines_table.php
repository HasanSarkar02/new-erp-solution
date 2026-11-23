<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lines of an Internal Transfer Sale (plan §4.3).
     *
     * transfer_price_unit (P) is the custom internal price for the line.
     * cost_price_unit (C) is the FIFO unit cost, captured at ship time
     * in Phase 3 — it stays NULL while the transfer is a draft.
     */
    public function up(): void
    {
        Schema::create('branch_transfer_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('branch_transfer_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products_products')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventories_lots')->nullOnDelete();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('quantity_shipped', 15, 4)->default(0);
            $table->decimal('quantity_received', 15, 4)->default(0);
            $table->decimal('quantity_returned', 15, 4)->default(0);
            $table->foreignId('uom_id')->nullable()->constrained('unit_of_measures')->nullOnDelete();
            $table->decimal('transfer_price_unit', 15, 4)->default(0);
            $table->decimal('cost_price_unit', 15, 4)->nullable();
            $table->decimal('price_subtotal', 15, 4)->default(0);
            $table->decimal('cost_subtotal', 15, 4)->default(0);
            $table->foreignId('inventory_move_id')->nullable()->constrained('inventories_moves')->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index('transfer_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_transfer_sale_lines');
    }
};
