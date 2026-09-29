<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = ['stripe_mode', 'stripe_secret', 'stripe_webhook_secret', 'stripe_automatic_tax', 'frontend_url'];

    protected $hidden = ['stripe_secret', 'stripe_webhook_secret'];

    protected function casts(): array
    {
        return [
            'stripe_secret' => 'encrypted',
            'stripe_webhook_secret' => 'encrypted',
            'stripe_automatic_tax' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::firstOrCreate([], ['frontend_url' => config('services.stripe.frontend_url', 'http://localhost:5173')]);
    }
}
