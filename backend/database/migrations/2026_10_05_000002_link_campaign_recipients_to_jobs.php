<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            $table->foreignId('job_id')->nullable()->after('lead_id')->constrained('workspace_jobs')->nullOnDelete();
            $table->index(['job_id', 'status']);
        });
    }
    public function down(): void
    {
        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            $table->dropIndex(['job_id', 'status']);
            $table->dropConstrainedForeignId('job_id');
        });
    }
};
