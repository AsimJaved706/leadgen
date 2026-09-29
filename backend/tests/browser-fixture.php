<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

// Only used against the isolated, disposable browser-test database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_contains(config('database.connections.sqlite.database'), 'browser-test-')) {
    throw new RuntimeException('Browser fixtures require a disposable testing database.');
}
$user = User::create(['name' => 'Platform Test Admin', 'email' => 'admin@example.test', 'password' => getenv('BROWSER_TEST_PASSWORD')]);
$user->forceFill(['is_super_admin' => true])->save();
