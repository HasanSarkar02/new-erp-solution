<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FIFO cost layers (plan §4.3, §6).
     *
     * One row per batch of stock at a branch. The layer is the cost
     * truth: quantity_remaining × unit_cost is the stock value, and
     * transfer-out consumes layers oldest-first (incoming_at ASC).
     * Layers for stock that already exists (receipts before Phase 3)
     * are backfilled as 'opening' layers at the product cost.
     */
    public function up(): void
    {
        Schema::create('stock_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('inventories_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventories_locations')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products_products')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventories_lots')->nullOnDelete();
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('quantity_initial', 15, 4);
            $table->decimal('quantity_remaining', 15, 4);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('original_cost_unit', 15, 4)->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->dateTime('incoming_at');
            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'product_id', 'incoming_at']);
            $table->index(['product_id', 'quantity_remaining']);
            $table->index(['branch_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_layers');
    }
};
