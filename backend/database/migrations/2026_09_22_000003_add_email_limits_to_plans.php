<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $limits = ['free' => 100, 'starter' => 2500, 'professional' => 25000, 'agency' => 100000];
        DB::table('plans')->orderBy('id')->get()->each(function ($plan) use ($limits) {
            $current = json_decode($plan->limits, true) ?: [];
            $current['monthly_emails'] = $limits[$plan->slug] ?? 100;
            DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($current), 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        DB::table('plans')->orderBy('id')->get()->each(function ($plan) {
            $current = json_decode($plan->limits, true) ?: [];
            unset($current['monthly_emails']);
            DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($current), 'updated_at' => now()]);
        });
    }
};
