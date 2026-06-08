<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('name')->nullable();
            $table->string('prefix', 24);
            $table->string('key_hash', 128)->unique();
            $table->char('last_four', 4);
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
