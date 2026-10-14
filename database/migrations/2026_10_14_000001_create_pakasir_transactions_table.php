<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pakasir_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('order_id')->unique();
            $table->string('txn_id')->nullable()->unique();
            $table->string('order_type', 20)->index();
            $table->string('item_id');
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->string('payment_method', 30)->default('payment_link');
            $table->string('status', 20)->default('pending')->index();
            $table->boolean('is_sandbox')->default(true);
            $table->text('customer_data');
            $table->json('provider_payload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pakasir_transactions');
    }
};
