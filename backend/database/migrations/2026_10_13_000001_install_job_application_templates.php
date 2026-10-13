<?php

use App\Models\Workspace;
use App\Support\ProfessionalEmailTemplates;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Workspace::query()->eachById(
            fn (Workspace $workspace) => ProfessionalEmailTemplates::install($workspace)
        );
    }

    public function down(): void
    {
        // Preserve templates that may already be used by campaigns.
    }
};
