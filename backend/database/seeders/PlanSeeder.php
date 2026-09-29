<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['Free', 'free', 0, 500, 1, 3, 100], ['Starter', 'starter', 1900, 5000, 3, 20, 2500], ['Basic', 'basic', 2900, 10000, 5, 50, 10000], ['Professional', 'professional', 4900, 20000, 10, 100, 25000], ['Agency', 'agency', 9900, 100000, 50, 500, 100000]] as [$name, $slug, $price, $leads, $members, $lists, $emails]) {
            Plan::updateOrCreate(['slug' => $slug], ['name' => $name, 'monthly_price_cents' => $price, 'yearly_price_cents' => $price * 10, 'limits' => ['leads' => $leads, 'monthly_imports' => $leads, 'members' => $members, 'api_keys' => 5, 'monthly_exports' => 100, 'lists' => $lists, 'monthly_emails' => $emails], 'is_active' => true]);
        }
    }
}
