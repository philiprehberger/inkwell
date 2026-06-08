<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('key', 128);
            $table->string('body_hash', 64);
            $table->json('response');
            $table->unsignedInteger('status_code');
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
