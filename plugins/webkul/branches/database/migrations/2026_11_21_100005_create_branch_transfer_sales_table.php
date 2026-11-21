<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Internal Transfer Sale document (plan §4.3, Phase 2).
     *
     * The commercial document. Stock movement (Phase 3) and accounting
     * (Phase 4) attach to it via operation_id / out_invoice_id /
     * in_invoice_id, which stay NULL until those phases run.
     */
    public function up(): void
    {
        Schema::create('branch_transfer_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('source_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('destination_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('source_warehouse_id')->nullable()->constrained('inventories_warehouses')->nullOnDelete();
            $table->foreignId('destination_warehouse_id')->nullable()->constrained('inventories_warehouses')->nullOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('inventories_operations')->nullOnDelete();
            $table->foreignId('out_invoice_id')->nullable()->constrained('accounts_account_moves')->nullOnDelete();
            $table->foreignId('in_invoice_id')->nullable()->constrained('accounts_account_moves')->nullOnDelete();
            $table->foreignId('parent_transfer_id')->nullable()->constrained('branch_transfer_sales')->nullOnDelete();
            $table->string('name')->unique();
            $table->string('reference')->nullable();
            $table->string('type', 20)->default('supply');
            $table->string('status', 30)->default('draft');
            $table->date('transfer_date')->nullable();
            $table->date('expected_date')->nullable();
            $table->dateTime('received_date')->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('amount_untaxed', 15, 4)->default(0);
            $table->decimal('amount_total', 15, 4)->default(0);
            $table->decimal('amount_cost_total', 15, 4)->default(0);
            $table->decimal('amount_paid', 15, 4)->default(0);
            $table->decimal('amount_residual', 15, 4)->default(0);
            $table->unsignedInteger('payment_terms_days')->default(30);
            $table->date('due_date')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('source_branch_id');
            $table->index('destination_branch_id');
            $table->index('operation_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_transfer_sales');
    }
};
