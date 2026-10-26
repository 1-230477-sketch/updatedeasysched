<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db.php';

$pdo = db();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
    fwrite(STDERR, "EASYSCHED_DATABASE_URL must select PostgreSQL for this check.\n");
    exit(1);
}

define('EASYSCHED_LIBRARY_MODE', true);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api.php';

$duplicateActiveSubjects = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT LOWER(REGEXP_REPLACE(BTRIM(name), '[[:space:][:punct:]]+', '', 'g')) FROM subjects WHERE active = 1 GROUP BY 1 HAVING COUNT(*) > 1) duplicates")->fetchColumn();
$subjectPairIndex = $pdo->query("SELECT COUNT(*) FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'uq_subjects_active_code_name_nocase'")->fetchColumn();
$subjectNameIndex = $pdo->query("SELECT COUNT(*) FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'uq_subjects_active_name_nocase'")->fetchColumn();
$catalogShape = $pdo->query("SELECT COUNT(*) FROM subjects WHERE active = 1 AND ((code = 'GE 1' AND name IN ('Understanding the Self', 'Mathematics in the Modern World', 'Environmental Science')) OR (code = 'COMP' AND name IN ('Health Information System for Med Lab Science', 'Living in the IT Era')))")->fetchColumn();
$normalizedAnaPhy = $pdo->query("SELECT COUNT(*) FROM subjects WHERE active = 1 AND code = 'ANA/PHY R' AND name = 'Human Anatomy and Physiology'")->fetchColumn();
$introResearch = $pdo->query("SELECT COUNT(*) FROM subjects WHERE active = 1 AND name = 'Introduction to Research'")->fetchColumn();
$activeSubjectCount = (int) $pdo->query('SELECT COUNT(*) FROM subjects WHERE active = 1')->fetchColumn();
if ($duplicateActiveSubjects !== 0 || (int) $subjectPairIndex !== 1 || (int) $subjectNameIndex !== 1 || (int) $catalogShape !== 5 || (int) $normalizedAnaPhy !== 1 || (int) $introResearch !== 1 || $activeSubjectCount !== 63) {
    throw new RuntimeException('PostgreSQL cleaned subject catalog or code/name uniqueness check failed.');
}

$admin = $pdo->query("SELECT * FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1")->fetch();
if (!$admin || normalize_subject_code(['code' => ' PATFIT:1 ']) !== 'PATHFIT 1' || normalize_subject_code(['code' => 'math1']) !== 'MATH 1') {
    throw new RuntimeException('Subject course-code normalization check failed.');
}

