<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_blocklist_entries', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26)->nullable(); // null = global
            $table->string('ip_or_cidr', 64);
            $table->string('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index('ip_or_cidr');
            $table->index(['workspace_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_blocklist_entries');
    }
};
