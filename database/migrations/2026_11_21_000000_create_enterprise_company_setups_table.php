<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_company_setups', function (Blueprint $table): void {
            $table->foreignId('team_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->unique()->constrained('accounting_legal_entities');
            $table->foreignId('billing_account_id')->unique()->constrained('billing_accounts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_company_setups');
    }
};
