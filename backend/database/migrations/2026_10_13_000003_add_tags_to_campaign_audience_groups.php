<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_audience_groups', fn (Blueprint $table) => $table->json('tags')->nullable()->after('countries'));
    }

    public function down(): void
    {
        Schema::table('campaign_audience_groups', fn (Blueprint $table) => $table->dropColumn('tags'));
    }
};
