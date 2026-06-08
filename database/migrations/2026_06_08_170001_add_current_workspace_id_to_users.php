<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('current_workspace_id', 26)->nullable()->after('remember_token');
            $table->string('two_factor_secret')->nullable()->after('current_workspace_id');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->foreign('current_workspace_id')->references('id')->on('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_workspace_id']);
            $table->dropColumn([
                'current_workspace_id',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
