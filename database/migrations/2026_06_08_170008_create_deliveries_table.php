<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('submission_id', 26);
            $table->char('destination_id', 26);
            $table->string('state', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('replay_sequence')->default(0);
            $table->unsignedInteger('final_status_code')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamps();

            $table->foreign('submission_id')->references('id')->on('submissions')->cascadeOnDelete();
            $table->foreign('destination_id')->references('id')->on('form_destinations')->cascadeOnDelete();
            $table->index(['submission_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
