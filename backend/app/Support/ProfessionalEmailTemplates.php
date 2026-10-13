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
            self::simpleTemplate('Job Application - Frontend Developer', 'Application for {{lead.category}} - Fahad Tanwir', "Hello {{lead.name}},\n\nI am interested in the {{lead.category}} opportunity with your team. I have more than eight years of experience building responsive web applications with React, Vue.js, Next.js, Angular, TypeScript, and modern CSS frameworks.\n\nMy experience includes translating designs into accessible interfaces, integrating REST APIs, improving performance, and collaborating with backend, product, and design teams.\n\nI have attached my resume for your review. I would welcome the opportunity to discuss how my frontend experience could support your team.\n\nBest regards,\nFahad Tanwir\nfahadm.dev@gmail.com\nLewisville, Texas"),
            self::simpleTemplate('Job Application - Backend Developer', 'Application for {{lead.category}} - Fahad Tanwir', "Hello {{lead.name}},\n\nI am writing to apply for the {{lead.category}} opportunity. I have more than eight years of software development experience, including PHP, Laravel, MySQL, PostgreSQL, REST APIs, authentication, background jobs, integrations, Docker, Azure, and CI/CD.\n\nI focus on building secure, maintainable systems and reliable APIs that support real business workflows.\n\nMy resume is attached. I would be glad to discuss the role and how my backend experience could contribute to your team.\n\nBest regards,\nFahad Tanwir\nfahadm.dev@gmail.com\nLewisville, Texas"),
            self::simpleTemplate('Job Application - Web Developer', 'Application for {{lead.category}} - Fahad Tanwir', "Hello {{lead.name}},\n\nI would like to be considered for the {{lead.category}} position. I have more than eight years of experience developing and maintaining business websites, web applications, and e-commerce platforms.\n\nMy background includes JavaScript, TypeScript, React, Vue.js, PHP, Laravel, WordPress, Shopify, databases, API integrations, performance optimization, and responsive development.\n\nI have attached my resume and would appreciate the opportunity to speak with you about the position.\n\nBest regards,\nFahad Tanwir\nfahadm.dev@gmail.com\nLewisville, Texas"),
            self::simpleTemplate('Job Application - Shopify and WordPress Developer', 'Application for {{lead.category}} - Fahad Tanwir', "Hello {{lead.name}},\n\nI am interested in the {{lead.category}} opportunity. In my current role at NCO Global, I develop and maintain Shopify and WordPress solutions, customize themes and storefront functionality, integrate third-party services, and improve site performance and user experience.\n\nI also bring full-stack experience with React, Vue.js, PHP, Laravel, MySQL, REST APIs, Docker, and CI/CD.\n\nMy resume is attached for your review. I would welcome a conversation about how I can support your team.\n\nBest regards,\nFahad Tanwir\nfahadm.dev@gmail.com\nLewisville, Texas"),
            self::simpleTemplate('Job Application - Short Introduction', 'Interest in {{lead.category}} - Fahad Tanwir', "Hello {{lead.name}},\n\nI am reaching out regarding the {{lead.category}} opportunity. I am a Senior Full Stack Developer with more than eight years of experience across React, Vue.js, Next.js, Angular, PHP, Laravel, Shopify, WordPress, MySQL, and PostgreSQL.\n\nI have attached my resume and would be happy to discuss how my experience aligns with your needs.\n\nThank you for your time.\n\nBest regards,\nFahad Tanwir\nfahadm.dev@gmail.com\nLewisville, Texas"),
            self::simpleTemplate('Simple Introduction', 'Quick introduction', "Hi {{lead.name}},\n\nI came across your business and wanted to introduce myself. We help companies simplify lead generation and outreach.\n\nWould you be open to a quick conversation?\n\nBest regards,\nYour name"),
            self::simpleTemplate('Local Business Outreach', 'A quick question about {{lead.name}}', "Hi {{lead.name}},\n\nI found {{lead.name}} while researching businesses in {{lead.city}}. I wanted to ask whether finding more qualified customers is currently a priority for your team.\n\nIf so, I would be happy to share a few ideas.\n\nBest regards,\nYour name"),
            self::simpleTemplate('Simple Service Offer', 'Could we help {{lead.name}}?', "Hi {{lead.name}},\n\nWe help {{lead.category}} businesses find and contact suitable prospects without spending hours on manual research.\n\nWould you like me to send a short overview of how it works?\n\nBest regards,\nYour name"),
            self::simpleTemplate('Quick Question', 'Quick question, {{lead.name}}', "Hi {{lead.name}},\n\nAre you currently looking for a simpler way to organize leads and manage outreach?\n\nI may have something useful for {{lead.name}}. Would a short introduction be helpful?\n\nBest regards,\nYour name"),
            self::simpleTemplate('Short Follow-Up', 'Following up', "Hi {{lead.name}},\n\nI wanted to follow up on my previous message in case it was missed.\n\nWould you be available for a brief conversation this week?\n\nBest regards,\nYour name"),
            self::simpleTemplate('Final Check-In', 'Should I close the loop?', "Hi {{lead.name}},\n\nThis is my final follow-up. If improving lead generation is relevant right now, I would be glad to share more information.\n\nIf the timing is not right, no problem at all.\n\nBest regards,\nYour name"),
        ];
    }

    public static function fullStackDeveloperApplication(): array
    {
        $name = 'Full Stack Developer Application';
        $subject = 'Application for Senior Full Stack Developer - Fahad Tanwir';
        $text = "Hello {{lead.name}},\n\nI am writing to express my interest in a Senior Full Stack Developer opportunity with your team.\n\nI have more than eight years of experience building scalable web applications and e-commerce platforms using React, Vue.js, Next.js, Angular, PHP, Laravel, MySQL, and PostgreSQL. I also have hands-on experience with Shopify, WordPress, REST API integrations, Azure, Docker, CI/CD, and AI-assisted development workflows.\n\nIn my current role at NCO Global, I develop and maintain Shopify and WordPress solutions, customize themes and storefront functionality, improve performance and user experience, and use AI tools to accelerate development, testing, debugging, documentation, and automation. My earlier roles involved developing responsive applications with Vue.js, Angular, and TypeScript while collaborating with backend, design, and product teams.\n\nI have attached my resume for your review. Selected work:\nhttps://vision.bimmapping.com/\nhttps://www.1guygadget.com/\nhttps://pazmentalrd.com/\nhttps://weekchef.com/\n\nI would welcome the opportunity to discuss how my experience could support your team. Thank you for your time and consideration.\n\nBest regards,\nFahad Tanwir\nSenior Full Stack Developer\nfahadm.dev@gmail.com\nLewisville, Texas";

        return self::simpleTemplate($name, $subject, $text);
    }

    private static function template(string $name, string $subject, string $heading, string $intro, string $value, string $cta): array
    {
        $html = '<div style="margin:0;background:#f4f8fc;padding:32px 16px;font-family:Arial,sans-serif;color:#15345b"><div style="max-width:600px;margin:auto;background:#ffffff;border:1px solid #dce8f7;border-radius:12px;overflow:hidden"><div style="height:5px;background:#2874e6"></div><div style="padding:34px"><div style="font-size:20px;font-weight:700;margin-bottom:24px">leadspace<span style="color:#2874e6">.</span></div><h1 style="font-size:24px;line-height:1.3;margin:0 0 18px">'.$heading.'</h1><p style="font-size:15px;line-height:1.75;color:#49627f">'.$intro.'</p><p style="font-size:15px;line-height:1.75;color:#49627f">'.$value.'</p><p style="font-size:15px;line-height:1.75;color:#15345b;font-weight:600">'.$cta.'</p><p style="font-size:15px;line-height:1.75;color:#49627f;margin-top:28px">Best regards,<br>Your team</p></div><div style="padding:18px 34px;background:#f7faff;font-size:11px;color:#7890ab">You are receiving this business communication because your contact information was associated with {{lead.name}}.</div></div></div>';
        $text = $heading."\n\n".$intro."\n\n".$value."\n\n".$cta."\n\nBest regards,\nYour team";

        return compact('name', 'subject') + ['html_body' => $html, 'text_body' => $text];
    }

    private static function simpleTemplate(string $name, string $subject, string $text): array
    {
        $paragraphs = array_map(
            fn (string $paragraph) => '<p>'.nl2br(htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</p>',
            preg_split('/\n{2,}/', trim($text))
        );

        return compact('name', 'subject') + ['html_body' => implode('', $paragraphs), 'text_body' => $text];
    }
}
