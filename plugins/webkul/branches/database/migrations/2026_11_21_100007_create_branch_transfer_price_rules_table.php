<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transfer price rules — the "price list" for internal transfers
     * (plan §4.3). Resolution order is enforced in
     * BranchTransferPriceService: product + destination branch >
     * product > category + destination branch > category >
     * cost x (1 + default markup) > cost.
     */
    public function up(): void
    {
        Schema::create('branch_transfer_price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('destination_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products_products')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('products_categories')->nullOnDelete();
            $table->decimal('transfer_price', 15, 4)->nullable();
            $table->decimal('markup_percent', 5, 2)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'product_id']);
            $table->index(['company_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_transfer_price_rules');
    }
};
