<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('form_id', 26);
            $table->char('workspace_id', 26);
            $table->json('payload');
            $table->json('meta');
            $table->unsignedTinyInteger('spam_score')->default(0);
            $table->json('spam_signals')->nullable();
            $table->string('state', 16)->default('pending');
            $table->string('payload_hash', 64);
            $table->timestamp('pii_purged_at')->nullable();
            $table->timestamps();

            $table->foreign('form_id')->references('id')->on('forms')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['form_id', 'state', 'created_at']);
            $table->index(['form_id', 'created_at']);
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
