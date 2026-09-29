<?php

namespace App\Support;

use App\Models\Workspace;

class ProfessionalEmailTemplates
{
    public static function install(Workspace $workspace): int
    {
        $created = 0;
        foreach (self::templates() as $template) {
            if ($workspace->emailTemplates()->where('name', $template['name'])->exists()) {
                continue;
            }
            $workspace->emailTemplates()->create($template + ['is_active' => true]);
            $created++;
        }

        return $created;
    }

    private static function templates(): array
    {
        return [
            self::template('Professional Introduction', 'A quick idea for {{lead.name}}', 'Hello {{lead.name}}', 'I came across your business in {{lead.city}} and wanted to introduce myself.', 'We help growing businesses create a reliable pipeline of qualified opportunities without adding more manual work.', 'Would you be open to a brief conversation this week?'),
            self::template('Value Proposition', '{{lead.name}}: a practical way to grow your pipeline', 'A growth idea for {{lead.name}}', 'Your work in {{lead.category}} stood out to us.', 'Our platform helps teams organize verified prospects, personalize outreach, and keep every opportunity moving from first contact to conversation.', 'Can I send you a short overview tailored to your business?'),
            self::template('Friendly Follow-up', 'Following up — {{lead.name}}', 'Just following up', 'I wanted to bring my earlier note back to the top of your inbox.', 'I believe there may be a useful fit for {{lead.name}}, especially if generating consistent new opportunities is a priority this quarter.', 'Would a 15-minute call be useful, or is there someone else I should contact?'),
            self::template('Meeting Request', '15 minutes for {{lead.name}}?', 'Would next week work?', 'I would value the opportunity to learn more about your goals at {{lead.name}}.', 'I can share a few practical ideas based on your market in {{lead.city}} and show how similar teams organize their lead generation.', 'Are you available for a short call next week?'),
            self::template('Re-engagement', 'Still relevant for {{lead.name}}?', 'Should I close the loop?', 'I know priorities change, so I wanted to check in one final time.', 'If improving prospecting or outreach is still on your roadmap, I would be happy to share a concise plan for {{lead.name}}.', 'If the timing is not right, simply let me know and I will close the loop.'),
            self::fullStackDeveloperApplication(),
        ];
    }

    private static function fullStackDeveloperApplication(): array
    {
        $name = 'Full Stack Developer Application';
        $subject = 'Application for Senior Full Stack Developer - Fahad Tanwir';
        $html = '<p>Hello {{lead.name}},</p><p>I am writing to express my interest in a Senior Full Stack Developer opportunity with your team.</p><p>I have more than eight years of experience building scalable web applications and e-commerce platforms using React, Vue.js, Next.js, Angular, PHP, Laravel, MySQL, and PostgreSQL. I also have hands-on experience with Shopify, WordPress, REST API integrations, Azure, Docker, CI/CD, and AI-assisted development workflows.</p><p>In my current role at NCO Global, I develop and maintain Shopify and WordPress solutions, customize themes and storefront functionality, improve performance and user experience, and use AI tools to accelerate development, testing, debugging, documentation, and automation. My earlier roles involved developing responsive applications with Vue.js, Angular, and TypeScript while collaborating with backend, design, and product teams.</p><p>I have attached my resume for your review. Selected work:</p><p><a href="https://vision.bimmapping.com/">vision.bimmapping.com</a><br><a href="https://www.1guygadget.com/">1guygadget.com</a><br><a href="https://pazmentalrd.com/">pazmentalrd.com</a><br><a href="https://weekchef.com/">weekchef.com</a></p><p>I would welcome the opportunity to discuss how my experience could support your team. Thank you for your time and consideration.</p><p>Best regards,<br>Fahad Tanwir<br>Senior Full Stack Developer<br><a href="mailto:fahadm.dev@gmail.com">fahadm.dev@gmail.com</a><br>Lewisville, Texas</p>';
        $text = "Hello {{lead.name}},\n\nI am writing to express my interest in a Senior Full Stack Developer opportunity with your team.\n\nI have more than eight years of experience building scalable web applications and e-commerce platforms using React, Vue.js, Next.js, Angular, PHP, Laravel, MySQL, and PostgreSQL. I also have hands-on experience with Shopify, WordPress, REST API integrations, Azure, Docker, CI/CD, and AI-assisted development workflows.\n\nIn my current role at NCO Global, I develop and maintain Shopify and WordPress solutions, customize themes and storefront functionality, improve performance and user experience, and use AI tools to accelerate development, testing, debugging, documentation, and automation. My earlier roles involved developing responsive applications with Vue.js, Angular, and TypeScript while collaborating with backend, design, and product teams.\n\nI have attached my resume for your review. Selected work:\nhttps://vision.bimmapping.com/\nhttps://www.1guygadget.com/\nhttps://pazmentalrd.com/\nhttps://weekchef.com/\n\nI would welcome the opportunity to discuss how my experience could support your team. Thank you for your time and consideration.\n\nBest regards,\nFahad Tanwir\nSenior Full Stack Developer\nfahadm.dev@gmail.com\nLewisville, Texas";

        return compact('name', 'subject') + ['html_body' => $html, 'text_body' => $text];
    }

    private static function template(string $name, string $subject, string $heading, string $intro, string $value, string $cta): array
    {
        $html = '<div style="margin:0;background:#f4f8fc;padding:32px 16px;font-family:Arial,sans-serif;color:#15345b"><div style="max-width:600px;margin:auto;background:#ffffff;border:1px solid #dce8f7;border-radius:12px;overflow:hidden"><div style="height:5px;background:#2874e6"></div><div style="padding:34px"><div style="font-size:20px;font-weight:700;margin-bottom:24px">leadspace<span style="color:#2874e6">.</span></div><h1 style="font-size:24px;line-height:1.3;margin:0 0 18px">'.$heading.'</h1><p style="font-size:15px;line-height:1.75;color:#49627f">'.$intro.'</p><p style="font-size:15px;line-height:1.75;color:#49627f">'.$value.'</p><p style="font-size:15px;line-height:1.75;color:#15345b;font-weight:600">'.$cta.'</p><p style="font-size:15px;line-height:1.75;color:#49627f;margin-top:28px">Best regards,<br>Your team</p></div><div style="padding:18px 34px;background:#f7faff;font-size:11px;color:#7890ab">You are receiving this business communication because your contact information was associated with {{lead.name}}.</div></div></div>';
        $text = $heading."\n\n".$intro."\n\n".$value."\n\n".$cta."\n\nBest regards,\nYour team";

        return compact('name', 'subject') + ['html_body' => $html, 'text_body' => $text];
    }
}
