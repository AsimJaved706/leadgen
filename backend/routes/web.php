<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\EmailMarketingController;
use App\Http\Controllers\EmailOpenController;
use App\Http\Controllers\EmailUnsubscribeController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\LeadImportController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\JobController;
use App\Http\Middleware\ActiveUser;
use App\Http\Middleware\SuperAdmin;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('/csrf', fn () => response()->json(['token' => csrf_token()]));
    Route::get('/plans', fn () => Plan::where('is_active', true)->orderBy('monthly_price_cents')->get());
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/stripe/webhook', StripeWebhookController::class);
    Route::middleware(['auth:sanctum', ActiveUser::class])->group(function () {
        Route::get('/me', fn (Request $r) => $r->user()->load('workspaces.plan'));
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/extension/token', [ExtensionController::class, 'token'])->middleware('throttle:10,1');
        Route::get('/extension/context', [ExtensionController::class, 'context'])->middleware('throttle:60,1');
        Route::post('/extension/workspaces/{workspace}/leads', [ExtensionController::class, 'storeLeads'])->middleware('throttle:20,1');
        Route::get('/workspaces/{workspace}/summary', [WorkspaceController::class, 'summary']);
        Route::get('/workspaces/{workspace}/leads', [WorkspaceController::class, 'leads']);
        Route::post('/workspaces/{workspace}/leads', [WorkspaceController::class, 'createLead']);
        Route::get('/workspaces/{workspace}/leads/{lead}', [WorkspaceController::class, 'showLead']);
        Route::post('/workspaces/{workspace}/leads/import', LeadImportController::class);
        Route::delete('/workspaces/{workspace}/leads', [WorkspaceController::class, 'deleteLeads']);
        Route::delete('/workspaces/{workspace}/leads/{lead}', [WorkspaceController::class, 'deleteLead']);
        Route::get('/workspaces/{workspace}/lists', [WorkspaceController::class, 'lists']);
        Route::post('/workspaces/{workspace}/lists', [WorkspaceController::class, 'createList']);
        Route::post('/workspaces/{workspace}/lists/{list}/leads', [WorkspaceController::class, 'addLeadsToList']);
        Route::get('/workspaces/{workspace}/jobs', [JobController::class, 'index']);
        Route::get('/workspaces/{workspace}/jobs/filters', [JobController::class, 'filters']);
        Route::post('/workspaces/{workspace}/jobs/sync', [JobController::class, 'sync'])->middleware('throttle:3,10');
        Route::post('/workspaces/{workspace}/jobs/enrich', [JobController::class, 'enrich'])->middleware('throttle:2,10');
        Route::post('/workspaces/{workspace}/jobs', [JobController::class, 'store']);
        Route::post('/workspaces/{workspace}/jobs/import', [JobController::class, 'import'])->middleware('throttle:20,1');
        Route::get('/workspaces/{workspace}/jobs/{job}', [JobController::class, 'show']);
        Route::patch('/workspaces/{workspace}/jobs/{job}', [JobController::class, 'update']);
        Route::delete('/workspaces/{workspace}/jobs/{job}', [JobController::class, 'destroy']);
        Route::get('/workspaces/{workspace}/email-settings', [EmailMarketingController::class, 'settings']);
        Route::put('/workspaces/{workspace}/email-settings', [EmailMarketingController::class, 'saveSettings']);
        Route::post('/workspaces/{workspace}/email-settings/test', [EmailMarketingController::class, 'testSettings']);
        Route::get('/workspaces/{workspace}/email-templates', [EmailMarketingController::class, 'templates']);
        Route::post('/workspaces/{workspace}/email-templates', [EmailMarketingController::class, 'saveTemplate']);
        Route::put('/workspaces/{workspace}/email-templates/{template}', [EmailMarketingController::class, 'saveTemplate']);
        Route::delete('/workspaces/{workspace}/email-templates/{template}', [EmailMarketingController::class, 'deleteTemplate']);
        Route::get('/workspaces/{workspace}/email-campaigns', [EmailMarketingController::class, 'campaigns']);
        Route::get('/workspaces/{workspace}/email-campaigns/{campaign}/recipients', [EmailMarketingController::class, 'campaignRecipients']);
        Route::post('/workspaces/{workspace}/email-campaigns', [EmailMarketingController::class, 'createCampaign']);
        Route::post('/workspaces/{workspace}/email-campaigns/{campaign}/cancel', [EmailMarketingController::class, 'cancelCampaign']);
        Route::post('/workspaces/{workspace}/email-campaigns/{campaign}/send', [EmailMarketingController::class, 'sendCampaign']);
        Route::get('/workspaces/{workspace}/billing', [BillingController::class, 'status']);
        Route::post('/workspaces/{workspace}/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1');
        Route::post('/workspaces/{workspace}/billing/confirm', [BillingController::class, 'confirm'])->middleware('throttle:10,1');
        Route::post('/workspaces/{workspace}/billing/portal', [BillingController::class, 'portal'])->middleware('throttle:10,1');
        Route::prefix('admin')->middleware(SuperAdmin::class)->group(function () {
            Route::get('/overview', [AdminController::class, 'overview']);
            Route::get('/users', [AdminController::class, 'users']);
            Route::patch('/users/{user}', [AdminController::class, 'updateUser']);
            Route::get('/workspaces', [AdminController::class, 'workspaces']);
            Route::patch('/workspaces/{workspace}', [AdminController::class, 'updateWorkspace']);
            Route::get('/plans', fn () => Plan::orderBy('monthly_price_cents')->get());
            Route::patch('/plans/{plan}', [AdminController::class, 'updatePlan']);
            Route::get('/stripe', [AdminController::class, 'stripeSettings']);
            Route::patch('/stripe', [AdminController::class, 'updateStripeSettings']);
            Route::post('/stripe/test', [AdminController::class, 'testStripe'])->middleware('throttle:5,1');
            Route::post('/stripe/sync-prices', [AdminController::class, 'syncStripePrices'])->middleware('throttle:2,1');
            Route::get('/audit-logs', [AdminController::class, 'audit']);
        });
    });
});

Route::get('/email/unsubscribe/{recipient}', EmailUnsubscribeController::class)->name('email.unsubscribe');
Route::get('/email/open/{recipient}.gif', EmailOpenController::class)->name('email.open');

Route::fallback(fn () => response()->file(public_path('index.html')));
