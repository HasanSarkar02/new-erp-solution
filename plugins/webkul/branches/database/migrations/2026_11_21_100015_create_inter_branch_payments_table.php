<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One inter-branch payment (plan §5.2c): a commercial settlement
     * recorded by Main. It always creates TWO linked Payment legs
     * (Sub side + Main side) via paired_internal_transfer_payment_id;
     * the legs are the accounting truth, this row is the document.
     */
    public function up(): void
    {
        Schema::create('inter_branch_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('payer_branch_id')->comment('Sub branch that pays (debtor)');
            $table->unsignedBigInteger('payee_branch_id')->comment('Main branch that receives (creditor)');
            $table->unsignedBigInteger('journal_id')->comment('Bank/Cash journal the money moves through');
            $table->unsignedBigInteger('currency_id');
            $table->unsignedBigInteger('sub_payment_id')->nullable()->comment('Payment leg, Sub side (outbound)');
            $table->unsignedBigInteger('main_payment_id')->nullable()->comment('Payment leg, Main side (inbound)');
            $table->string('reference')->nullable();
            $table->string('memo')->nullable();
            $table->date('payment_date');
            $table->decimal('amount', 15, 4);
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'payer_branch_id']);
            $table->index(['company_id', 'payee_branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_branch_payments');
    }
};
