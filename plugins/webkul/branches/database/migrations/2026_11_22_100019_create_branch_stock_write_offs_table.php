<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5: lost / missing stock write-offs (addendum A3).
     *
     * A write-off is its own document: goods that disappeared at a
     * Sub branch, an investigation note, Main approval, and the loss
     * posted at stock value P (the Sub still owes Main unless Main
     * formally forgives the debt with a credit note).
     *
     * stock_layer_consumptions.write_off_line_id links the FIFO
     * consumption of the lost stock to the write-off line that
     * caused it (transfer_line_id keeps pointing at the original
     * transfer line the goods came from).
     */
    public function up(): void
    {
        Schema::create('branch_stock_write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('transfer_id')->nullable()->constrained('branch_transfer_sales')->nullOnDelete();
            $table->string('name')->unique();
            $table->string('status')->default('draft');
            $table->text('investigation_note')->nullable();
            $table->decimal('amount_total', 15, 4)->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('forgiven_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('forgiven_at')->nullable();
            $table->unsignedBigInteger('account_move_id')->nullable();
            $table->unsignedBigInteger('forgiveness_move_id')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('branch_stock_write_off_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('write_off_id')->constrained('branch_stock_write_offs')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products_products')->restrictOnDelete();
            $table->foreignId('transfer_line_id')->nullable()->constrained('branch_transfer_sale_lines')->nullOnDelete();
            $table->foreignId('inventory_scrap_id')->nullable()->constrained('inventories_scraps')->nullOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_value', 15, 4);
            $table->decimal('loss_subtotal', 15, 4);
            $table->timestamps();
        });

        Schema::table('stock_layer_consumptions', function (Blueprint $table) {
            $table->foreignId('write_off_line_id')->nullable()->after('transfer_line_id')
                ->constrained('branch_stock_write_off_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_layer_consumptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('write_off_line_id');
        });

        Schema::dropIfExists('branch_stock_write_off_lines');
        Schema::dropIfExists('branch_stock_write_offs');
    }
};
