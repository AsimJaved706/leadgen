<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('stripe_monthly_price_id')->nullable();
            $table->string('stripe_yearly_price_id')->nullable();
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('provider_customer_id')->nullable()->index();
            $table->string('provider_price_id')->nullable();
            $table->string('billing_interval')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
        });
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['provider_customer_id', 'provider_price_id', 'billing_interval', 'current_period_start', 'current_period_end', 'cancel_at_period_end', 'canceled_at']));
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn(['stripe_monthly_price_id', 'stripe_yearly_price_id']));
    }
};
