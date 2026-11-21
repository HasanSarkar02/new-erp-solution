<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Traceability for FIFO consumption (plan §6.3 rule 3).
     *
     * Every unit consumed at ship time records exactly which layer it
     * came from, so a transfer line's cost_price_unit is auditable
     * back to the receipt batches.
     */
    public function up(): void
    {
        Schema::create('stock_layer_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layer_id')->constrained('stock_layers')->restrictOnDelete();
            $table->foreignId('transfer_line_id')->constrained('branch_transfer_sale_lines')->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4);
            $table->timestamps();

            $table->index('transfer_line_id');
            $table->index('layer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_layer_consumptions');
    }
};
