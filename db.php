<?php
declare(strict_types=1);

// Load optional local settings, such as email delivery, from an untracked .env file.
function load_local_env(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    $candidatePaths = [
        __DIR__ . DIRECTORY_SEPARATOR . '.env',
        __DIR__ . DIRECTORY_SEPARATOR . '.env.local',
        __DIR__ . DIRECTORY_SEPARATOR . '.env.txt',
    ];

    foreach ($candidatePaths as $path) {
        if (!is_file($path)) continue;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key); $value = trim($value);
            if ($value !== '' && (($value[0] ?? '') === '"' || ($value[0] ?? '') === "'")) $value = trim($value, "\"'");
            if ($key === '') continue;
            $runtimeValue = getenv($key);
            if ($runtimeValue === false || $runtimeValue === '') {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        break;
    }
}

function db(): PDO
{
    load_local_env();
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        // Railway's PostgreSQL plugin exposes DATABASE_URL by default. Keep
        // the app-specific variable as the preferred override for other hosts.
        $databaseUrl = getenv('EASYSCHED_DATABASE_URL') ?: (getenv('DATABASE_URL') ?: '');
        if ($databaseUrl === '') {
            throw new RuntimeException('EASYSCHED_DATABASE_URL or DATABASE_URL is required.');
        }
        $parts = parse_url($databaseUrl);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['postgres', 'postgresql'], true) || empty($parts['host']) || empty($parts['path'])) {
            throw new RuntimeException('EASYSCHED_DATABASE_URL or DATABASE_URL must be a PostgreSQL connection URL.');
        }
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $dsn = 'pgsql:host=' . $parts['host'] . ';port=' . (int) ($parts['port'] ?? 5432) . ';dbname=' . ltrim($parts['path'], '/');
        if (isset($query['sslmode'])) {
            $dsn .= ';sslmode=' . $query['sslmode'];
        }
        $pdo = new PDO($dsn, rawurldecode((string) ($parts['user'] ?? '')), rawurldecode((string) ($parts['pass'] ?? '')));

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $schema = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'schema.postgres.sql');
        if ($schema === false) {
            throw new RuntimeException('Database schema is unavailable.');
        }
        $pdo->exec($schema);
        
        // Initialize database
        seed_database($pdo);
        ensure_schedule_term_options($pdo);
        ensure_schedule_history_samples($pdo);
        normalize_instructor_catalog($pdo);
        normalize_institution_branding($pdo);
        normalize_program_catalog($pdo);
        normalize_section_catalog($pdo);
        normalize_room_catalog($pdo);
        normalize_subject_catalog($pdo);
        normalize_time_slot_catalog($pdo);
        ensure_schedule_request_date($pdo);
        
        return $pdo;
    } catch (Throwable $e) {
        error_log('Database initialization error: ' . $e->getMessage());
        throw $e;
    }
}

function ensure_schedule_request_date(PDO $pdo): void
{
    $pdo->exec("ALTER TABLE schedule_requests ADD COLUMN IF NOT EXISTS request_type TEXT NOT NULL DEFAULT 'ROOM'");
    $pdo->exec('ALTER TABLE schedule_requests ADD COLUMN IF NOT EXISTS target_entry_id BIGINT');
    $pdo->exec('ALTER TABLE schedule_requests ADD COLUMN IF NOT EXISTS request_date TEXT');
    $pdo->exec('ALTER TABLE schedule_requests ADD COLUMN IF NOT EXISTS requested_end_time TEXT');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $offeringRequired = $pdo->query("SELECT attnotnull FROM pg_attribute WHERE attrelid='schedule_requests'::regclass AND attname='offering_id' AND NOT attisdropped")->fetchColumn();
        if ($offeringRequired) {
            $pdo->exec('ALTER TABLE schedule_requests ALTER COLUMN offering_id DROP NOT NULL');
        }
        $requestTypeConstraint = $pdo->query("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid='schedule_requests'::regclass AND conname='schedule_requests_request_type_check'")->fetchColumn();
        if ($requestTypeConstraint === false || !str_contains((string) $requestTypeConstraint, "'OTHER'")) {
            $pdo->exec('ALTER TABLE schedule_requests DROP CONSTRAINT IF EXISTS schedule_requests_request_type_check');
            $pdo->exec("ALTER TABLE schedule_requests ADD CONSTRAINT schedule_requests_request_type_check CHECK (request_type IN ('ROOM', 'CHANGE', 'OTHER'))");
        }
    }
    $pdo->exec('ALTER TABLE schedule_entries ADD COLUMN IF NOT EXISTS start_time_override TEXT');
    $pdo->exec('ALTER TABLE schedule_entries ADD COLUMN IF NOT EXISTS end_time_override TEXT');
    $pdo->exec('ALTER TABLE schedule_entries ADD COLUMN IF NOT EXISTS room_fit_override INTEGER NOT NULL DEFAULT 0');
    $pdo->exec("ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS severity TEXT NOT NULL DEFAULT 'INFO'");
    $pdo->exec("ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS outcome TEXT NOT NULL DEFAULT 'SUCCESS'");
    $pdo->exec('ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS request_path TEXT');
    $pdo->exec('ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS http_method TEXT');
    $pdo->exec('ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS source_ip INET');
    $pdo->exec('ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS user_agent TEXT');
    $pdo->exec('ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS correlation_id TEXT');
    $pdo->exec('ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS occurred_at_utc TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_occurred_at ON audit_logs(occurred_at_utc DESC, id DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_user ON audit_logs(user_id, occurred_at_utc DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs(action, occurred_at_utc DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_severity ON audit_logs(severity, occurred_at_utc DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_outcome ON audit_logs(outcome, occurred_at_utc DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs(entity_type, entity_id, occurred_at_utc DESC)');
}

