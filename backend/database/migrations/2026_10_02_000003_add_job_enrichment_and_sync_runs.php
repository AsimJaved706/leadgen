<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_jobs', function (Blueprint $table) {
            $table->string('company_domain')->nullable()->after('company_website');
            $table->string('email_discovery_status', 30)->default('not_checked')->after('contact_email');
            $table->string('email_source')->nullable()->after('email_discovery_status');
            $table->timestamp('enriched_at')->nullable()->after('scraped_at');
            $table->index(['workspace_id', 'email_discovery_status']);
        });
        Schema::create('job_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('running');
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('sources')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_sync_runs');
        Schema::table('workspace_jobs', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'email_discovery_status']);
            $table->dropColumn(['company_domain', 'email_discovery_status', 'email_source', 'enriched_at']);
        });
    }
};
