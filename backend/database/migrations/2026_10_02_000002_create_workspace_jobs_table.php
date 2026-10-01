<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('source_platform', 100);
            $table->string('source_job_id')->nullable();
            $table->text('source_url')->nullable();
            $table->string('dedupe_hash', 64);
            $table->string('title');
            $table->string('company_name')->nullable();
            $table->string('company_website')->nullable();
            $table->string('location')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('workplace_type', 30)->nullable();
            $table->string('employment_type', 50)->nullable();
            $table->string('seniority_level', 50)->nullable();
            $table->unsignedBigInteger('salary_min')->nullable();
            $table->unsignedBigInteger('salary_max')->nullable();
            $table->string('salary_currency', 3)->nullable();
            $table->string('salary_period', 30)->nullable();
            $table->longText('description')->nullable();
            $table->longText('requirements')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('status', 30)->default('new');
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('scraped_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'dedupe_hash']);
            $table->index(['workspace_id', 'source_platform']);
            $table->index(['workspace_id', 'country']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_jobs');
    }
};
