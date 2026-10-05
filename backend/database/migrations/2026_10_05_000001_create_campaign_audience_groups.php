<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('campaign_audience_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('countries');
            $table->boolean('require_email')->default(true);
            $table->timestamps();
            $table->unique(['workspace_id', 'name']);
        });
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->string('audience_type')->default('all')->change();
            $table->foreignId('campaign_audience_group_id')->nullable()->after('lead_list_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_audience_group_id');
        });
        Schema::dropIfExists('campaign_audience_groups');
    }
};
