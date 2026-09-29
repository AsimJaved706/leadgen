<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaignRecipient;
use App\Models\EmailUnsubscribe;
use Illuminate\Http\Request;

class EmailUnsubscribeController extends Controller
{
    public function __invoke(Request $request, EmailCampaignRecipient $recipient)
    {
        abort_unless($request->hasValidSignature(), 403);
        EmailUnsubscribe::updateOrCreate(['workspace_id' => $recipient->campaign->workspace_id, 'email' => strtolower($recipient->email)], ['email_campaign_id' => $recipient->campaign->id, 'unsubscribed_at' => now()]);

        return response('<!doctype html><html><head><meta name="viewport" content="width=device-width"><title>Unsubscribed</title></head><body style="font-family:system-ui;background:#f5f9ff;color:#172b4d;padding:60px 20px;text-align:center"><h1>You have been unsubscribed.</h1><p>You will not receive future campaigns from this workspace.</p></body></html>');
    }
}
