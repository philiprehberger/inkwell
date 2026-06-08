<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_files', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('submission_id', 26);
            $table->string('field_name', 64);
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size');
            $table->string('scan_state', 16)->default('pending');
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('submission_id')->references('id')->on('submissions')->cascadeOnDelete();
            $table->index('scan_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_files');
    }
};
