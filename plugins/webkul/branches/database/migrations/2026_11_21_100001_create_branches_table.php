<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->foreignId('partner_id')
                ->nullable()
                ->constrained('partners_partners')
                ->restrictOnDelete();

            $table->foreignId('warehouse_id')
                ->nullable()
                ->constrained('inventories_warehouses')
                ->nullOnDelete();

            $table->foreignId('manager_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('code', 20);
            $table->string('name', 255);
            $table->string('type', 20);
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_demo')->default(false);

            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            $table->string('street1')->nullable();
            $table->string('street2')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->foreignId('state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();

            $table->decimal('opening_balance', 15, 2)->default(0);

            $table->foreignId('creator_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /**
             * Database-level enforcement of "exactly one Main branch per
             * company" (plan §4.1). The generated value is non-null only
             * for Main branches, so the unique index allows any number of
             * non-Main branches but at most one Main per company.
             */
            $table->string('main_unique_key', 64)->nullable()
                ->storedAs("CASE WHEN is_main THEN CONCAT('main-', company_id) ELSE NULL END");

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'name']);
            $table->unique('main_unique_key');
            $table->index(['company_id', 'type']);
            $table->index('warehouse_id');
            $table->index('partner_id');

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