function normalize_institution_branding(PDO $pdo): void
{
    $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'institution_name' AND setting_value = ?");
    $stmt->execute(['New Sinai School and Colleges Sta. Rosa, Inc.', 'Sinao Colleges']);

    $demoEmails = [
        'respende@sinao.edu' => 'respende@example.invalid',
        'tuazon@sinao.edu' => 'tuazon@example.invalid',
        'guarino@sinao.edu' => 'guarino@example.invalid',
        'payos@sinao.edu' => 'payos@example.invalid',
        'santos@sinao.edu' => 'santos@example.invalid',
        'cruz@sinao.edu' => 'cruz@example.invalid',
        'admin@sinao.edu' => 'admin@example.invalid',
        'scheduler@sinao.edu' => 'scheduler@example.invalid',
        'student@sinao.edu' => 'student@example.invalid',
    ];
    foreach ($demoEmails as $oldEmail => $newEmail) {
        $updateInstructor = $pdo->prepare('UPDATE instructors SET email = ? WHERE email = ?');
        $updateInstructor->execute([$newEmail, $oldEmail]);
        $updateUser = $pdo->prepare('UPDATE users SET email = ? WHERE email = ?');
        $updateUser->execute([$newEmail, $oldEmail]);
    }
}

function faculty_roster(): array
{
    return [
        'Lisette Cruz',
        'Regina Marie Tuquib',
        'Janine Marciflor N. Villarama',
        'Joniel S. Cerbito',
        'James Erran Almodovar',
        'Divina Abelardo',
        'Jerome T. Estabillo',
        'Minerva Diana Dichoso',
        'Wilbert S. Umbina',
        'Kate Lourence Abao',
        'Pamela Joy Celimen',
        'Marvin Diadula',
        'El B Fernandez',
        'Dr. Mathew Buera',
        'Marlon Javier',
        'Glazel Biaculo',
        'Hedda Rose Vida Lamigas De La Cruz',
        'Daryl Batino',
        'Chris Ellazar',
        'Olive Lucero Bautista',
        'Ronnel Abigail D. Maneclang',
        'Aurora Roslin Samosa',
        'May Ann S. Chozas',
        'Dennis Lasola Petate',
        'Bernardo Fernando II',
        'Dynn D.C. Solomon',
        'Christelle Joy C. Cubangbang',
        'Kawthar R. Casasola',
        'Christian Klein Serrano Magsino',
        'Antonina R. Rapada',
        'Rochelle R. Dayego',
    ];
}

