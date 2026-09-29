<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaignRecipient;
use Illuminate\Http\Request;

class EmailOpenController extends Controller
{
    public function __invoke(Request $request, EmailCampaignRecipient $recipient)
    {
        abort_unless($request->hasValidSignature(), 403);
        $recipient->forceFill([
            'opened_at' => $recipient->opened_at ?? now(),
            'open_count' => $recipient->open_count + 1,
        ])->save();

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
