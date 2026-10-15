<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_operation_states', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('payload');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $legacy = DB::table('store_states')->where('key', 'botakstore')->value('payload');
        $payload = is_string($legacy) ? json_decode($legacy, true) : [];
        if (is_array($payload)) {
            DB::table('store_operation_states')->insert([
                'key' => 'botakstore',
                'payload' => json_encode(array_intersect_key($payload, array_flip([
                    'orders', 'payments', 'logs', 'refunds', 'notifications',
                ]))),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_operation_states');
    }
};
