<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_subject_requests', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('email_hash', 64);
            $table->text('reason');
            $table->string('state', 16)->default('queued');
            $table->unsignedInteger('submissions_purged')->default(0);
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_requests');
    }
};
