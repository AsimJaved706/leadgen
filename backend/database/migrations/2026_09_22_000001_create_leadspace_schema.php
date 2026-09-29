<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_super_admin')->default(false)->index();
            $t->timestamp('suspended_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
        });
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->unsignedInteger('monthly_price_cents')->default(0);
            $t->unsignedInteger('yearly_price_cents')->default(0);
            $t->json('limits');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('plan_id')->constrained()->restrictOnDelete();
            $t->timestamp('suspended_at')->nullable();
            $t->timestamps();
        });
        Schema::create('workspace_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->enum('role', ['owner', 'admin', 'member', 'viewer'])->default('member');
            $t->timestamps();
            $t->unique(['workspace_id', 'user_id']);
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained()->restrictOnDelete();
            $t->string('provider_id')->nullable()->unique();
            $t->string('status')->default('active');
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamps();
        });
        Schema::create('api_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('prefix', 16);
            $t->string('token_hash', 64)->unique();
            $t->json('abilities');
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('lead_lists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'name']);
        });
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            foreach (['phone', 'email', 'website', 'city', 'state', 'postal_code', 'country', 'category', 'price_range', 'place_id', 'cid', 'plus_code', 'website_domain', 'normalized_phone'] as $field) {
                $t->string($field)->nullable();
            }
            foreach (['address', 'description', 'google_maps_url', 'menu_url', 'order_url', 'reservation_url', 'source_search_query', 'source_location'] as $field) {
                $t->text($field)->nullable();
            }
            $t->string('name_address_hash', 64)->nullable();
            $t->decimal('average_rating', 2, 1)->nullable();
            $t->unsignedInteger('review_count')->default(0);
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            foreach (['secondary_phones', 'additional_emails', 'categories', 'current_hours', 'weekly_hours', 'social_profiles', 'image_urls', 'action_links', 'additional_details', 'raw_maps_details', 'tags'] as $field) {
                $t->json($field)->nullable();
            }
            $t->timestamp('collected_at')->nullable();
            $t->timestamp('enriched_at')->nullable();
            $t->timestamps();
            foreach (['place_id', 'cid', 'website_domain', 'normalized_phone', 'name_address_hash', 'email', 'category', 'city', 'country', 'collected_at', 'created_at'] as $field) {
                $t->index(['workspace_id', $field]);
            }
        });
        Schema::create('lead_list_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_list_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['lead_list_id', 'lead_id']);
        });
        Schema::create('imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->uuid('request_uuid');
            $t->string('name');
            $t->string('status')->default('pending');
            $t->unsignedInteger('created_count')->default(0);
            $t->unsignedInteger('updated_count')->default(0);
            $t->unsignedInteger('failed_count')->default(0);
            $t->json('result')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'request_uuid']);
        });
        Schema::create('import_failures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('row_number');
            $t->json('errors');
            $t->json('raw_data');
            $t->timestamps();
        });
        Schema::create('exports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('format');
            $t->string('status')->default('pending');
            $t->string('file_path')->nullable();
            $t->json('filters')->nullable();
            $t->timestamps();
        });
        Schema::create('usage_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('metric');
            $t->string('period', 7);
            $t->unsignedBigInteger('quantity')->default(0);
            $t->timestamps();
            $t->unique(['workspace_id', 'metric', 'period']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->string('resource_type')->nullable();
            $t->unsignedBigInteger('resource_id')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['workspace_id', 'created_at']);
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'usage_records', 'exports', 'import_failures', 'imports', 'lead_list_items', 'leads', 'lead_lists', 'api_keys', 'subscriptions', 'workspace_user', 'workspaces', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_super_admin', 'suspended_at', 'last_login_at']));
    }
};
