<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The integration kit's tables (see src/Kit/README.md).
 *
 * Every table carries organization_id and every row belongs to one
 * connection, so deleting an organization or a connection removes its sync
 * state with it. None of these tables references a core table other than
 * organizations and users: imported orders, products and stock live in core
 * and survive the plugin's removal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plg_shopify_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('shop_domain', 191);
            $table->text('credentials')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('settings')->nullable();
            $table->json('remote')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('paused_until')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'shop_domain'], 'plg_shopify_conn_org_shop_uq');
            $table->index('status', 'plg_shopify_conn_status_idx');
        });

        Schema::create('plg_shopify_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('plg_shopify_connections')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->string('resource', 30);
            $table->string('trigger', 20);
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('error_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['connection_id', 'created_at'], 'plg_shopify_runs_conn_created_idx');
            $table->index(['organization_id', 'status'], 'plg_shopify_runs_org_status_idx');
        });

        Schema::create('plg_shopify_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('plg_shopify_connections')->cascadeOnDelete();
            $table->foreignId('sync_run_id')->nullable()->constrained('plg_shopify_sync_runs')->cascadeOnDelete();
            $table->string('level', 10);
            $table->string('entity', 30)->nullable();
            $table->string('external_id', 191)->nullable();
            $table->unsignedBigInteger('internal_id')->nullable();
            $table->string('action', 40)->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['connection_id', 'level', 'created_at'], 'plg_shopify_logs_conn_level_idx');
            $table->index(['sync_run_id'], 'plg_shopify_logs_run_idx');
        });

        Schema::create('plg_shopify_webhook_inbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('plg_shopify_connections')->cascadeOnDelete();
            $table->string('topic', 80);
            $table->string('event_id', 191);
            $table->char('payload_hash', 64);
            $table->string('resource_id', 191)->nullable();
            $table->longText('payload');
            $table->timestamp('triggered_at')->nullable();
            $table->string('status', 20)->default('received');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'event_id'], 'plg_shopify_inbox_conn_event_uq');
            $table->unique(['connection_id', 'topic', 'payload_hash'], 'plg_shopify_inbox_conn_hash_uq');
            $table->index(['connection_id', 'status'], 'plg_shopify_inbox_conn_status_idx');
        });

        Schema::create('plg_shopify_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('plg_shopify_connections')->cascadeOnDelete();
            $table->string('entity', 30);
            $table->string('external_id', 191);
            $table->string('external_parent_id', 191)->nullable();
            $table->string('external_label', 255)->nullable();
            $table->string('sku', 191)->nullable();
            $table->string('internal_type', 30)->nullable();
            $table->unsignedBigInteger('internal_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('meta')->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'entity', 'external_id'], 'plg_shopify_map_external_uq');
            $table->unique(['connection_id', 'entity', 'internal_type', 'internal_id'], 'plg_shopify_map_internal_uq');
            $table->index(['connection_id', 'entity', 'status'], 'plg_shopify_map_status_idx');
            $table->index(['connection_id', 'entity', 'sku'], 'plg_shopify_map_sku_idx');
        });

        Schema::create('plg_shopify_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('plg_shopify_connections')->cascadeOnDelete();
            $table->string('resource', 40);
            $table->string('cursor', 255)->nullable();
            $table->timestamp('position_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'resource'], 'plg_shopify_cursor_conn_res_uq');
        });

        Schema::create('plg_shopify_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('plg_shopify_connections')->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('subject', 100);
            $table->json('payload')->nullable();
            $table->unsignedInteger('coalesced')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'kind', 'subject'], 'plg_shopify_outbox_subject_uq');
            $table->index(['connection_id', 'kind', 'available_at'], 'plg_shopify_outbox_due_idx');
        });

        Schema::create('plg_shopify_dead_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('plg_shopify_connections')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('reference', 191)->nullable();
            $table->json('payload')->nullable();
            $table->text('error');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('failed_at');
            $table->timestamp('retried_at')->nullable();
            $table->foreignId('retried_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['connection_id', 'resolved_at'], 'plg_shopify_dead_conn_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plg_shopify_dead_letters');
        Schema::dropIfExists('plg_shopify_outbox');
        Schema::dropIfExists('plg_shopify_cursors');
        Schema::dropIfExists('plg_shopify_mappings');
        Schema::dropIfExists('plg_shopify_webhook_inbox');
        Schema::dropIfExists('plg_shopify_sync_logs');
        Schema::dropIfExists('plg_shopify_sync_runs');
        Schema::dropIfExists('plg_shopify_connections');
    }
};
