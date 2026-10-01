<?php

use App\Jobs\PrepareEmailCampaign;
use App\Models\EmailCampaign;
use App\Models\User;
use App\Support\Audit;
use App\Models\Workspace;
use App\Services\JobFeedSyncService;
use App\Services\CompanyEmailEnricher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('leadspace:admin {email} {--name=Platform Administrator}', function () {
    $email = strtolower($this->argument('email'));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Enter a valid email address.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('This account exists. Use the admin console to change existing roles.');

        return 1;
    }
    $password = $this->secret('Choose a password (12+ characters, letters and numbers)');
    if (strlen($password ?? '') < 12 || ! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)) {
        $this->error('Password does not meet the requirements.');

        return 1;
    }
    if ($password !== $this->secret('Confirm password')) {
        $this->error('Passwords do not match.');

        return 1;
    }
    DB::transaction(function () use ($email, $password) {
        $user = User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password]);
        $user->forceFill(['is_super_admin' => true])->save();
        Audit::record('user.admin_bootstrapped', $user->id, ['email' => $email]);
    });
    $this->info('Super administrator created. Sign in at /login.');
})->purpose('Securely bootstrap a super administrator without default credentials');

Schedule::call(function () {
    EmailCampaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())
        ->orderBy('id')->limit(100)->pluck('id')->each(fn ($id) => PrepareEmailCampaign::dispatch($id));
})->name('dispatch-due-email-campaigns')->everyMinute()->withoutOverlapping();

Artisan::command('jobs:sync {--workspace=}', function (JobFeedSyncService $service) {
    $query = Workspace::query()->whereNull('suspended_at')->whereHas('plan', fn ($q) => $q->where('slug', '!=', 'free'));
    if ($id = $this->option('workspace')) $query->whereKey($id);
    $query->each(function (Workspace $workspace) use ($service) {
        $this->info("Syncing jobs for workspace {$workspace->id}: {$workspace->name}");
        $run = $service->sync($workspace);
        $this->line("Created {$run->created_count}, updated {$run->updated_count}, failed {$run->failed_count}");
    });
})->purpose('Fetch and enrich jobs from configured free feeds');

Schedule::command('jobs:sync')->dailyAt('02:15')->withoutOverlapping()->onOneServer();

Artisan::command('jobs:enrich {--workspace=} {--limit=25}', function (CompanyEmailEnricher $service) {
    $query = Workspace::query()->whereNull('suspended_at')->whereHas('plan', fn ($q) => $q->where('slug', '!=', 'free'));
    if ($id = $this->option('workspace')) $query->whereKey($id);
    $query->each(function (Workspace $workspace) use ($service) {
        $result = $service->enrich($workspace, max(1, min(100, (int) $this->option('limit'))));
        $this->line("Workspace {$workspace->id}: checked {$result['checked']}, found {$result['found']} public emails");
    });
})->purpose('Find publicly listed contact emails on employer websites');

Schedule::command('jobs:enrich --limit=25')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