function normalize_instructor_catalog(PDO $pdo): void
{
    $migrationKey = 'faculty_roster_31_v1';
    $migration = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $migration->execute([$migrationKey]);
    $migrationComplete = $migration->fetchColumn() === '1';

    $findByEmployeeNo = $pdo->prepare('SELECT 1 FROM instructors WHERE employee_no = ? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO instructors (employee_no, name, email, max_hours_day, active) VALUES (?, ?, NULL, 6, 1)');
    $rosterIds = [];
    foreach (faculty_roster() as $index => $name) {
        $findExisting = $pdo->prepare('SELECT id FROM instructors WHERE name = ? ORDER BY id LIMIT 1');
        $findExisting->execute([$name]);
        $id = $findExisting->fetchColumn();
        if ($id !== false) {
            $id = (int) $id;
            $pdo->prepare('UPDATE instructors SET name = ?, active = 1 WHERE id = ?')->execute([$name, $id]);
        } else {
            $employeeNoIndex = $index + 1;
            do {
                $employeeNo = 'FAC-' . str_pad((string) $employeeNoIndex, 3, '0', STR_PAD_LEFT);
                $findByEmployeeNo->execute([$employeeNo]);
                $employeeNoIndex++;
            } while ($findByEmployeeNo->fetchColumn() !== false);
            $insert->execute([$employeeNo, $name]);
            $id = db_insert_id($pdo, 'instructors');
        }
        $rosterIds[] = $id;
    }

    if (!$migrationComplete) {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $placeholders = implode(', ', array_fill(0, count($rosterIds), '?'));
            $legacyQuery = $pdo->prepare("SELECT id FROM instructors WHERE id NOT IN ({$placeholders}) ORDER BY id");
            $legacyQuery->execute($rosterIds);
            $legacyIds = array_map('intval', $legacyQuery->fetchAll(PDO::FETCH_COLUMN));

            $updateOccupancy = $pdo->prepare("UPDATE schedule_occupancy so SET resource_id = ? FROM schedule_entries se JOIN course_offerings co ON co.id = se.offering_id WHERE so.entry_id = se.id AND so.resource_type = 'INSTRUCTOR' AND co.instructor_id = ?");
            $updateOfferings = $pdo->prepare('UPDATE course_offerings SET instructor_id = ? WHERE instructor_id = ?');
            $updateUsers = $pdo->prepare('UPDATE users SET instructor_id = ? WHERE instructor_id = ?');
            $updateRequests = $pdo->prepare('UPDATE schedule_requests SET instructor_id = ? WHERE instructor_id = ?');
            $updateAvailability = $pdo->prepare('UPDATE instructor_availability SET instructor_id = ? WHERE instructor_id = ?');
            $updatePreferences = $pdo->prepare('UPDATE instructor_time_preferences SET instructor_id = ? WHERE instructor_id = ?');

            foreach ($legacyIds as $index => $legacyId) {
                $replacementId = $rosterIds[$index % count($rosterIds)];
                $updateOccupancy->execute([$replacementId, $legacyId]);
                $updateOfferings->execute([$replacementId, $legacyId]);
                $updateUsers->execute([$replacementId, $legacyId]);
                $updateRequests->execute([$replacementId, $legacyId]);
                $updateAvailability->execute([$replacementId, $legacyId]);
                $updatePreferences->execute([$replacementId, $legacyId]);
            }

            if ($legacyIds !== []) {
                $legacyPlaceholders = implode(', ', array_fill(0, count($legacyIds), '?'));
                $deleteLegacy = $pdo->prepare("DELETE FROM instructors WHERE id IN ({$legacyPlaceholders})");
                $deleteLegacy->execute($legacyIds);
            }

            $pdo->exec('UPDATE instructors SET active = 0 WHERE active <> 0');
            $activate = $pdo->prepare('UPDATE instructors SET active = 1 WHERE id = ?');
            foreach ($rosterIds as $id) {
                $activate->execute([$id]);
            }
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, '1') ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value")->execute([$migrationKey]);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    } else {
        $pdo->exec('UPDATE instructors SET active = 0 WHERE active <> 0');
        $activate = $pdo->prepare('UPDATE instructors SET active = 1 WHERE id = ?');
        foreach ($rosterIds as $id) {
            $activate->execute([$id]);
        }
    }
}

