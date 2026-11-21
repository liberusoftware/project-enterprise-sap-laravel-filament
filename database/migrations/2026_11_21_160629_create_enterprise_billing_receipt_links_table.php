<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('enterprise_billing_receipt_links', function (Blueprint $table) {
            $table->foreignId('allocation_id')->primary()->constrained('billing_payment_allocations')->restrictOnDelete();
            $table->foreignId('receipt_id')->unique()->constrained('accounting_ar_receipts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enterprise_billing_receipt_links');
    }
};
