<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

$pdo = new PDO('sqlite:' . __DIR__ . '/data/easysched.sqlite');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$termId = (int) $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
$validSectionId = (int) $pdo->query("SELECT sec.id FROM sections sec JOIN course_offerings co ON co.section_id = sec.id AND co.term_id = {$termId} AND co.status = 'ACTIVE' WHERE sec.active = 1 GROUP BY sec.id ORDER BY sec.id LIMIT 1")->fetchColumn();

$result = [
  'term_id' => $termId,
  'valid_section_id' => $validSectionId,
  'before' => $pdo->query("SELECT id, username, role, section_id, active FROM users WHERE username = 'student' ORDER BY id")->fetchAll(),
];

if ($validSectionId > 0) {
    $update = $pdo->prepare('UPDATE users SET section_id = ? WHERE username = ? AND role = ?');
    $update->execute([$validSectionId, 'student', 'student']);
}

$result['after'] = $pdo->query("SELECT id, username, role, section_id, active FROM users WHERE username = 'student' ORDER BY id")->fetchAll();
$result['valid_sections'] = $pdo->query("SELECT sec.id, sec.code FROM sections sec JOIN course_offerings co ON co.section_id = sec.id AND co.term_id = {$termId} AND co.status = 'ACTIVE' WHERE sec.active = 1 GROUP BY sec.id ORDER BY sec.id")->fetchAll();

file_put_contents(__DIR__ . '/data/student_fix_report.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
