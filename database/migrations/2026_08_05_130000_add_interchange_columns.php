<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 5.7 — Interchange adoption columns.
 *
 * Every column is nullable or defaulted, so the rollback procedure can leave
 * them in place: v0 code ignores what it does not read (plan R-1/R-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_destinations', function (Blueprint $table) {
            // Encrypted at rest — these values are credentials (plan 5.4).
            $table->text('headers')->nullable()->after('config');

            // Fixed shapes only; no tenant-authored templates (plan 5.5).
            $table->string('envelope_shape', 32)->default('inkwell-native')->after('headers');

            // Defaults to the native scheme, permanently. Standard Webhooks
            // needs a newly issued whsec_ secret and a consumer handshake, so
            // this can never be flipped in bulk (G-2 migration hazard).
            $table->string('signature_scheme', 32)->default('inkwell-v0')->after('envelope_shape');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->char('trace_id', 32)->nullable()->after('meta');
            $table->string('trace_class', 16)->nullable()->after('trace_id');
            $table->index('trace_id');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            // Required before a credentialed destination may be saved (5.3).
            $table->json('egress_allowlist')->nullable();
        });

        // D-8: dual-accept evidence lives in the database, not in logs.
        // Log retention on this host is 30 days with a 200 MB cap, which is
        // exactly the window the sunset rule needs — too thin to rely on.
        Schema::create('signature_scheme_usage', function (Blueprint $table) {
            $table->id();
            $table->char('destination_id', 26);
            $table->string('scheme', 32);
            $table->unsignedBigInteger('requests')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['destination_id', 'scheme']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_scheme_usage');

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('egress_allowlist');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['trace_id']);
            $table->dropColumn(['trace_id', 'trace_class']);
        });

        Schema::table('form_destinations', function (Blueprint $table) {
            $table->dropColumn(['headers', 'envelope_shape', 'signature_scheme']);
        });
    }
};
