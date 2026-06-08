<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('actor_type', 16);
            $table->string('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('subject_type', 32);
            $table->string('subject_id');
            $table->string('action', 64);
            $table->json('diff');
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
