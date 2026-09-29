<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Audit
{
    public static function record(string $action, ?int $resourceId = null, array $metadata = [], ?int $workspaceId = null): void
    {
        DB::table('audit_logs')->insert(['user_id' => auth()->id(), 'workspace_id' => $workspaceId, 'action' => $action, 'resource_id' => $resourceId, 'resource_type' => explode('.', $action)[0], 'metadata' => json_encode($metadata), 'created_at' => now()]);
    }
}
