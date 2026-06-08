<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_memberships', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('workspace_id', 26);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('member');
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_memberships');
    }
};
