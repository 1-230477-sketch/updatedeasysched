<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'security.php';
easysched_load_local_env();
easysched_send_security_headers();

$documents = [
    'privacy' => [
        'title' => 'Privacy Policy',
        'intro' => 'This policy explains how New Sinai School and Colleges Sta. Rosa, Inc. handles information when you use EasySched.',
        'sections' => [
            ['heading' => 'Information we collect', 'paragraphs' => [
                'EasySched may collect account details such as your name, username, email address, role, and academic information needed to provide scheduling and account services.',
                'When you contact us, we collect the name, email address, and message that you choose to submit.'
            ]],
            ['heading' => 'How we use information', 'paragraphs' => [
                'We use information to authenticate users, manage student access requests, create and publish academic schedules, respond to support messages, maintain security, and improve the service.',
                'We do not sell personal information or use it for unrelated advertising.'
            ]],
            ['heading' => 'How we protect information', 'paragraphs' => [
                'Access is limited according to account role. We use server-side validation, protected sessions, and security controls designed to reduce unauthorized access. No online system can guarantee absolute security.'
            ]],
            ['heading' => 'Retention and your choices', 'paragraphs' => [
                'We retain information for as long as it is needed for school operations, account administration, security, and legal obligations. You may ask the school to review, correct, or delete personal information where applicable.'
            ]],
            ['heading' => 'Contact', 'paragraphs' => [
                'For privacy questions or requests, contact the school through the contact details shown on the EasySched homepage.'
            ]],
        ],
    ],
    'terms' => [
        'title' => 'Terms and Conditions',
        'intro' => 'These terms govern your use of EasySched, the academic scheduling system of New Sinai School and Colleges Sta. Rosa, Inc.',
        'sections' => [
            ['heading' => 'Acceptable use', 'paragraphs' => [
                'Use EasySched only for legitimate school, scheduling, account, and support activities. Do not attempt to access another person’s account, bypass security controls, disrupt the service, or submit false information.'
            ]],
            ['heading' => 'Accounts and responsibilities', 'paragraphs' => [
                'Keep your login credentials confidential and notify an administrator if you believe your account has been compromised. You are responsible for activity performed through your account.',
                'Student registration requests are subject to administrator review. Access may be suspended or removed when these terms or school policies are violated.'
            ]],
            ['heading' => 'Schedules and availability', 'paragraphs' => [
                'Schedules and related information are provided for school operations and may change as authorized staff update academic records. Always follow the latest schedule published by the school.'
            ]],
            ['heading' => 'Service availability', 'paragraphs' => [
                'We may modify, suspend, or discontinue parts of EasySched for maintenance, security, or operational reasons. We do not guarantee uninterrupted or error-free availability.'
            ]],
            ['heading' => 'Contact', 'paragraphs' => [
                'Questions about these terms should be sent through the contact details shown on the EasySched homepage.'
            ]],
        ],
    ],
    'cookies' => [
        'title' => 'Cookies Policy',
        'intro' => 'EasySched uses only the storage technologies needed to keep the application secure and functional.',
        'sections' => [
            ['heading' => 'Essential session cookies', 'paragraphs' => [
                'When you sign in, EasySched uses a secure session cookie to keep you authenticated between requests. This cookie is necessary for login, authorization, and protecting account activity.'
            ]],
            ['heading' => 'Preferences', 'paragraphs' => [
                'EasySched may store a local browser preference for display settings, such as your selected theme. This preference stays in your browser and is not used to identify you.'
            ]],
            ['heading' => 'What we do not use', 'paragraphs' => [
                'We do not use advertising cookies, cross-site tracking cookies, or third-party analytics cookies on the EasySched homepage or application.'
            ]],
            ['heading' => 'Managing cookies', 'paragraphs' => [
                'You can control or delete cookies through your browser settings. Disabling essential session cookies may prevent sign-in and other protected features from working correctly.'
            ]],
        ],
    ],
];

$documentKey = (string) ($_GET['document'] ?? 'privacy');
$document = $documents[$documentKey] ?? $documents['privacy'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= htmlspecialchars($document['title'], ENT_QUOTES, 'UTF-8') ?> for EasySched">
    <title><?= htmlspecialchars($document['title'], ENT_QUOTES, 'UTF-8') ?> | EasySched</title>
    <link rel="icon" href="assets/school-logo.png">
    <link rel="stylesheet" href="styles.css">
</head>
<body class="legal-page">
    <header class="legal-header">
        <a class="home-brand" href="index.php" aria-label="EasySched home">
            <img src="assets/school-logo.png" alt="">
            <span>EasySched</span>
        </a>
        <a class="legal-home-link" href="index.php">Back to home <span aria-hidden="true">&rarr;</span></a>
    </header>

    <main class="legal-content">
        <p class="legal-eyebrow">New Sinai School and Colleges Sta. Rosa, Inc.</p>
        <h1><?= htmlspecialchars($document['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="legal-intro"><?= htmlspecialchars($document['intro'], ENT_QUOTES, 'UTF-8') ?></p>
        <p class="legal-updated">Last updated: October 10, 2026</p>

        <nav class="legal-document-nav" aria-label="Legal documents">
            <a class="<?= $documentKey === 'privacy' ? 'is-current' : '' ?>" href="legal.php?document=privacy">Privacy Policy</a>
            <a class="<?= $documentKey === 'terms' ? 'is-current' : '' ?>" href="legal.php?document=terms">Terms and Conditions</a>
            <a class="<?= $documentKey === 'cookies' ? 'is-current' : '' ?>" href="legal.php?document=cookies">Cookies Policy</a>
        </nav>

        <div class="legal-sections">
            <?php foreach ($document['sections'] as $section): ?>
                <section aria-labelledby="section-<?= htmlspecialchars(strtolower(str_replace(' ', '-', $section['heading'])), ENT_QUOTES, 'UTF-8') ?>">
                    <h2 id="section-<?= htmlspecialchars(strtolower(str_replace(' ', '-', $section['heading'])), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($section['heading'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <?php foreach ($section['paragraphs'] as $paragraph): ?>
                        <p><?= htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </main>

    <footer class="legal-footer">
        <span>&copy; <?= date('Y') ?> New Sinai School and Colleges Sta. Rosa, Inc.</span>
        <a href="index.php">EasySched home</a>
    </footer>
</body>
</html>
