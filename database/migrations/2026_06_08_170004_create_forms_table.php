<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->string('slug', 80);
            $table->string('name');
            $table->json('schema');
            $table->unsignedTinyInteger('spam_threshold')->default(50);
            $table->string('success_redirect_url')->nullable();
            $table->json('cors_origins');
            $table->boolean('accept_any_origin')->default(false);
            $table->timestamp('accept_any_origin_set_at')->nullable();
            $table->json('allowed_mime_types')->nullable();
            $table->string('honeypot_field', 64)->default('_subject_honeypot');
            $table->string('from_email')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'slug']);
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
