<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_destinations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('form_id', 26);
            $table->string('kind', 24);
            $table->json('config');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->string('health', 16)->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            // Webhook secret rotation grace
            $table->string('previous_secret', 128)->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->timestamps();

            $table->foreign('form_id')->references('id')->on('forms')->cascadeOnDelete();
            $table->index(['form_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_destinations');
    }
};
