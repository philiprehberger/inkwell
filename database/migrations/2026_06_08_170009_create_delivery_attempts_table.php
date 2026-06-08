<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('delivery_id', 26);
            $table->unsignedInteger('attempt_number');
            $table->string('request_summary');
            $table->unsignedInteger('response_status')->nullable();
            $table->text('response_body_snippet')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('delivery_id')->references('id')->on('deliveries')->cascadeOnDelete();
            $table->unique(['delivery_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
    }
};
