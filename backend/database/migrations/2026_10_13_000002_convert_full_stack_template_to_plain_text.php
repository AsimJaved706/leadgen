<?php

use App\Models\Workspace;
use App\Support\ProfessionalEmailTemplates;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $template = ProfessionalEmailTemplates::fullStackDeveloperApplication();

        Workspace::query()->eachById(function (Workspace $workspace) use ($template) {
            $workspace->emailTemplates()
                ->where('name', $template['name'])
                ->update([
                    'subject' => $template['subject'],
                    'html_body' => $template['html_body'],
                    'text_body' => $template['text_body'],
                    'is_active' => true,
                ]);
        });
    }

    public function down(): void
    {
        // Keep the usable plain-text template if this migration is rolled back.
    }
};