function normalize_program_catalog(PDO $pdo): void
{
    $programs = [
        ['BSRT', 'Bachelor of Science in Radiologic Technology', 'BSIT'],
        ['BSP', 'Bachelor of Science in Psychology', 'BSED'],
        ['BSN', 'Bachelor of Science in Nursing', null],
        ['BSMT', 'Bachelor of Science in Medical Technology', null],
    ];

    foreach ($programs as [$code, $name, $legacyCode]) {
        $stmt = $pdo->prepare('SELECT id FROM programs WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();

        if (!$id && $legacyCode !== null) {
            $stmt->execute([$legacyCode]);
            $id = $stmt->fetchColumn();
        }

        if ($id) {
            $update = $pdo->prepare('UPDATE programs SET code = ?, name = ?, active = 1 WHERE id = ?');
            $update->execute([$code, $name, $id]);
        } else {
            $insert = $pdo->prepare('INSERT INTO programs (code, name, active) VALUES (?, ?, 1)');
            $insert->execute([$code, $name]);
        }
    }

    $pdo->exec("UPDATE programs SET active = 0 WHERE code IN ('BSIT', 'BSED')");
}

function normalize_section_catalog(PDO $pdo): void
{
    $termId = $pdo->query('SELECT id FROM academic_terms WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
    if (!$termId) return;

    $migrationKey = 'single_section_catalog_v1';
    $migration = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $migration->execute([$migrationKey]);
    if ($migration->fetchColumn() !== '1') {
        $pdo->prepare("UPDATE schedule_runs SET status = 'ARCHIVED' WHERE term_id = ? AND status <> 'ARCHIVED'")->execute([(int) $termId]);
    }

    $programs = $pdo->query("SELECT id, code FROM programs WHERE active = 1 AND code IN ('BSN', 'BSP', 'BSRT', 'BSMT') ORDER BY code")->fetchAll();
    $canonical = [];
    foreach ($programs as $program) {
        for ($year = 1; $year <= 4; $year++) {
            foreach (['A'] as $suffix) {
                $code = $program['code'] . '-' . $year . $suffix;
                $stmt = $pdo->prepare('SELECT id FROM sections WHERE term_id = ? AND code = ? LIMIT 1');
                $stmt->execute([$termId, $code]);
                $id = $stmt->fetchColumn();
                if ($id) {
                    $pdo->prepare('UPDATE sections SET program_id = ?, year_level = ?, student_count = ?, active = 1 WHERE id = ?')->execute([(int) $program['id'], $year, 40, $id]);
                } else {
                    $pdo->prepare('INSERT INTO sections (program_id, term_id, code, year_level, student_count, active) VALUES (?, ?, ?, ?, ?, 1)')->execute([(int) $program['id'], $termId, $code, $year, 40]);
                    $id = db_insert_id($pdo, 'sections');
                }
                $canonical[(int) $program['id'] . ':' . $year . ':' . $suffix] = (int) $id;
            }
        }
    }

    foreach ($programs as $program) {
        for ($year = 1; $year <= 4; $year++) {
            $stmt = $pdo->prepare('SELECT id, code FROM sections WHERE program_id = ? AND term_id = ? AND year_level = ? AND active = 1');
            $stmt->execute([(int) $program['id'], $termId, $year]);
            foreach ($stmt->fetchAll() as $section) {
                $suffix = in_array(substr((string) $section['code'], -1), ['A', 'B'], true) ? substr((string) $section['code'], -1) : null;
                $targetId = $canonical[(int) $program['id'] . ':' . $year . ':A'] ?? null;
                if ($targetId && (int) $section['id'] !== $targetId) {
                    $offerings = $pdo->prepare("SELECT id, subject_id, instructor_id, enrollment, required_meetings FROM course_offerings WHERE section_id = ? AND status = 'ACTIVE'");
                    $offerings->execute([(int) $section['id']]);
                    $duplicate = $pdo->prepare('SELECT id, status FROM course_offerings WHERE term_id = ? AND subject_id = ? AND section_id = ? LIMIT 1');
                    foreach ($offerings->fetchAll() as $offering) {
                        $duplicate->execute([$termId, (int) $offering['subject_id'], $targetId]);
                        $existing = $duplicate->fetch();
                        if ($existing && $existing['status'] === 'ACTIVE') {
                            $pdo->prepare("UPDATE course_offerings SET status = 'INACTIVE' WHERE id = ?")->execute([(int) $offering['id']]);
                        } elseif ($existing) {
                            $pdo->prepare("UPDATE course_offerings SET instructor_id = ?, enrollment = ?, required_meetings = ?, status = 'ACTIVE' WHERE id = ?")->execute([(int) $offering['instructor_id'], (int) $offering['enrollment'], (int) $offering['required_meetings'], (int) $existing['id']]);
                            $pdo->prepare("UPDATE course_offerings SET status = 'INACTIVE' WHERE id = ?")->execute([(int) $offering['id']]);
                        } else {
                            $pdo->prepare('UPDATE course_offerings SET section_id = ? WHERE id = ?')->execute([$targetId, (int) $offering['id']]);
                        }
                    }
                    $pdo->prepare('UPDATE pending_registrations SET section_id = ? WHERE section_id = ?')->execute([$targetId, (int) $section['id']]);
                    $pdo->prepare("UPDATE users SET section_id = ? WHERE role = 'student' AND section_id = ?")->execute([$targetId, (int) $section['id']]);
                    $pdo->prepare('UPDATE sections SET active = 0 WHERE id = ?')->execute([(int) $section['id']]);
                } elseif (!$targetId) {
                    $pdo->prepare('UPDATE sections SET active = 0 WHERE id = ?')->execute([(int) $section['id']]);
                }
            }
        }
    }

    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, '1') ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value")->execute([$migrationKey]);

    $staleStudents = $pdo->query("SELECT id, section_id FROM users WHERE role = 'student' AND active = 1")->fetchAll();
    foreach ($staleStudents as $userRow) {
        $currentSection = (int) $userRow['section_id'];
        $validCheck = $pdo->prepare("SELECT sec.id FROM sections sec JOIN course_offerings co ON co.section_id = sec.id AND co.term_id = ? AND co.status = 'ACTIVE' WHERE sec.id = ? AND sec.active = 1 GROUP BY sec.id LIMIT 1");
        $validCheck->execute([$termId, $currentSection]);
        if ($validCheck->fetchColumn() !== false) {
            continue;
        }

        $canonicalSectionId = $pdo->prepare("SELECT sec.id FROM sections sec JOIN course_offerings co ON co.section_id = sec.id AND co.term_id = ? AND co.status = 'ACTIVE' WHERE sec.active = 1 GROUP BY sec.id ORDER BY sec.id LIMIT 1");
        $canonicalSectionId->execute([$termId]);
        $fallbackSectionId = $canonicalSectionId->fetchColumn();
        if ($fallbackSectionId === false) {
            $pdo->prepare("UPDATE users SET section_id = NULL WHERE id = ? AND role = 'student'")->execute([(int) $userRow['id']]);
            continue;
        }

        $pdo->prepare("UPDATE users SET section_id = ? WHERE id = ? AND role = 'student'")->execute([(int) $fallbackSectionId, (int) $userRow['id']]);
    }
}

function normalize_room_catalog(PDO $pdo): void
{
    $lectureCapacity = max(40, (int) ($pdo->query('SELECT COALESCE(MAX(student_count), 40) FROM sections WHERE active = 1')->fetchColumn() ?: 40));
    $rooms = [
        ['RLE', 'Related Learning Experience (RLE)', 'SPECIAL', 40, ['Projector']],
        ['NSL', 'Nursing Skills Laboratory', 'LAB', 40, ['Projector']],
        ['MTL', 'Medical Technology Laboratory', 'LAB', 40, ['Computers', 'Projector']],
        ['RTL', 'Radiologic Technology Laboratory', 'LAB', 40, ['Projector']],
        ['PSL', 'Psychology Laboratory', 'LAB', 40, ['Projector']],
        ['COMLAB', 'Computer Laboratory', 'LAB', 40, ['Computers', 'Projector']],
        ['LR', 'Lecture Room', 'LECTURE', $lectureCapacity, ['Projector']],
        ['LR-1', 'Lecture Room 1', 'LECTURE', $lectureCapacity, ['Projector']],
        ['LR-2', 'Lecture Room 2', 'LECTURE', $lectureCapacity, ['Projector']],
        ['LR-3', 'Lecture Room 3', 'LECTURE', $lectureCapacity, ['Projector']],
        ['LR-4', 'Lecture Room 4', 'LECTURE', $lectureCapacity, ['Projector']],
    ];
    $legacyCodes = ['ROOM-201', 'COMLAB-1', 'COMLAB-2', 'COMLAB-3', 'ROOM-202', 'LH-A', 'LH-B'];

    foreach ($rooms as $index => [$code, $name, $type, $capacity, $features]) {
        $stmt = $pdo->prepare('SELECT id FROM rooms WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        if (!$id && isset($legacyCodes[$index])) {
            $stmt->execute([$legacyCodes[$index]]);
            $id = $stmt->fetchColumn();
        }
        if ($id) {
            $update = $pdo->prepare('UPDATE rooms SET code = ?, name = ?, capacity = ?, room_type = ?, features_json = ?, active = 1 WHERE id = ?');
            $update->execute([$code, $name, $capacity, $type, json_encode($features, JSON_THROW_ON_ERROR), $id]);
        } else {
            $insert = $pdo->prepare('INSERT INTO rooms (code, name, capacity, room_type, features_json, active) VALUES (?, ?, ?, ?, ?, 1)');
            $insert->execute([$code, $name, $capacity, $type, json_encode($features, JSON_THROW_ON_ERROR)]);
        }
    }
}

function normalize_subject_catalog(PDO $pdo): void
{
    $migrationKey = 'clean_subject_catalog_v2';
    $migration = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $migration->execute([$migrationKey]);

    if ($migration->fetchColumn() !== '1') {
        $subjects = [
            ['ANA/PHY', 'Human Anatomy and Physiology with Pathophysiology'],
            ['ANA/PHY R', 'Human Anatomy and Physiology'],
            ['AUBF', 'Analysis of Urine and Other Bodily Fluids'],
            ['BACT 1', 'Clinical Bacteriology'],
            ['BPSY 101', 'Introduction to Psychology'],
            ['BPSY 102', 'Psychological Statistics'],
            ['CC 1', 'Clinical Chemistry 1'],
            ['CHEM 1', 'Inorganic and Organic Chemistry'],
            ['CHEM 3', 'Biochemistry for Medical Laboratory Science'],
            ['CL 1', 'Clinical Internship 1 - Hospital Affiliation'],
            ['COMP', 'Health Information System for Med Lab Science'],
            ['COMP', 'Living in the IT Era'],
            ['CYTO', 'Cytogenetics'],
            ['ELECTIVE 1', "Sinai's History and Values Formation"],
            ['ELECTIVE 3', 'Reading Visual Arts'],
            ['ENG 1', 'Purposive Communication'],
            ['ENG 3', 'The Contemporary World'],
            ['GE 1', 'Understanding the Self'],
            ['GE 1', 'Mathematics in the Modern World'],
            ['GE 2', 'Readings in Philippine History'],
            ['GE 1', 'Environmental Science'],
            ['HEMA 1', 'Hematology 1'],
            ['HISTOPATH', 'Histopathologic and Cytologic Techniques'],
            ['LM', 'Laboratory Management'],
            ['MATH 2', 'Biostatistics and Epidemiology'],
            ['MC 1', 'Anatomy and Physiology'],
            ['MC 2', 'Biochemistry'],
            ['MED SCI 1', 'Principles of Medical Laboratory Science 1'],
            ['MOLBIO', 'Molecular Biology and Diagnostics'],
            ['MTAP 1', 'Medical Technology Assessment Program 1'],
            ['NATSCI 2', 'General & Inorganic Chemistry'],
            ['NCM 100', 'Theoretical Foundation of Nursing Practice'],
            ['NCM 104', 'Community Health Nursing 1'],
            ['NCM 105', 'Nutrition and Diet Therapy'],
            ['NCM 106', 'Pharmacology'],
            ['NCM 107', 'Care of Mother, Child, Adolescent (Well)'],
            ['NCM 108', 'Health Care Ethics (Bioethics)'],
            ['NCM 111', 'Nursing Research 1'],
            ['NCM 112', 'Care of Clients w/ Problems in Oxygenation, Fluids & Electrolytes, Infectious, Inflammatory & Immunological Conditions'],
            ['NCM 113', 'Community Health Nursing 2'],
            ['NCM 114', 'Care of Older Adults'],
            ['NSTP 1', 'Civic Welfare Training Service 1'],
            ['PATHFIT 1', 'Movement Competency Training'],
            ['PATHFIT 3', 'Dance'],
            ['PSTHE', 'Principles and Strategies of Teaching in Medical Laboratory Science'],
            ['PSY 1', 'Developmental Psychology'],
            ['RESEARCH 1', 'Introduction to Medical Laboratory Science Research'],
            ['INTRO-RESEARCH', 'Introduction to Research'],
            ['RIZAL', 'Life and Works of Rizal'],
            ['RT 101', 'Introduction to Radiologic Technology and Health Care'],
            ['RT 201', 'Imaging Science and Informatics'],
            ['RT 202', 'Radiation Production and Characteristics'],
            ['RT 203', 'Principles of Imaging'],
            ['RT 204', 'Professional Ethics, Juris, and Cultural Sensitivity'],
            ['RT 301', 'Radiographic Positioning and Radiographic Procedure 1'],
            ['RT 302', 'Radiologic Contrast Examination'],
            ['RT 303', 'Ultrasonography'],
            ['RT 304', 'Administration, Leadership, and Entrepreneurship'],
            ['RT 305', 'Radiation Protection'],
            ['RT 306', 'Radiation Pathology'],
            ['RT 307', 'Quality Assurance and Quality Control'],
            ['RTCE 1', 'Clinical Education 1 (5 1/2 months)'],
            ['SEM 1', 'Seminar 1'],
        ];
        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE course_offerings SET status = 'INACTIVE' WHERE status = 'ACTIVE'");
            $pdo->exec('UPDATE subjects SET active = 0 WHERE active = 1');
            $insert = $pdo->prepare("INSERT INTO subjects (code, name, units, hours_per_week, duration_slots, room_type, required_features_json, active) VALUES (?, ?, 3, 2, 2, 'LECTURE', '[]', 1)");
            $seenNames = [];
            foreach ($subjects as [$code, $name]) {
                $normalizedName = strtolower(preg_replace('/[\s\p{P}]+/u', '', $name) ?? $name);
                if (isset($seenNames[$normalizedName])) {
                    continue;
                }
                $seenNames[$normalizedName] = true;
                $insert->execute([$code, $name]);
            }
            $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)')->execute([$migrationKey, '1']);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    $pdo->prepare("UPDATE subjects SET code = 'ANA/PHY R' WHERE active = 1 AND code = 'ANA/PHY: R' AND name = 'Human Anatomy and Physiology'")->execute();
    $pdo->exec(
        "CREATE UNIQUE INDEX IF NOT EXISTS uq_subjects_active_code_name_nocase
         ON subjects (
             LOWER(REGEXP_REPLACE(BTRIM(code), '[[:space:]:]+', '', 'g')),
             LOWER(REGEXP_REPLACE(BTRIM(name), '[[:space:][:punct:]]+', '', 'g'))
         )
         WHERE active = 1"
    );
    $pdo->exec(
        "CREATE UNIQUE INDEX IF NOT EXISTS uq_subjects_active_name_nocase
         ON subjects (LOWER(REGEXP_REPLACE(BTRIM(name), '[[:space:][:punct:]]+', '', 'g')))
         WHERE active = 1"
    );
}

function normalize_time_slot_catalog(PDO $pdo): void
{
    $slots = [
        ['H01', '7:00 AM - 8:00 AM', '07:00', '08:00', 1],
        ['H02', '8:00 AM - 9:00 AM', '08:00', '09:00', 2],
        ['H03', '9:00 AM - 10:00 AM', '09:00', '10:00', 3],
        ['H04', '10:00 AM - 11:00 AM', '10:00', '11:00', 4],
        ['H05', '11:00 AM - 12:00 PM', '11:00', '12:00', 5],
        ['H06', '12:00 PM - 1:00 PM', '12:00', '13:00', 6],
        ['H07', '1:00 PM - 2:00 PM', '13:00', '14:00', 7],
        ['H08', '2:00 PM - 3:00 PM', '14:00', '15:00', 8],
        ['H09', '3:00 PM - 4:00 PM', '15:00', '16:00', 9],
        ['H10', '4:00 PM - 5:00 PM', '16:00', '17:00', 10],
    ];

    foreach ($slots as [$code, $label, $start, $end, $order]) {
        $stmt = $pdo->prepare('SELECT id FROM time_slots WHERE slot_order = ? LIMIT 1');
        $stmt->execute([$order]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $pdo->prepare('UPDATE time_slots SET code = ?, label = ?, start_time = ?, end_time = ? WHERE id = ?')->execute([$code, $label, $start, $end, $id]);
        } else {
            $pdo->prepare('INSERT INTO time_slots (code, label, start_time, end_time, slot_order) VALUES (?, ?, ?, ?, ?)')->execute([$code, $label, $start, $end, $order]);
        }
    }
}

function db_insert_id(PDO $pdo, string $table): int
{
    $statement = $pdo->prepare("SELECT currval(pg_get_serial_sequence(?, 'id'))");
    $statement->execute([$table]);
    return (int) $statement->fetchColumn();
}

function seed_database(PDO $pdo): void
{
    $hasUser = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    if ($hasUser) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $term = $pdo->prepare('INSERT INTO academic_terms (academic_year, semester, is_active) VALUES (?, ?, 1)');
        $term->execute(['2026-2027', 'First Semester']);
        $termId = db_insert_id($pdo, 'academic_terms');

        $program = $pdo->prepare('INSERT INTO programs (code, name) VALUES (?, ?)');
        $program->execute(['BSRT', 'Bachelor of Science in Radiologic Technology']);
        $bsrt = db_insert_id($pdo, 'programs');
        $program->execute(['BSP', 'Bachelor of Science in Psychology']);
        $bsp = db_insert_id($pdo, 'programs');
        $program->execute(['BSN', 'Bachelor of Science in Nursing']);
        $bsn = db_insert_id($pdo, 'programs');
        $program->execute(['BSMT', 'Bachelor of Science in Medical Technology']);
        $bsmt = db_insert_id($pdo, 'programs');

        $instructor = $pdo->prepare('INSERT INTO instructors (employee_no, name, email, max_hours_day) VALUES (?, ?, NULL, 6)');
        $instructors = faculty_roster();
        $instructorIds = [];
        foreach ($instructors as $index => $name) {
            $employeeNo = 'FAC-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
            $instructor->execute([$employeeNo, $name]);
            $instructorIds[] = db_insert_id($pdo, 'instructors');
        }

        $room = $pdo->prepare('INSERT INTO rooms (code, name, capacity, room_type, features_json) VALUES (?, ?, ?, ?, ?)');
        $rooms = [
            ['RLE', 'Related Learning Experience (RLE)', 40, 'SPECIAL', ['Projector']],
            ['NSL', 'Nursing Skills Laboratory', 40, 'LAB', ['Projector']],
            ['MTL', 'Medical Technology Laboratory', 40, 'LAB', ['Computers', 'Projector']],
            ['RTL', 'Radiologic Technology Laboratory', 40, 'LAB', ['Projector']],
            ['PSL', 'Psychology Laboratory', 40, 'LAB', ['Projector']],
            ['COMLAB', 'Computer Laboratory', 40, 'LAB', ['Computers', 'Projector']],
            ['LR', 'Lecture Room', 40, 'LECTURE', ['Projector']],
        ];
        foreach ($rooms as [$code, $name, $capacity, $type, $features]) {
            $room->execute([$code, $name, $capacity, $type, json_encode($features, JSON_THROW_ON_ERROR)]);
        }

        $slot = $pdo->prepare('INSERT INTO time_slots (code, label, start_time, end_time, slot_order) VALUES (?, ?, ?, ?, ?)');
        $slots = [
            ['H01', '7:00 AM - 8:00 AM', '07:00', '08:00', 1],
            ['H02', '8:00 AM - 9:00 AM', '08:00', '09:00', 2],
            ['H03', '9:00 AM - 10:00 AM', '09:00', '10:00', 3],
            ['H04', '10:00 AM - 11:00 AM', '10:00', '11:00', 4],
            ['H05', '11:00 AM - 12:00 PM', '11:00', '12:00', 5],
            ['H06', '12:00 PM - 1:00 PM', '12:00', '13:00', 6],
            ['H07', '1:00 PM - 2:00 PM', '13:00', '14:00', 7],
            ['H08', '2:00 PM - 3:00 PM', '14:00', '15:00', 8],
            ['H09', '3:00 PM - 4:00 PM', '15:00', '16:00', 9],
            ['H10', '4:00 PM - 5:00 PM', '16:00', '17:00', 10],
        ];
        foreach ($slots as $row) {
            $slot->execute($row);
        }

        $section = $pdo->prepare('INSERT INTO sections (program_id, term_id, code, year_level, student_count) VALUES (?, ?, ?, ?, ?)');
        $sectionRows = [];
        foreach ([[$bsn, 'BSN'], [$bsp, 'BSP'], [$bsrt, 'BSRT'], [$bsmt, 'BSMT']] as [$programId, $programCode]) {
            for ($year = 1; $year <= 4; $year++) {
                foreach (['A', 'B'] as $suffix) {
                    $sectionRows[] = [$programId, $termId, $programCode . '-' . $year . $suffix, $year, 40];
                }
            }
        }
        $sectionIds = [];
        foreach ($sectionRows as $row) {
            $section->execute($row);
            $sectionIds[] = db_insert_id($pdo, 'sections');
        }

        $studentSectionId = (int) $pdo->query("SELECT sec.id FROM sections sec JOIN course_offerings co ON co.section_id = sec.id WHERE co.term_id = {$termId} AND co.status = 'ACTIVE' GROUP BY sec.id ORDER BY sec.id LIMIT 1")->fetchColumn();
        if ($studentSectionId < 1) {
            $studentSectionId = (int) $sectionIds[0];
        }

        $user = $pdo->prepare('INSERT INTO users (username, password_hash, display_name, email, role, instructor_id, section_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $user->execute(['admin', password_hash('Admin123!', PASSWORD_DEFAULT), 'System Administrator', 'admin@example.invalid', 'admin', null, null]);
        $user->execute(['scheduler', password_hash('Scheduler123!', PASSWORD_DEFAULT), 'Scheduling Coordinator', 'scheduler@example.invalid', 'scheduler', null, null]);
        $user->execute(['instructor', password_hash('Instructor123!', PASSWORD_DEFAULT), 'Prof. Respende', 'respende@example.invalid', 'instructor', $instructorIds[0], null]);
        $user->execute(['student', password_hash('Student123!', PASSWORD_DEFAULT), 'BSIT Student', 'student@example.invalid', 'student', null, $studentSectionId]);

        $settings = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
        $settings->execute(['institution_name', 'New Sinai School and Colleges Sta. Rosa, Inc.']);
        $settings->execute(['system_name', 'EasySched']);
        $settings->execute(['active_term_id', (string) $termId]);
        $settings->execute(['generation_node_limit', '100000']);

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function ensure_schedule_term_options(PDO $pdo): void
{
    $insert = $pdo->prepare(
        'INSERT INTO academic_terms (academic_year, semester, is_active) VALUES (?, ?, 0)
         ON CONFLICT (academic_year, semester) DO NOTHING'
    );
    for ($year = 2018; $year <= 2025; $year++) {
        $academicYear = sprintf('%d-%d', $year, $year + 1);
        foreach (['First Semester', 'Second Semester'] as $semester) {
            $insert->execute([$academicYear, $semester]);
        }
    }
}

function ensure_schedule_history_samples(PDO $pdo): void
{
    $sampleRows = [
        ['subject_code' => 'SAMPLE101', 'subject_name' => 'Sample Foundations of Computing', 'section_code' => 'SAMPLE-1A', 'instructor_name' => 'Sample Instructor A', 'room_code' => 'SAMPLE-101', 'day_of_week' => 1, 'slot_order' => 1, 'room_type' => 'LECTURE'],
        ['subject_code' => 'SAMPLE102', 'subject_name' => 'Sample Communication Skills', 'section_code' => 'SAMPLE-1A', 'instructor_name' => 'Sample Instructor B', 'room_code' => 'SAMPLE-102', 'day_of_week' => 2, 'slot_order' => 3, 'room_type' => 'LECTURE'],
        ['subject_code' => 'SAMPLE201', 'subject_name' => 'Sample Applied Mathematics', 'section_code' => 'SAMPLE-2A', 'instructor_name' => 'Sample Instructor C', 'room_code' => 'SAMPLE-201', 'day_of_week' => 3, 'slot_order' => 5, 'room_type' => 'LECTURE'],
        ['subject_code' => 'SAMPLE202', 'subject_name' => 'Sample Laboratory Practice', 'section_code' => 'SAMPLE-2A', 'instructor_name' => 'Sample Instructor D', 'room_code' => 'SAMPLE-LAB', 'day_of_week' => 4, 'slot_order' => 7, 'room_type' => 'LAB'],
    ];
    $sampleJson = encode_json($sampleRows);
    $insert = $pdo->prepare(
        'INSERT INTO schedule_history_samples (term_id, schedules_json) VALUES (?, ?)
         ON CONFLICT (term_id) DO NOTHING'
    );
    $terms = $pdo->query(
        "SELECT id, academic_year, semester FROM academic_terms
         WHERE academic_year BETWEEN '2018-2019' AND '2025-2026'
         AND semester IN ('First Semester', 'Second Semester')"
    )->fetchAll();
    foreach ($terms as $term) {
        $insert->execute([(int) $term['id'], $sampleJson]);
    }
}

function json_array(?string $value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? array_values($decoded) : [];
}

function encode_json(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