$pdo->beginTransaction();
try {
    $token = strtoupper(bin2hex(random_bytes(4)));
    $term = $pdo->query('SELECT id, academic_year, semester FROM academic_terms ORDER BY is_active DESC, id DESC LIMIT 1')->fetch();
    $instructor = $pdo->query('SELECT id, employee_no, name FROM instructors WHERE active = 1 ORDER BY id LIMIT 1')->fetch();
    if (!$term || !$instructor) {
        throw new RuntimeException('CSV class-assignment import test needs an academic term and active instructor.');
    }
    $pdo->prepare('INSERT INTO programs (code, name) VALUES (?, ?)')->execute(['CSVTEST-' . $token, 'CSV Import Test']);
    $programId = db_insert_id($pdo, 'programs');
    $pdo->prepare('INSERT INTO sections (program_id, term_id, code, year_level, student_count) VALUES (?, ?, ?, 1, 30)')
        ->execute([$programId, (int) $term['id'], 'CSV-' . $token]);
    $sectionId = db_insert_id($pdo, 'sections');
    $subjectCode = 'CSV-' . $token;
    $subjectName = 'CSV Import Test ' . $token;
    $pdo->prepare('INSERT INTO subjects (code, name) VALUES (?, ?)')->execute([$subjectCode, $subjectName]);
    $subjectId = db_insert_id($pdo, 'subjects');
    $csvHeaders = 'academic_year,semester,subject_code,subject_name,section_code,instructor_employee_no,instructor_name,enrollment,required_meetings';
    $csvRow = implode(',', [
        $term['academic_year'],
        $term['semester'],
        $subjectCode,
        $subjectName,
        'CSV-' . $token,
        'LOCAL-' . $token,
        $instructor['name'],
        '10',
        '1',
    ]);
    $firstImport = import_offerings_csv($pdo, $admin, ['csv' => $csvHeaders . "\n" . $csvRow]);
    if ($firstImport['created'] !== 1 || $firstImport['skipped'] !== 0) {
        throw new RuntimeException('CSV import did not create a new class assignment.');
    }
    $repeatCsv = $csvHeaders . "\n" . implode(',', [
        $term['academic_year'],
        $term['semester'],
        $subjectCode,
        $subjectName,
        'CSV-' . $token,
        'LOCAL-' . $token,
        $instructor['name'],
        '11',
        '2',
    ]);
    $secondImport = import_offerings_csv($pdo, $admin, ['csv' => $repeatCsv]);
    if ($secondImport['created'] !== 0 || $secondImport['skipped'] !== 1) {
        throw new RuntimeException('CSV import did not skip an existing class assignment.');
    }

    $invalidBatch = $repeatCsv . "\n" . implode(',', [
        $term['academic_year'],
        $term['semester'],
        $subjectCode,
        $subjectName,
        'MISSING-' . $token,
        'LOCAL-' . $token,
        $instructor['name'],
        '12',
        '2',
    ]);
    $invalidBatchRejected = false;
    try {
        import_offerings_csv($pdo, $admin, ['csv' => $invalidBatch]);
    } catch (ApiError $error) {
        $invalidBatchRejected = $error->status === 422;
    }
    $assignmentStmt = $pdo->prepare('SELECT enrollment, required_meetings FROM course_offerings WHERE term_id = ? AND subject_id = ? AND section_id = ?');
    $assignmentStmt->execute([(int) $term['id'], $subjectId, $sectionId]);
    $assignment = $assignmentStmt->fetch();
    if (!$invalidBatchRejected || !$assignment || (int) $assignment['enrollment'] !== 10 || (int) $assignment['required_meetings'] !== 1) {
        throw new RuntimeException('CSV import partially applied an invalid batch.');
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$expectedFaculty = faculty_roster();
$assignmentsBeforeFacultyMigration = (int) $pdo->query('SELECT COUNT(*) FROM course_offerings')->fetchColumn();
$pdo->beginTransaction();
try {
    $createdInstructor = save_master($pdo, $admin, [
        'entity' => 'instructors',
        'data' => [
            'employee_no' => 'TEST-FAC-9001',
            'name' => 'Test Added Instructor',
            'email' => '',
            'max_hours_day' => 6,
        ],
    ]);
    $instructorId = (int) $createdInstructor['id'];
    normalize_instructor_catalog($pdo);
    $instructorStmt = $pdo->prepare('SELECT active FROM instructors WHERE id = ?');
    $instructorStmt->execute([$instructorId]);
    if ((int) $instructorStmt->fetchColumn() !== 0) {
        throw new RuntimeException('Instructor outside the approved 31-person roster remained active.');
    }
    $snapshot = bootstrap($pdo, $admin);
    $snapshotFaculty = array_column($snapshot['instructors'], 'name');
    if ($snapshotFaculty !== $expectedFaculty || count(array_unique($snapshotFaculty)) !== 31) {
        throw new RuntimeException('Faculty and class-assignment instructor options do not match the exact 31-person roster.');
    }
    $assignedFaculty = (int) $pdo->query("SELECT COUNT(DISTINCT co.instructor_id) FROM course_offerings co JOIN instructors i ON i.id = co.instructor_id WHERE co.status = 'ACTIVE' AND i.active = 1")->fetchColumn();
    $assignmentCountAfterNormalization = (int) $pdo->query('SELECT COUNT(*) FROM course_offerings')->fetchColumn();
    if ($assignedFaculty > 31 || $assignmentCountAfterNormalization !== $assignmentsBeforeFacultyMigration) {
        throw new RuntimeException('Instructor migration changed existing class-assignment records.');
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$pdo->beginTransaction();
try {
    $created = save_master($pdo, $admin, ['entity' => 'subjects', 'data' => ['code' => ' patfit:1 ', 'name' => '  new   regression SUBJECT  ']]);
    $subjectId = (int) $created['id'];
    $subjectStmt = $pdo->prepare('SELECT code, name FROM subjects WHERE id = ? AND active = 1');
    $subjectStmt->execute([$subjectId]);
    $createdSubject = $subjectStmt->fetch();
    if (!$createdSubject || $createdSubject['code'] !== 'PATHFIT 1' || $createdSubject['name'] !== 'New Regression Subject') {
        throw new RuntimeException('Subject creation and normalized persistence check failed.');
    }

    $duplicateRejected = false;
    try {
        save_master($pdo, $admin, ['entity' => 'subjects', 'data' => ['code' => 'OTHER 1', 'name' => 'new regression-subject']]);
    } catch (ApiError $error) {
        $duplicateRejected = $error->status === 409;
    }
    if (!$duplicateRejected) {
        throw new RuntimeException('Subject API accepted a formatting-only duplicate.');
    }

    save_master($pdo, $admin, ['entity' => 'subjects', 'data' => ['id' => $subjectId, 'code' => 'COMP', 'name' => 'A Different Subject']]);
    $subjectStmt->execute([$subjectId]);
    $editedSubject = $subjectStmt->fetch();
    if (!$editedSubject || $editedSubject['code'] !== 'COMP' || $editedSubject['name'] !== 'A Different Subject') {
        throw new RuntimeException('Subject editing or same-code/different-name support check failed.');
    }

    save_master($pdo, $admin, ['entity' => 'subjects', 'id' => $subjectId, 'delete' => true]);
    $subjectStmt = $pdo->prepare('SELECT active FROM subjects WHERE id = ?');
    $subjectStmt->execute([$subjectId]);
    if ((int) $subjectStmt->fetchColumn() !== 0) {
        throw new RuntimeException('Subject deletion check failed.');
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO subjects (code, name) VALUES ('QA TEST 1', 'Regression Subject')")->execute();
    $pdo->prepare("INSERT INTO subjects (code, name) VALUES ('OTHER 1', 'Regression-Subject')")->execute();
} catch (PDOException) {
    $duplicateSubjectPairRejected = true;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
if (empty($duplicateSubjectPairRejected)) {
    throw new RuntimeException('PostgreSQL accepted a formatting-only duplicate subject.');
}

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO subjects (code, name) VALUES ('QA TEST 2', 'One Subject')")->execute();
    $pdo->prepare("INSERT INTO subjects (code, name) VALUES ('QA TEST 2', 'Another Subject')")->execute();
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$beforeRequests = (int) $pdo->query('SELECT COUNT(*) FROM schedule_requests')->fetchColumn();
$pdo->beginTransaction();
try {
    $pdo->exec('INSERT INTO schedule_requests (term_id, offering_id, instructor_id, room_id, day_of_week, slot_id) SELECT term_id, offering_id, instructor_id, room_id, day_of_week, slot_id FROM schedule_requests ORDER BY id LIMIT 1');
    $requestId = db_insert_id($pdo, 'schedule_requests');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
$afterRequests = (int) $pdo->query('SELECT COUNT(*) FROM schedule_requests')->fetchColumn();
if ($requestId < 1 || $beforeRequests !== $afterRequests) {
    throw new RuntimeException('PostgreSQL generated-ID rollback check failed.');
}

$programCode = (string) $pdo->query('SELECT code FROM programs ORDER BY id LIMIT 1')->fetchColumn();
$caseInsensitiveUnique = false;
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare('INSERT INTO programs (code, name) VALUES (?, ?)');
    $insert->execute([strtolower($programCode), 'PostgreSQL uniqueness probe']);
} catch (PDOException) {
    $caseInsensitiveUnique = true;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
if (!$caseInsensitiveUnique) {
    throw new RuntimeException('PostgreSQL accepted a case-insensitive duplicate program code.');
}

echo "PostgreSQL IDs, rollback, 63 unique subjects, name-only catalog CRUD/normalization, and uniqueness: OK\n";
