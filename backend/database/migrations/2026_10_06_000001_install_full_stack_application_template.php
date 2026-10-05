<?php

use App\Models\Workspace;
use App\Support\ProfessionalEmailTemplates;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $template = ProfessionalEmailTemplates::fullStackDeveloperApplication();
        Workspace::query()->eachById(function (Workspace $workspace) use ($template) {
            $workspace->emailTemplates()->updateOrCreate(
                ['name' => $template['name']],
                $template + ['is_active' => true]
            );
        });
    }

    public function down(): void {}
};
