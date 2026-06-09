<?php
ob_start();
session_start();
require 'db.php';

// Auto-load libraries if available
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/* ══════════════════════════════════════════════════
   ENSURE TABLES EXIST
   ══════════════════════════════════════════════════ */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS labs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        capacity INT DEFAULT 40,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Add this near the other CREATE TABLE statements in admin.php (after line ~20)
    try { $pdo->exec("ALTER TABLE labs ADD COLUMN software_pdf VARCHAR(255) DEFAULT NULL"); } catch(Exception $e){}
    $pdo->exec("CREATE TABLE IF NOT EXISTS uploaded_files (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        admin_name    VARCHAR(100) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name   VARCHAR(255) NOT NULL,
        file_type     VARCHAR(100) NOT NULL,
        file_size     INT NOT NULL COMMENT 'bytes',
        category      VARCHAR(80)  DEFAULT 'general',
        description   TEXT,
        download_count INT DEFAULT 0,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_category (category)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lab_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    lab_id INT NOT NULL,
    pc_number INT DEFAULT NULL,
    software_used TEXT DEFAULT NULL,
    time_in DATETIME NOT NULL,
    time_out DATETIME DEFAULT NULL,
    status ENUM('active','completed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_student (student_id),
    KEY idx_lab (lab_id)
)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_name VARCHAR(100),
        action_type VARCHAR(50),
        description TEXT,
        entity_type VARCHAR(50),
        entity_id INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $cnt = $pdo->query("SELECT COUNT(*) FROM labs")->fetchColumn();
    if ($cnt == 0) {
        $defaults = ['Lab 524','Lab 526','Lab 528','Lab 530','Lab 542','Lab 544'];
        $ins = $pdo->prepare("INSERT IGNORE INTO labs (name) VALUES (?)");
        foreach ($defaults as $l) $ins->execute([$l]);
    }
    // PC seats table
    $pdo->exec("CREATE TABLE IF NOT EXISTS pc_seats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lab_id INT NOT NULL,
        pc_number INT NOT NULL,
        label VARCHAR(20) DEFAULT NULL,
        is_functional TINYINT(1) DEFAULT 1,
        UNIQUE KEY uq_lab_pc (lab_id, pc_number)
    )");
    // Reservations: add pc_number and admin_assigned_pc columns if missing
    try { $pdo->exec("ALTER TABLE reservations ADD COLUMN pc_number INT DEFAULT NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE reservations ADD COLUMN admin_pc INT DEFAULT NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE reservations ADD COLUMN rejection_reason VARCHAR(255) DEFAULT NULL"); } catch(Exception $e){}
    // System settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value VARCHAR(255) NOT NULL DEFAULT '1',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('reservation_enabled', '1')");
} catch(Exception $e){}

function logActivity($pdo, $action_type, $description, $entity_type='', $entity_id=0) {
    global $_SESSION;
    $admin = $_SESSION['admin_name'] ?? 'Admin';
    try {
        $pdo->prepare("INSERT INTO activity_logs (admin_name,action_type,description,entity_type,entity_id) VALUES (?,?,?,?,?)")
            ->execute([$admin, $action_type, $description, $entity_type, $entity_id]);
    } catch(Exception $e){}
}

/* ══════════════════════════════════════════════════
   AJAX HANDLERS
   ══════════════════════════════════════════════════ */

/* ── Search Student ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'search_student') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $id = trim($_POST['id_number'] ?? '');
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Enter an ID number.']); exit; }
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id_number = ?");
    $stmt->execute([$id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) { echo json_encode(['success'=>false,'message'=>'No student found with ID: '.htmlspecialchars($id)]); exit; }
    $yr = [1=>'1st Year',2=>'2nd Year',3=>'3rd Year',4=>'4th Year'];
    $used = 0; $last = '—';
    try { $used = $pdo->query("SELECT COUNT(*) FROM sitins WHERE student_id={$s['id']} AND status='done'")->fetchColumn(); } catch(Exception $e){}
    try { $lr = $pdo->query("SELECT time_in FROM sitins WHERE student_id={$s['id']} ORDER BY time_in DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC); if($lr) $last = date('M d, Y', strtotime($lr['time_in'])); } catch(Exception $e){}
    echo json_encode([
        'success'       => true,
        'student_db_id' => $s['id'],
        'id_number'     => $s['id_number'],
        'name'          => $s['last_name'].', '.$s['first_name'].(!empty($s['middle_name'])?' '.$s['middle_name']:''),
        'first_name'    => $s['first_name'],
        'last_name'     => $s['last_name'],
        'course'        => $s['course'],
        'year'          => $yr[$s['year_level']] ?? $s['year_level'].' Year',
        'email'         => $s['email'],
        'address'       => $s['address'] ?? '—',
        'photo'         => !empty($s['profile_photo']) ? $s['profile_photo'] : null,
        'sessions'      => $s['remaining_session'] ?? 30,
        'used'          => $used,
        'lastSitin'     => $last,
    ]);
    exit;
}

/* ── Log Sit-in (Manual) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'log_sitin') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $student_db_id = intval($_POST['student_db_id'] ?? 0);
    $purpose       = trim($_POST['purpose'] ?? '');
    $lab           = trim($_POST['lab'] ?? '');
    $pc_number     = intval($_POST['pc_number'] ?? 0) ?: null;
    if (!$student_db_id || !$purpose || !$lab) { echo json_encode(['success'=>false,'message'=>'Please fill in all fields.']); exit; }
    $check = $pdo->prepare("SELECT id FROM sitins WHERE student_id=? AND status='active'");
    $check->execute([$student_db_id]);
    if ($check->fetch()) { echo json_encode(['success'=>false,'message'=>'Student is already sitting in.']); exit; }
    $sess = $pdo->prepare("SELECT remaining_session FROM students WHERE id=?");
    $sess->execute([$student_db_id]);
    $row = $sess->fetch(PDO::FETCH_ASSOC);
    $remaining = $row['remaining_session'] ?? 30;
    if ($remaining <= 0) { echo json_encode(['success'=>false,'message'=>'No remaining sessions.']); exit; }
    // Check if selected PC is already occupied
    if ($pc_number) {
        $pcChk = $pdo->prepare("SELECT id FROM sitins WHERE lab=? AND pc_number=? AND status='active'");
        $pcChk->execute([$lab, $pc_number]);
        if ($pcChk->fetch()) { echo json_encode(['success'=>false,'message'=>'PC-'.str_pad($pc_number,2,'0',STR_PAD_LEFT).' is already in use. Please choose another.']); exit; }
    }
    $pdo->prepare("INSERT INTO sitins (student_id,purpose,lab,pc_number,remaining_session,status,time_in) VALUES (?,?,?,?,?,'active',NOW())")->execute([$student_db_id,$purpose,$lab,$pc_number,$remaining]);
    $pdo->prepare("UPDATE students SET remaining_session=remaining_session-1 WHERE id=?")->execute([$student_db_id]);
    $pcLabel = $pc_number ? ' — PC-'.str_pad($pc_number,2,'0',STR_PAD_LEFT) : '';
    logActivity($pdo, 'SITIN_CREATED', "Sit-in logged for student ID $student_db_id — Lab: $lab$pcLabel, Purpose: $purpose", 'sitin', $student_db_id);
    echo json_encode(['success'=>true,'message'=>'Sit-in logged successfully!'.($pc_number ? ' PC-'.str_pad($pc_number,2,'0',STR_PAD_LEFT).' assigned.' : '')]);
    exit;
}

/* ── Add Announcement ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'add_announcement') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $text = trim($_POST['announcement'] ?? '');
    if (!$text) { echo json_encode(['success'=>false,'message'=>'Announcement cannot be empty.']); exit; }
    $stmt = $pdo->prepare("INSERT INTO announcements (admin_name, content) VALUES (?, ?)");
    $stmt->execute([$_SESSION['admin_name'] ?? 'CCS Admin', $text]);
    echo json_encode(['success'=>true,'message'=>'Announcement posted!']);
    exit;
}

/* ── Reset All Sessions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'reset_sessions') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $pdo->exec("UPDATE students SET remaining_session = 30");
    echo json_encode(['success'=>true,'message'=>'All sessions reset to 30.']);
    exit;
}

/* ── Reset All Sit-in Records ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'reset_sitin_records') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $confirm = trim($_POST['confirm'] ?? '');
    if ($confirm !== 'RESET') { echo json_encode(['success'=>false,'message'=>'Confirmation text did not match.']); exit; }
    try {
        $pdo->exec("DELETE FROM sitins");
        $pdo->exec("UPDATE students SET remaining_session = 30");
        logActivity($pdo, 'RECORDS_RESET', 'All sit-in records were wiped and sessions reset to 30 by admin.', 'system', 0);
        echo json_encode(['success'=>true,'message'=>'All sit-in records cleared and sessions reset to 30.']);
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
    }
    exit;
}

/* ── Delete Student ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete_student') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $id = intval($_POST['student_id'] ?? 0);
    $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$id]);
    echo json_encode(['success'=>true,'message'=>'Student deleted.']);
    exit;
}

/* ── Edit Student ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'edit_student') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $id         = intval($_POST['student_id']  ?? 0);
    $first_name = trim($_POST['first_name']    ?? '');
    $last_name  = trim($_POST['last_name']     ?? '');
    $course     = trim($_POST['course']        ?? '');
    $year_level = intval($_POST['year_level']  ?? 1);
    $sessions   = intval($_POST['remaining_session'] ?? 30);
    $stmt = $pdo->prepare("UPDATE students SET first_name=?,last_name=?,course=?,year_level=?,remaining_session=? WHERE id=?");
    $stmt->execute([$first_name,$last_name,$course,$year_level,$sessions,$id]);
    echo json_encode(['success'=>true,'message'=>'Student updated.']);
    exit;
}

/* ── Add Student ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'add_student') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $id_number   = trim($_POST['id_number'] ?? '');
    $first_name  = trim($_POST['first_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $course      = trim($_POST['course'] ?? 'BSIT');
    $year_level  = intval($_POST['year_level'] ?? 1);
    $email       = trim($_POST['email'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $password    = password_hash('password123', PASSWORD_DEFAULT);
    
    if (!$id_number || !$first_name || !$last_name) {
        echo json_encode(['success'=>false,'message'=>'ID Number, First Name, and Last Name are required.']);
        exit;
    }
    
    $check = $pdo->prepare("SELECT id FROM students WHERE id_number = ?");
    $check->execute([$id_number]);
    if ($check->fetch()) {
        echo json_encode(['success'=>false,'message'=>'ID Number already exists.']);
        exit;
    }
    
    $stmt = $pdo->prepare("INSERT INTO students (id_number, first_name, last_name, middle_name, course, year_level, email, address, password, remaining_session) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 30)");
    $stmt->execute([$id_number, $first_name, $last_name, $middle_name, $course, $year_level, $email, $address, $password]);
    
    echo json_encode(['success'=>true,'message'=>'Student added successfully! Default password: password123']);
    exit;
}

/* ── Get Students (for real-time table refresh) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'get_students') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    try {
        $rows = $pdo->query("SELECT id, id_number, first_name, last_name, year_level, course, remaining_session FROM students ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'students'=>$rows]);
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

/* ── End Sit-in ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'end_sitin') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $id = intval($_POST['sitin_id'] ?? 0);

    // Get the sitin details so we can find the matching reservation
    $sitinRow = $pdo->prepare("SELECT student_id, lab, purpose FROM sitins WHERE id=?");
    $sitinRow->execute([$id]);
    $sitinData = $sitinRow->fetch(PDO::FETCH_ASSOC);

    // End the sit-in
    $pdo->prepare("UPDATE sitins SET status='done', time_out=NOW() WHERE id=?")->execute([$id]);

    // Mark the matching approved/pending reservation as done
    if ($sitinData && $sitinData['student_id']) {
        $pdo->prepare("
            UPDATE reservations
            SET status = 'done'
            WHERE student_id = ?
              AND status IN ('approved', 'pending')
            ORDER BY date ASC
            LIMIT 1
        ")->execute([$sitinData['student_id']]);
    }

    echo json_encode(['success'=>true,'message'=>'Sit-in ended.']);
    exit;
}

/* ── Get Lab Seat Map ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'get_lab_seats') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $lab_name = trim($_POST['lab_name'] ?? '');
    $date     = trim($_POST['date']     ?? '');
    $timein   = trim($_POST['time_in']  ?? '');
    $excl_rsv = intval($_POST['exclude_reservation_id'] ?? 0);
    if (!$lab_name) { echo json_encode(['success'=>false,'message'=>'Lab name required.']); exit; }
    try {
        $lab = $pdo->prepare("SELECT * FROM labs WHERE name=?"); $lab->execute([$lab_name]);
        $labRow = $lab->fetch(PDO::FETCH_ASSOC);
        if (!$labRow) { echo json_encode(['success'=>false,'message'=>'Lab not found.']); exit; }
        $capacity = (int)($labRow['capacity'] ?? 40);
        $lab_id   = (int)$labRow['id'];

        // Ensure seat rows exist
        $existing = $pdo->prepare("SELECT COUNT(*) FROM pc_seats WHERE lab_id=?");
        $existing->execute([$lab_id]);
        $existingCount = $existing->fetchColumn();
        if ($existingCount < $capacity) {
            $ins = $pdo->prepare("INSERT IGNORE INTO pc_seats (lab_id, pc_number, label) VALUES (?,?,?)");
            for ($i = 1; $i <= $capacity; $i++) $ins->execute([$lab_id, $i, 'PC-'.str_pad($i,2,'0',STR_PAD_LEFT)]);
        }

        $seats = $pdo->prepare("SELECT * FROM pc_seats WHERE lab_id=? ORDER BY pc_number ASC");
        $seats->execute([$lab_id]);
        $seatsData = $seats->fetchAll(PDO::FETCH_ASSOC);

        // Reserved PCs for this lab/date/time (exclude current reservation being processed)
        $reservedPcs = [];
        if ($date) {
            $q = "SELECT pc_number, admin_pc, status, id FROM reservations WHERE lab=? AND date=? AND status IN ('pending','approved')";
            $params = [$lab_name, $date];
            if ($excl_rsv) { $q .= " AND id != ?"; $params[] = $excl_rsv; }
            $rsvStmt = $pdo->prepare($q); $rsvStmt->execute($params);
            foreach ($rsvStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pc = $r['admin_pc'] ?: $r['pc_number'];
                if ($pc) $reservedPcs[] = (int)$pc;
            }
        }

        // Active sit-ins for this lab
        $inUsePcs = [];
        $sitinStmt = $pdo->prepare("SELECT pc_number FROM sitins WHERE lab=? AND status='active'");
        $sitinStmt->execute([$lab_name]);
        foreach ($sitinStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            if ($s['pc_number']) $inUsePcs[] = (int)$s['pc_number'];
        }

        foreach ($seatsData as &$s) {
            $pn = (int)$s['pc_number'];
            if (!$s['is_functional']) $s['status'] = 'unavailable';
            elseif (in_array($pn, $inUsePcs)) $s['status'] = 'in_use';
            elseif (in_array($pn, $reservedPcs)) $s['status'] = 'reserved';
            else $s['status'] = 'available';
        }
        unset($s);
        echo json_encode(['success'=>true,'seats'=>$seatsData,'capacity'=>$capacity,'lab_id'=>$lab_id]);
    } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

/* ── Toggle PC Functional ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'toggle_pc_functional') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $lab_id = intval($_POST['lab_id'] ?? 0);
    $pc_num = intval($_POST['pc_number'] ?? 0);
    $functional = intval($_POST['is_functional'] ?? 1);
    $pdo->prepare("UPDATE pc_seats SET is_functional=? WHERE lab_id=? AND pc_number=?")->execute([$functional, $lab_id, $pc_num]);
    echo json_encode(['success'=>true]);
    exit;
}

/* ── Get Pending Reservations ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'get_pending_reservations') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false]); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, s.first_name, s.last_name, s.id_number as student_id_number, 
                   s.course, s.email, s.id as student_db_id
            FROM reservations r
            JOIN students s ON r.student_id = s.id
            WHERE r.status = 'pending'
            ORDER BY r.date ASC, r.time_in ASC
        ");
        $stmt->execute();
        $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'reservations' => $reservations]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'reservations' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

/* ── Approve Reservation ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'approve_reservation') {
    error_reporting(0);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $id = intval($_POST['reservation_id'] ?? 0);
    $admin_pc = intval($_POST['admin_pc'] ?? 0);
    $admin_lab = trim($_POST['admin_lab'] ?? ''); // admin may override lab
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid reservation.']); exit; }

    try {
        $stmt = $pdo->prepare("
            SELECT r.*, s.remaining_session, s.first_name, s.last_name,
                   s.id_number AS student_id_number, s.course, s.email, s.id as student_db_id
            FROM reservations r
            JOIN students s ON r.student_id = s.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $rsv = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rsv) { echo json_encode(['success'=>false,'message'=>'Reservation not found.']); exit; }
        if ($rsv['status'] === 'approved') { echo json_encode(['success'=>false,'message'=>'Already approved.']); exit; }

        // Determine final lab (admin override takes priority)
        $final_lab = $admin_lab ?: $rsv['lab'];

        // If lab was changed by admin, reset student's PC choice
        if ($admin_lab && $admin_lab !== $rsv['lab']) {
            $admin_pc = $admin_pc; // keep admin-selected PC, ignore student's original PC
        }

        // Conflict check on assigned PC in the final lab
        if ($admin_pc) {
            $conflictStmt = $pdo->prepare("SELECT id FROM reservations WHERE lab=? AND date=? AND status IN ('approved') AND (admin_pc=? OR pc_number=?) AND id != ?");
            $conflictStmt->execute([$final_lab, $rsv['date'], $admin_pc, $admin_pc, $id]);
            if ($conflictStmt->fetch()) {
                echo json_encode(['success'=>false,'message'=>'PC-'.str_pad($admin_pc,2,'0',STR_PAD_LEFT).' is already reserved for this date in '.$final_lab.'. Please choose another PC.']);
                exit;
            }
        }

        $final_pc = $admin_pc ?: (($admin_lab && $admin_lab !== $rsv['lab']) ? null : ($rsv['pc_number'] ?: null));

        // Update lab and pc in reservation
        $pdo->prepare("UPDATE reservations SET status='approved', lab=?, admin_pc=? WHERE id=?")->execute([$final_lab, $final_pc, $id]);

        $sitin_created = false;
        $sitin_data    = null;
        $reservationDate = $rsv['date'];
        $today = date('Y-m-d');

        if ($reservationDate === $today) {
            $check = $pdo->prepare("SELECT id FROM sitins WHERE student_id=? AND status='active'");
            $check->execute([$rsv['student_id']]);
            if (!$check->fetch()) {
                $remaining = $rsv['remaining_session'] ?? 30;
                if ($remaining > 0) {
                    $pdo->prepare("INSERT INTO sitins (student_id, purpose, lab, pc_number, remaining_session, status, time_in) VALUES (?,?,?,?,?,'active',NOW())")
                        ->execute([$rsv['student_id'], $rsv['purpose'], $final_lab, $final_pc, $remaining]);
                    $pdo->prepare("UPDATE students SET remaining_session=remaining_session-1 WHERE id=?")->execute([$rsv['student_id']]);
                    $sitin_created = true;
                    $sitinId = $pdo->lastInsertId();
                    $sitStmt = $pdo->prepare("SELECT s.id, s.purpose, s.lab, s.pc_number, s.remaining_session, s.time_in, st.first_name, st.last_name, st.id_number as student_id_number FROM sitins s JOIN students st ON s.student_id = st.id WHERE s.id = ?");
                    $sitStmt->execute([$sitinId]);
                    $sitin_data = $sitStmt->fetch(PDO::FETCH_ASSOC);
                    logActivity($pdo, 'SITIN_CREATED', "Auto sit-in from approved reservation #$id for student {$rsv['first_name']} {$rsv['last_name']} — Lab: $final_lab, PC: ".($final_pc?'PC-'.str_pad($final_pc,2,'0',STR_PAD_LEFT):'—'), 'sitin', $rsv['student_id']);
                }
            }
        }

        $labNote = ($admin_lab && $admin_lab !== $rsv['lab']) ? " (Lab changed to $final_lab)" : '';
        logActivity($pdo, 'RESERVATION_APPROVED', "Reservation #$id approved for student {$rsv['first_name']} {$rsv['last_name']} — Lab: $final_lab$labNote, PC: ".($final_pc?'PC-'.str_pad($final_pc,2,'0',STR_PAD_LEFT):'—').', Date: '.$rsv['date'], 'reservation', $id);
        echo json_encode([
            'success' => true,
            'message' => $sitin_created ? 'Reservation approved and student automatically checked in!' : 'Reservation approved.',
            'sitin_created' => $sitin_created,
            'sitin_data'    => $sitin_data,
            'student_name'  => $rsv['first_name'].' '.$rsv['last_name'],
            'student_id'    => $rsv['student_db_id'],
            'lab'           => $final_lab,
            'purpose'       => $rsv['purpose'],
            'assigned_pc'   => $final_pc,
            'lab_changed'   => ($admin_lab && $admin_lab !== $rsv['lab']),
        ]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
    }
    exit;
}

/* ── Reject Reservation with reason ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'reject_reservation') {
    error_reporting(0);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $id     = intval($_POST['reservation_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid reservation ID.']); exit; }
    try {
        $pdo->prepare("UPDATE reservations SET status='cancelled', rejection_reason=? WHERE id=?")->execute([$reason, $id]);
        logActivity($pdo, 'RESERVATION_DENIED', "Reservation #$id was denied by admin".($reason?" — Reason: $reason":''), 'reservation', $id);
        echo json_encode(['success'=>true,'message'=>'Reservation denied.']);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

/* ── Get Labs ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'get_labs') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false]); exit; }
    try {
        $labs = $pdo->query("SELECT * FROM labs ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true, 'labs'=>$labs]);
    } catch(Exception $e) { echo json_encode(['success'=>false, 'labs'=>[], 'error'=>$e->getMessage()]); }
    exit;
}

/* ── Add Lab ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'add_lab') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $name = trim($_POST['lab_name'] ?? '');
    $capacity = intval($_POST['lab_capacity'] ?? 40);
    if (!$name) { echo json_encode(['success'=>false,'message'=>'Lab name required.']); exit; }
    try {
        $pdo->prepare("INSERT INTO labs (name, capacity) VALUES (?,?)")->execute([$name, $capacity]);
        $lab_id = $pdo->lastInsertId();
        logActivity($pdo, 'LAB_ADDED', "New lab added: $name (Capacity: $capacity)", 'lab', $lab_id);
        echo json_encode(['success'=>true,'message'=>"Lab '$name' added successfully.",'id'=>$lab_id]);
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>'Lab name already exists.']);
    }
    exit;
}

/* ── Delete Lab ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete_lab') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $id = intval($_POST['lab_id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid lab.']); exit; }
    try {
        $lab = $pdo->prepare("SELECT name FROM labs WHERE id=?"); $lab->execute([$id]);
        $lrow = $lab->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("DELETE FROM labs WHERE id=?")->execute([$id]);
        logActivity($pdo, 'LAB_DELETED', "Lab removed: ".($lrow['name']??'Unknown'), 'lab', $id);
        echo json_encode(['success'=>true,'message'=>'Lab deleted.']);
    } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>'Could not delete lab.']); }
    exit;
}

/* ── Toggle Lab Active ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'toggle_lab') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $id = intval($_POST['lab_id'] ?? 0);
    $active = intval($_POST['is_active'] ?? 1);
    $pdo->prepare("UPDATE labs SET is_active=? WHERE id=?")->execute([$active, $id]);
    $lab = $pdo->prepare("SELECT name FROM labs WHERE id=?"); $lab->execute([$id]);
    $lrow = $lab->fetch(PDO::FETCH_ASSOC);
    $statusStr = $active ? 'enabled' : 'disabled';
    logActivity($pdo, 'LAB_CHANGED', "Lab ".($lrow['name']??'#'.$id)." was $statusStr by admin", 'lab', $id);
    echo json_encode(['success'=>true]);
    exit;
}

/* ── Toggle Reservation System ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'toggle_reservation_system') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $enabled = intval($_POST['enabled'] ?? 1);
    try {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('reservation_enabled', ?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$enabled, $enabled]);
        $statusStr = $enabled ? 'enabled' : 'disabled';
        logActivity($pdo, 'SYSTEM_SETTING', "Reservation system was $statusStr by admin", 'setting', 0);
        echo json_encode(['success'=>true,'enabled'=>$enabled,'message'=>'Reservation system '.$statusStr.'.']);
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>'Could not update setting: '.$e->getMessage()]);
    }
    exit;
}

/* ── Get Reservation System Status ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'get_reservation_status') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    try {
        $val = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='reservation_enabled'")->fetchColumn();
        echo json_encode(['success'=>true,'enabled'=>(intval($val) === 1)]);
    } catch(Exception $e) {
        echo json_encode(['success'=>true,'enabled'=>true]);
    }
    exit;
}

/* ── Edit Lab ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'edit_lab') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $id       = intval($_POST['lab_id']       ?? 0);
    $name     = trim($_POST['lab_name']       ?? '');
    $capacity = intval($_POST['lab_capacity'] ?? 40);
    if (!$id || !$name) { echo json_encode(['success'=>false,'message'=>'Lab ID and name are required.']); exit; }
    try {
        $pdo->prepare("UPDATE labs SET name=?, capacity=? WHERE id=?")->execute([$name, $capacity, $id]);
        logActivity($pdo, 'LAB_CHANGED', "Lab #$id updated: name='$name', capacity=$capacity", 'lab', $id);
        echo json_encode(['success'=>true,'message'=>"Lab updated successfully.",'name'=>$name,'capacity'=>$capacity]);
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>'Lab name already exists or update failed.']);
    }
    exit;
}

/* ── Upload File ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'upload_file') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $allowed_types = [
        'application/pdf'                                                          => 'pdf',
        'application/msword'                                                       => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel'                                                 => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
        'application/vnd.ms-powerpoint'                                            => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'=> 'pptx',
        'text/plain'                                                               => 'txt',
        'image/jpeg'                                                               => 'jpg',
        'image/png'                                                                => 'png',
        'image/gif'                                                                => 'gif',
        'image/webp'                                                               => 'webp',
        'application/zip'                                                          => 'zip',
        'application/x-zip-compressed'                                             => 'zip',
    ];
    $max_size = 20 * 1024 * 1024; // 20MB
    $category    = trim($_POST['file_category']    ?? 'general');
    $description = trim($_POST['file_description'] ?? '');
    if (empty($_FILES['upload_file']) || $_FILES['upload_file']['error'] !== UPLOAD_ERR_OK) {
        $err_codes = [1=>'File too large (server limit).',2=>'File too large.',3=>'Partial upload.',4=>'No file selected.',6=>'Missing temp folder.',7=>'Write permission denied.'];
        $code = $_FILES['upload_file']['error'] ?? 4;
        echo json_encode(['success'=>false,'message'=>$err_codes[$code] ?? 'Upload failed.']); exit;
    }
    $file = $_FILES['upload_file'];
    if ($file['size'] > $max_size) { echo json_encode(['success'=>false,'message'=>'File too large (max 20MB).']); exit; }
    // MIME check via finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!array_key_exists($mime, $allowed_types)) {
        echo json_encode(['success'=>false,'message'=>"File type '$mime' is not allowed. Allowed: PDF, Word, Excel, PowerPoint, TXT, images, ZIP."]); exit;
    }
    $ext         = $allowed_types[$mime];
    $upload_dir  = __DIR__ . '/uploads/files/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $stored_name = 'file_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $stored_name)) {
        $writable = is_writable($upload_dir) ? 'yes' : 'no';
        $exists   = is_dir($upload_dir) ? 'yes' : 'no';
        echo json_encode(['success'=>false,'message'=>"Failed to save file. Dir exists: $exists, Writable: $writable, Path: $upload_dir"]); exit;
    }
    try {
        $pdo->prepare("INSERT INTO uploaded_files (admin_name, original_name, stored_name, file_type, file_size, category, description) VALUES (?,?,?,?,?,?,?)")
            ->execute([$_SESSION['admin_name'] ?? 'Admin', $file['name'], $stored_name, $mime, $file['size'], $category, $description]);
        $new_id = $pdo->lastInsertId();
        logActivity($pdo, 'FILE_UPLOADED', "File uploaded: {$file['name']} ({$ext}, ".round($file['size']/1024,1)." KB) — Category: $category", 'file', $new_id);
        echo json_encode(['success'=>true,'message'=>'File uploaded successfully!','id'=>$new_id,'name'=>$file['name'],'size'=>$file['size'],'type'=>$ext,'category'=>$category]);
    } catch(Exception $e) {
        @unlink($upload_dir . $stored_name);
        echo json_encode(['success'=>false,'message'=>'Database error: '.$e->getMessage()]);
    }
    exit;
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'get_files') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false]); exit; }
    $category = trim($_POST['category'] ?? 'all');
    try {
        $sql  = "SELECT * FROM uploaded_files";
        $bind = [];
        if ($category !== 'all') { $sql .= " WHERE category = ?"; $bind[] = $category; }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($bind);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Add human-readable size and url
        foreach ($files as &$f) {
            $f['size_human'] = $f['file_size'] < 1048576 ? round($f['file_size']/1024,1).' KB' : round($f['file_size']/1048576,2).' MB';
            $f['url'] = 'uploads/files/' . $f['stored_name'];
            $f['exists'] = file_exists(__DIR__ . '/uploads/files/' . $f['stored_name']);
        }
        echo json_encode(['success'=>true,'files'=>$files]);
    } catch(Exception $e) { echo json_encode(['success'=>false,'files'=>[]]); }
    exit;
}

/* ── Delete File ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete_file') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $id = intval($_POST['file_id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid file ID.']); exit; }
    try {
        $row = $pdo->prepare("SELECT stored_name, original_name FROM uploaded_files WHERE id=?");
        $row->execute([$id]);
        $f = $row->fetch(PDO::FETCH_ASSOC);
        if ($f) {
            $path = __DIR__ . '/uploads/files/' . $f['stored_name'];
            if (file_exists($path)) @unlink($path);
        }
        $pdo->prepare("DELETE FROM uploaded_files WHERE id=?")->execute([$id]);
        logActivity($pdo, 'FILE_DELETED', "File deleted: ".($f['original_name']??'#'.$id), 'file', $id);
        echo json_encode(['success'=>true,'message'=>'File deleted.']);
    } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>'Could not delete file.']); }
    exit;
}

/* ── Replace File ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'replace_file') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $id = intval($_POST['file_id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid file ID.']); exit; }
    $allowed_types = ['application/pdf'=>'pdf','application/msword'=>'doc','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.ms-excel'=>'xls','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx','application/vnd.ms-powerpoint'=>'ppt','application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx','text/plain'=>'txt','image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','application/zip'=>'zip','application/x-zip-compressed'=>'zip'];
    if (empty($_FILES['upload_file']) || $_FILES['upload_file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['upload_file']['error'] ?? 4;
        $err_codes = [1=>'File too large.',2=>'File too large.',3=>'Partial upload.',4=>'No file selected.',6=>'Missing temp folder.',7=>'Write permission denied.'];
        echo json_encode(['success'=>false,'message'=>$err_codes[$code] ?? 'Upload failed.']); exit;
    }
    $file = $_FILES['upload_file'];
    if ($file['size'] > 20 * 1024 * 1024) { echo json_encode(['success'=>false,'message'=>'File too large (max 20MB).']); exit; }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!array_key_exists($mime, $allowed_types)) { echo json_encode(['success'=>false,'message'=>"File type not allowed."]); exit; }
    $ext = $allowed_types[$mime];
    $upload_dir  = __DIR__ . '/uploads/files/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    try {
        // Delete old file from disk
        $old = $pdo->prepare("SELECT stored_name FROM uploaded_files WHERE id=?");
        $old->execute([$id]);
        $oldRow = $old->fetch(PDO::FETCH_ASSOC);
        if ($oldRow && file_exists($upload_dir . $oldRow['stored_name'])) @unlink($upload_dir . $oldRow['stored_name']);
        // Save new file
        $stored_name = 'file_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $upload_dir . $stored_name)) {
            echo json_encode(['success'=>false,'message'=>'Failed to save file.']); exit;
        }
        $pdo->prepare("UPDATE uploaded_files SET original_name=?, stored_name=?, file_type=?, file_size=? WHERE id=?")
            ->execute([$file['name'], $stored_name, $mime, $file['size'], $id]);
        logActivity($pdo, 'FILE_REPLACED', "File replaced: {$file['name']}", 'file', $id);
        echo json_encode(['success'=>true,'message'=>'File replaced successfully!']);
    } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>'Database error: '.$e->getMessage()]); }
    exit;
}

/* ── Download File (increment counter) ── */
if (isset($_GET['download_file'])) {
    $id = intval($_GET['download_file']);
    try {
        $row = $pdo->prepare("SELECT * FROM uploaded_files WHERE id=?");
        $row->execute([$id]);
        $f = $row->fetch(PDO::FETCH_ASSOC);
        if (!$f) { http_response_code(404); echo 'File not found.'; exit; }
        $path = __DIR__ . '/uploads/files/' . $f['stored_name'];
        if (!file_exists($path)) { http_response_code(404); echo 'File not found on server.'; exit; }
        $pdo->prepare("UPDATE uploaded_files SET download_count = download_count + 1 WHERE id=?")->execute([$id]);
        header('Content-Type: ' . $f['file_type']);
        header('Content-Disposition: attachment; filename="' . addslashes($f['original_name']) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-cache');
        readfile($path);
    } catch(Exception $e) { http_response_code(500); echo 'Server error.'; }
    exit;
}

/* ── Get Leaderboard (public — no admin auth required) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'get_leaderboard') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    try {
        $rows = $pdo->query("
            SELECT
                s.id,
                s.id_number,
                s.first_name,
                s.last_name,
                s.course,
                s.year_level,
                s.remaining_session,
                s.profile_photo,
                COUNT(si.id)                                                    AS total_sitins,
                COALESCE(SUM(TIMESTAMPDIFF(MINUTE, si.time_in, si.time_out)),0) AS total_minutes,
                MAX(si.time_in)                                                 AS last_seen,
                MIN(si.time_in)                                                 AS first_seen
            FROM students s
            LEFT JOIN sitins si ON si.student_id = s.id AND si.status = 'done' AND si.time_out IS NOT NULL
            GROUP BY s.id, s.id_number, s.first_name, s.last_name, s.course, s.year_level, s.remaining_session, s.profile_photo
            HAVING total_sitins > 0
            ORDER BY total_sitins DESC
            LIMIT 3
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $sid = (int)$r['id'];
            $hours = round($r['total_minutes'] / 60, 2);
            $sitin_pts = floor($r['total_sitins'] / 3);
            $r['total_hours']  = $hours;
            $r['sitin_points'] = $sitin_pts;
            $r['final_score']  = round(($sitin_pts * 0.5) + ($hours * 0.3), 2);
            $r['display_name'] = $r['first_name'] . ' ' . $r['last_name'];
            $yr = [1=>'1st Year',2=>'2nd Year',3=>'3rd Year',4=>'4th Year'];
            $r['year_label']   = $yr[$r['year_level']] ?? ($r['year_level'].' Year');
            $r['last_seen']    = $r['last_seen'] ? date('M d, Y', strtotime($r['last_seen'])) : '—';
            $r['first_seen']   = $r['first_seen'] ? date('M d, Y', strtotime($r['first_seen'])) : '—';

            // Most visited lab
            try {
                $ml = $pdo->prepare("SELECT lab, COUNT(*) AS cnt FROM sitins WHERE student_id=? AND status='done' AND lab IS NOT NULL GROUP BY lab ORDER BY cnt DESC LIMIT 1");
                $ml->execute([$sid]); $mlr = $ml->fetch(PDO::FETCH_ASSOC);
                $r['fav_lab']     = $mlr['lab']  ?? '—';
                $r['fav_lab_cnt'] = $mlr['cnt']  ?? 0;
            } catch(Exception $e) { $r['fav_lab']='—'; $r['fav_lab_cnt']=0; }

            // Most used purpose
            try {
                $mp = $pdo->prepare("SELECT purpose, COUNT(*) AS cnt FROM sitins WHERE student_id=? AND status='done' AND purpose IS NOT NULL GROUP BY purpose ORDER BY cnt DESC LIMIT 1");
                $mp->execute([$sid]); $mpr = $mp->fetch(PDO::FETCH_ASSOC);
                $r['fav_purpose'] = $mpr['purpose'] ?? '—';
            } catch(Exception $e) { $r['fav_purpose']='—'; }

            // Avg feedback rating & count
            try {
                $fb = $pdo->prepare("SELECT ROUND(AVG(f.rating),1) AS avg_rating, COUNT(f.id) AS feedback_count FROM feedback f WHERE f.student_id=?");
                $fb->execute([$sid]); $fbr = $fb->fetch(PDO::FETCH_ASSOC);
                $r['avg_rating']     = $fbr['avg_rating']     ?? null;
                $r['feedback_count'] = $fbr['feedback_count'] ?? 0;
            } catch(Exception $e) { $r['avg_rating']=null; $r['feedback_count']=0; }

            // Sessions used (30 - remaining)
            $r['sessions_used'] = max(0, 30 - (int)$r['remaining_session']);
        }
        unset($r);

        usort($rows, fn($a,$b) => $b['final_score'] <=> $a['final_score']);
        foreach ($rows as $i => &$r) { $r['rank'] = $i + 1; }
        echo json_encode(['success'=>true,'leaderboard'=>array_values($rows)]);
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'leaderboard'=>[],'error'=>$e->getMessage()]);
    }
    exit;
}

/* ── Fetch Feedback (real-time AJAX) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'fetch_feedback') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sitin_id INT NOT NULL,
            student_id INT NOT NULL,
            rating INT NOT NULL,
            comment TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $rating_filter = $_POST['rating'] ?? 'all';
        $search        = trim($_POST['search'] ?? '');
        $where = []; $binds = [];
        if ($rating_filter !== 'all') { $where[] = 'f.rating = ?'; $binds[] = intval($rating_filter); }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(s.first_name LIKE ? OR s.last_name LIKE ? OR s.id_number LIKE ? OR f.comment LIKE ? OR si.lab LIKE ? OR si.purpose LIKE ?)';
            array_push($binds, $like, $like, $like, $like, $like, $like);
        }
        $wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $rows = $pdo->prepare("
            SELECT f.id, f.rating, f.comment, f.created_at,
                   s.first_name, s.last_name, s.id_number as student_id_number, s.course,
                   si.lab, si.purpose
            FROM feedback f
            JOIN students s ON f.student_id = s.id
            LEFT JOIN sitins si ON f.sitin_id = si.id
            $wsql
            ORDER BY f.created_at DESC
            LIMIT 300
        ");
        $rows->execute($binds);
        $feedback = $rows->fetchAll(PDO::FETCH_ASSOC);

        $stats = $pdo->query("
            SELECT COUNT(*) as total,
                   ROUND(AVG(rating),1) as avg_rating,
                   COUNT(CASE WHEN rating >= 4 THEN 1 END) as positive,
                   COUNT(CASE WHEN rating <= 2 THEN 1 END) as negative
            FROM feedback
        ")->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['success'=>true,'feedback'=>$feedback,'stats'=>$stats]);
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'feedback'=>[],'error'=>$e->getMessage()]);
    }
    exit;
}

/* ── Upload Software PDF ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'upload_software_pdf') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $upload_dir = __DIR__ . '/uploads/software/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $upload_err = $_FILES['software_pdf']['error'] ?? 4;
    $err_codes  = [1=>'File too large (server limit).',2=>'File too large.',3=>'Partial upload.',4=>'No file selected.',6=>'Missing temp folder.',7=>'Write permission denied.'];
    if (empty($_FILES['software_pdf']) || $upload_err !== UPLOAD_ERR_OK) {
        echo json_encode(['success'=>false,'message'=> $err_codes[$upload_err] ?? 'Upload error code '.$upload_err]); exit;
    }
    $file = $_FILES['software_pdf'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') { echo json_encode(['success'=>false,'message'=>'Only PDF files are allowed.']); exit; }
    if ($file['size'] > 10 * 1024 * 1024) { echo json_encode(['success'=>false,'message'=>'File too large (max 10MB).']); exit; }
    $filename = 'software_list_' . date('Ymd_His') . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
        echo json_encode(['success'=>false,'message'=>'Failed to save file. Check server write permissions on /uploads/software/']); exit;
    }
    try {
        // Delete old PDF file from disk
        $oldPdf = $pdo->query("SELECT software_pdf FROM labs WHERE software_pdf IS NOT NULL AND software_pdf != '' LIMIT 1")->fetchColumn();
        if ($oldPdf && $oldPdf !== $filename && file_exists(__DIR__ . '/uploads/software/' . $oldPdf)) @unlink(__DIR__ . '/uploads/software/' . $oldPdf);
        // Ensure the column exists, then upsert
        try { $pdo->exec("ALTER TABLE labs ADD COLUMN software_pdf VARCHAR(255) DEFAULT NULL"); } catch(Exception $e){}
        $count = (int)$pdo->query("SELECT COUNT(*) FROM labs")->fetchColumn();
        if ($count === 0) {
            $pdo->prepare("INSERT INTO labs (name, capacity, software_pdf) VALUES ('Default Lab', 40, ?)")->execute([$filename]);
        } else {
            $pdo->prepare("UPDATE labs SET software_pdf = ?")->execute([$filename]);
        }
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]); exit;
    }
    logActivity($pdo, 'SOFTWARE_PDF_UPLOADED', "Software availability PDF updated: $filename", 'software_pdf', 0);
    echo json_encode(['success'=>true,'message'=>'PDF uploaded successfully!','filename'=>$filename]);
    exit;
}

/* ── Get Software PDF ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'get_software_pdf') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    try {
        $pdf = $pdo->query("SELECT software_pdf FROM labs WHERE software_pdf IS NOT NULL LIMIT 1")->fetchColumn();
        if ($pdf && file_exists(__DIR__ . '/uploads/software/' . $pdf)) {
            echo json_encode(['success'=>true,'url'=>'uploads/software/' . $pdf]);
        } else {
            echo json_encode(['success'=>false,'message'=>'No software list uploaded yet.']);
        }
    } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>'Error.']); }
    exit;
}

/* ── Get Activity Logs ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'get_activity_logs') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false]); exit; }
    try {
        $logs = $pdo->query("SELECT * FROM activity_logs WHERE entity_type='reservation' AND action_type IN ('RESERVATION_APPROVED','RESERVATION_DENIED') ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'logs'=>$logs]);
    } catch(Exception $e) { echo json_encode(['success'=>false,'logs'=>[]]); }
    exit;
}

/* ── Check Active Session ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'check_active_session') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $student_id = intval($_POST['student_id'] ?? 0);
    if (!$student_id) { echo json_encode(['success'=>false,'message'=>'Invalid student ID.']); exit; }
    $chkSitin = $pdo->prepare("SELECT id FROM sitins WHERE student_id=? AND status='active'");
    $chkSitin->execute([$student_id]);
    $activeSitin = $chkSitin->fetch(PDO::FETCH_ASSOC);
    $chkRsv = $pdo->prepare("SELECT id FROM reservations WHERE student_id=? AND status IN ('pending','approved')");
    $chkRsv->execute([$student_id]);
    $activeRsv = $chkRsv->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'success'          => true,
        'has_active_sitin' => (bool)$activeSitin,
        'has_active_rsv'   => (bool)$activeRsv,
        'blocked'          => (bool)$activeSitin || (bool)$activeRsv,
    ]);
    exit;
}

/* ── View Student Details ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'view_student_details') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }
    $student_id = intval($_POST['student_id'] ?? 0);
    if (!$student_id) { echo json_encode(['success'=>false,'message'=>'Invalid student ID.']); exit; }
    
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) { echo json_encode(['success'=>false,'message'=>'Student not found.']); exit; }
    
    $yr = [1=>'1st Year',2=>'2nd Year',3=>'3rd Year',4=>'4th Year'];
    $used = $pdo->query("SELECT COUNT(*) FROM sitins WHERE student_id={$student['id']} AND status='done'")->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'id_number' => $student['id_number'],
        'name' => $student['last_name'].', '.$student['first_name'].(!empty($student['middle_name'])?' '.$student['middle_name']:''),
        'course' => $student['course'],
        'year' => $yr[$student['year_level']] ?? $student['year_level'].' Year',
        'email' => $student['email'] ?? '—',
        'address' => $student['address'] ?? '—',
        'remaining_session' => $student['remaining_session'] ?? 30,
        'used_sessions' => $used,
        'photo' => !empty($student['profile_photo']) ? $student['profile_photo'] : null
    ]);
    exit;
}

/* ── Logout ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'logout') {
    session_destroy();
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success'=>true]);
    exit;
}

/* ── Poll Reservations ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'poll_reservations') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false]); exit; }
    try {
        $data = $pdo->query("
            SELECT r.id, r.student_id, r.lab, r.date, r.time_in, r.purpose, r.status, r.created_at,
                   r.pc_number, r.admin_pc,
                   s.first_name, s.last_name, s.id_number as student_id_number, s.course, s.email, s.id as student_db_id
            FROM reservations r
            JOIN students s ON r.student_id = s.id
            ORDER BY
                CASE r.status WHEN 'pending' THEN 1 WHEN 'approved' THEN 2 ELSE 3 END,
                r.date ASC, r.time_in ASC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'reservations' => $data]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'reservations' => []]);
    }
    exit;
}

/* ── Poll Live Data ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'poll_live') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in'])) { echo json_encode(['success'=>false]); exit; }
    $data = ['success' => true];
    try {
        $data['total']       = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $data['currently']   = (int)$pdo->query("SELECT COUNT(*) FROM sitins WHERE status='active'")->fetchColumn();
        $data['total_sitin'] = (int)$pdo->query("SELECT COUNT(*) FROM sitins")->fetchColumn();
        $data['sitins']      = $pdo->query("
            SELECT s.id, s.purpose, s.lab, s.remaining_session, s.time_in,
                   st.first_name, st.last_name, st.id_number as student_id_number
            FROM sitins s
            JOIN students st ON s.student_id = st.id
            WHERE s.status = 'active'
            ORDER BY s.time_in DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $data['records']     = $pdo->query("
            SELECT s.id, s.purpose, s.lab, s.time_in, s.time_out, s.status,
                   st.first_name, st.last_name, st.id_number as student_id_number, st.id as student_id
            FROM sitins s
            JOIN students st ON s.student_id = st.id
            ORDER BY s.time_in DESC LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $data['error'] = $e->getMessage(); }
    echo json_encode($data);
    exit;
}

/* ══════════════════════════════════════════════════
   EXPORT HANDLERS
   ══════════════════════════════════════════════════ */
if (isset($_GET['export'])) {
    $export_type = $_GET['export'] ?? '';
    $export_format = $_GET['format'] ?? 'csv';
    
    function outputCSV($data, $headers, $filename) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($data as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
    
    function outputXLSX($data, $headers, $sheetName, $filename) {
        try {
            if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $col = 'A';
                foreach ($headers as $header) {
                    $sheet->setCellValue($col . '1', $header);
                    $sheet->getStyle($col . '1')->getFont()->setBold(true);
                    $sheet->getStyle($col . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
                    $col++;
                }
                $row = 2;
                foreach ($data as $data_row) {
                    $col = 'A';
                    foreach ($data_row as $value) {
                        $sheet->setCellValue($col . $row, $value);
                        $col++;
                    }
                    $row++;
                }
                foreach (range('A', chr(ord('A') + count($headers) - 1)) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.xlsx"');
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            } else {
                outputCSV($data, $headers, $filename);
            }
        } catch (Exception $e) {
            outputCSV($data, $headers, $filename);
        }
    }
    
    function outputPDF($data, $headers, $title, $filename) {
        try {
            if (class_exists('TCPDF')) {
                $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
                $pdf->SetCreator('CCS Sit-in System');
                $pdf->SetAuthor('Admin Portal');
                $pdf->SetTitle($title);
                $pdf->setHeaderFont(['helvetica', '', 10]);
                $pdf->setFooterFont(['helvetica', '', 8]);
                $pdf->SetDefaultMonospacedFont('courier');
                $pdf->SetMargins(15, 20, 15);
                $pdf->SetHeaderMargin(10);
                $pdf->SetFooterMargin(10);
                $pdf->SetAutoPageBreak(TRUE, 25);
                $pdf->AddPage();
                
                $html = '<table border="1" cellpadding="4" style="border-collapse: collapse; width: 100%;"><thead><tr>';
                foreach ($headers as $header) {
                    $html .= '<th><strong>' . htmlspecialchars($header) . '</strong></th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($data as $row_data) {
                    $html .= '<tr>';
                    foreach ($row_data as $value) {
                        $html .= '<td>' . htmlspecialchars($value) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                $pdf->writeHTML($html, true, false, true, false, '');
                $pdf->Output($filename . '_' . date('Y-m-d') . '.pdf', 'D');
                exit;
            } else {
                outputCSV($data, $headers, $filename);
            }
        } catch (Exception $e) {
            outputCSV($data, $headers, $filename);
        }
    }
    
    // Export Reservations
    if ($export_type === 'reservations') {
        $status_filter = $_GET['status'] ?? 'all';
        $date_from = $_GET['date_from'] ?? '';
        $date_to   = $_GET['date_to']   ?? '';
        $where = []; $binds = [];
        if ($status_filter !== 'all') { $where[] = 'r.status = ?'; $binds[] = $status_filter; }
        if ($date_from) { $where[] = 'DATE(r.created_at) >= ?'; $binds[] = $date_from; }
        if ($date_to)   { $where[] = 'DATE(r.created_at) <= ?'; $binds[] = $date_to; }
        $sql = "SELECT r.*, s.first_name, s.last_name, s.id_number as student_id_number, s.course, s.email 
                FROM reservations r JOIN students s ON r.student_id = s.id"
             . ($where ? ' WHERE '.implode(' AND ', $where) : '')
             . " ORDER BY r.created_at DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($binds);
        $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $formatted_data = [];
        foreach ($export_data as $row) {
            $formatted_data[] = [
                'Reservation ID' => $row['id'],
                'Student ID' => $row['student_id_number'],
                'Student Name' => $row['first_name'] . ' ' . $row['last_name'],
                'Course' => $row['course'],
                'Email' => $row['email'],
                'Lab' => $row['lab'],
                'Reservation Date' => $row['date'],
                'Time In' => $row['time_in'],
                'Purpose' => $row['purpose'],
                'Status' => $row['status'],
                'Created At' => $row['created_at']
            ];
        }
        $headers = ['Reservation ID', 'Student ID', 'Student Name', 'Course', 'Email', 'Lab', 'Reservation Date', 'Time In', 'Purpose', 'Status', 'Created At'];
        if ($export_format === 'csv') outputCSV($formatted_data, $headers, 'reservations_export');
        elseif ($export_format === 'xlsx') outputXLSX($formatted_data, $headers, 'Reservations', 'reservations_export');
        elseif ($export_format === 'pdf') outputPDF($formatted_data, $headers, 'Reservations Report', 'reservations_export');
        exit;
    }
    
    // Export Feedback
    if ($export_type === 'feedback') {
        $rating_filter = $_GET['rating'] ?? 'all';
        $date_from = $_GET['date_from'] ?? '';
        $date_to   = $_GET['date_to']   ?? '';
        $where = []; $binds = [];
        if ($rating_filter !== 'all') { $where[] = 'f.rating = ?'; $binds[] = intval($rating_filter); }
        if ($date_from) { $where[] = 'DATE(f.created_at) >= ?'; $binds[] = $date_from; }
        if ($date_to)   { $where[] = 'DATE(f.created_at) <= ?'; $binds[] = $date_to; }
        $sql = "SELECT f.*, s.first_name, s.last_name, s.id_number as student_id_number, s.course, s.email,
                       si.lab, si.purpose, si.time_in
                FROM feedback f
                JOIN students s ON f.student_id = s.id
                LEFT JOIN sitins si ON f.sitin_id = si.id"
             . ($where ? ' WHERE '.implode(' AND ', $where) : '')
             . " ORDER BY f.created_at DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($binds);
        $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $formatted_data = [];
        foreach ($export_data as $row) {
            $formatted_data[] = [
                'Feedback ID' => $row['id'],
                'Student ID' => $row['student_id_number'],
                'Student Name' => $row['first_name'] . ' ' . $row['last_name'],
                'Course' => $row['course'],
                'Email' => $row['email'],
                'Lab' => $row['lab'],
                'Purpose' => $row['purpose'],
                'Rating' => $row['rating'] . ' ★',
                'Comment' => $row['comment'],
                'Submitted At' => $row['created_at']
            ];
        }
        $headers = ['Feedback ID', 'Student ID', 'Student Name', 'Course', 'Email', 'Lab', 'Purpose', 'Rating', 'Comment', 'Submitted At'];
        if ($export_format === 'csv') outputCSV($formatted_data, $headers, 'feedback_export');
        elseif ($export_format === 'xlsx') outputXLSX($formatted_data, $headers, 'Feedback', 'feedback_export');
        elseif ($export_format === 'pdf') outputPDF($formatted_data, $headers, 'Feedback Report', 'feedback_export');
        exit;
    }
    
    // Export Sit-in Records
    if ($export_type === 'records') {
        $date_from = $_GET['date_from'] ?? '';
        $date_to   = $_GET['date_to']   ?? '';
        $where = []; $binds = [];
        if ($date_from) { $where[] = 'DATE(s.time_in) >= ?'; $binds[] = $date_from; }
        if ($date_to)   { $where[] = 'DATE(s.time_in) <= ?'; $binds[] = $date_to; }
        $sql = "SELECT s.*, st.first_name, st.last_name, st.id_number as student_id_number, st.course
                FROM sitins s JOIN students st ON s.student_id = st.id"
             . ($where ? ' WHERE '.implode(' AND ', $where) : '')
             . " ORDER BY s.time_in DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($binds);
        $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $formatted_data = [];
        foreach ($export_data as $row) {
            $formatted_data[] = [
                'ID' => $row['id'],
                'Student ID' => $row['student_id_number'],
                'Student Name' => $row['first_name'] . ' ' . $row['last_name'],
                'Course' => $row['course'],
                'Purpose' => $row['purpose'],
                'Lab' => $row['lab'],
                'Time In' => $row['time_in'],
                'Time Out' => $row['time_out'],
                'Status' => $row['status']
            ];
        }
        $headers = ['ID', 'Student ID', 'Student Name', 'Course', 'Purpose', 'Lab', 'Time In', 'Time Out', 'Status'];
        if ($export_format === 'csv') outputCSV($formatted_data, $headers, 'sitins_records');
        elseif ($export_format === 'xlsx') outputXLSX($formatted_data, $headers, 'Sit-in Records', 'sitins_records');
        elseif ($export_format === 'pdf') outputPDF($formatted_data, $headers, 'Sit-in Records Report', 'sitins_records');
        exit;
    }
    
    // Export Analytics
    if ($export_type === 'analytics') {
        $date_from = $_GET['date_from'] ?? '';
        $date_to   = $_GET['date_to']   ?? '';
        $dateWhere = ''; $dateBind = [];
        if ($date_from && $date_to) {
            $dateWhere = " WHERE DATE(time_in) BETWEEN ? AND ?"; $dateBind = [$date_from, $date_to];
        } elseif ($date_from) {
            $dateWhere = " WHERE DATE(time_in) >= ?"; $dateBind = [$date_from];
        } elseif ($date_to) {
            $dateWhere = " WHERE DATE(time_in) <= ?"; $dateBind = [$date_to];
        }
        $dailyBase = "SELECT DATE(time_in) as date, COUNT(*) as count FROM sitins" . ($dateWhere ?: " WHERE time_in >= DATE_SUB(NOW(), INTERVAL 30 DAY)") . " GROUP BY DATE(time_in) ORDER BY date ASC";
        $dailyStmt = $pdo->prepare($dailyBase); $dailyStmt->execute($dateBind ?: []);
        $daily_trends = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

        $labWhere = $dateWhere ? str_replace('time_in','time_in',  $dateWhere) . " AND status='done'" : " WHERE status='done'";
        $labStmt = $pdo->prepare("SELECT lab, COUNT(*) as total_sitins, COUNT(DISTINCT student_id) as unique_students FROM sitins$labWhere GROUP BY lab ORDER BY total_sitins DESC");
        $labStmt->execute($dateBind ?: []);
        $lab_utilization = $labStmt->fetchAll(PDO::FETCH_ASSOC);

        $course_dist = $pdo->query("SELECT course, COUNT(*) as count FROM students GROUP BY course ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);
        $total_students = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM sitins" . ($dateWhere ?: '')); $totalStmt->execute($dateBind ?: []);
        $total_sitins = $totalStmt->fetchColumn();
        
        $formatted_data = [];
        foreach ($daily_trends as $row) { $formatted_data[] = ['Report Type' => 'Daily Trend', 'Date' => $row['date'], 'Count' => $row['count'], 'Notes' => '']; }
        foreach ($lab_utilization as $row) { $formatted_data[] = ['Report Type' => 'Lab Utilization', 'Laboratory' => $row['lab'], 'Total Sit-ins' => $row['total_sitins'], 'Unique Students' => $row['unique_students']]; }
        foreach ($course_dist as $row) { $formatted_data[] = ['Report Type' => 'Course Distribution', 'Course' => $row['course'], 'Count' => $row['count'], 'Notes' => '']; }
        $formatted_data[] = ['Report Type' => 'Summary', 'Metric' => 'Total Students', 'Value' => $total_students, 'Notes' => ''];
        $formatted_data[] = ['Report Type' => 'Summary', 'Metric' => 'Total Sit-ins', 'Value' => $total_sitins, 'Notes' => ''];
        
        $headers = ['Report Type', 'Category', 'Value', 'Additional Info'];
        if ($export_format === 'csv') outputCSV($formatted_data, $headers, 'analytics_export');
        elseif ($export_format === 'xlsx') outputXLSX($formatted_data, $headers, 'Analytics', 'analytics_export');
        elseif ($export_format === 'pdf') outputPDF($formatted_data, $headers, 'Analytics Report', 'analytics_export');
        exit;
    }
    
    // Export Students
    if ($export_type === 'students') {
        $export_data = $pdo->query("SELECT * FROM students ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $formatted_data = [];
        foreach ($export_data as $row) {
            $formatted_data[] = [
                'ID Number' => $row['id_number'],
                'Last Name' => $row['last_name'],
                'First Name' => $row['first_name'],
                'Middle Name' => $row['middle_name'],
                'Course' => $row['course'],
                'Year Level' => $row['year_level'],
                'Email' => $row['email'],
                'Address' => $row['address'],
                'Remaining Session' => $row['remaining_session']
            ];
        }
        $headers = ['ID Number', 'Last Name', 'First Name', 'Middle Name', 'Course', 'Year Level', 'Email', 'Address', 'Remaining Session'];
        if ($export_format === 'csv') outputCSV($formatted_data, $headers, 'students_export');
        elseif ($export_format === 'xlsx') outputXLSX($formatted_data, $headers, 'Students', 'students_export');
        elseif ($export_format === 'pdf') outputPDF($formatted_data, $headers, 'Students Report', 'students_export');
        exit;
    }
}

/* ══════════════════════════════════════════════════
   GUARD
   ══════════════════════════════════════════════════ */
if (empty($_SESSION['admin_logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

/* ══════════════════════════════════════════════════
   FETCH DATA
   ══════════════════════════════════════════════════ */
$admin_name  = $_SESSION['admin_name'] ?? 'Administrator';
$total       = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();

$all_labs = [];
try { $all_labs = $pdo->query("SELECT * FROM labs ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}
$lab_names = array_column($all_labs, 'name');

$reservation_enabled = true;
try { $rv = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='reservation_enabled'")->fetchColumn(); $reservation_enabled = ($rv === false) ? true : (intval($rv) === 1); } catch(Exception $e){}

$activity_logs = [];
try { $activity_logs = $pdo->query("SELECT * FROM activity_logs WHERE entity_type='reservation' AND action_type IN ('RESERVATION_APPROVED','RESERVATION_DENIED') ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}
$currently   = 0; $total_sitin = 0;
try {
    $currently   = $pdo->query("SELECT COUNT(*) FROM sitins WHERE status='active'")->fetchColumn();
    $total_sitin = $pdo->query("SELECT COUNT(*) FROM sitins")->fetchColumn();
} catch (Exception $e) {}

$students = $pdo->query("SELECT * FROM students ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$announcements = [];
try { $announcements = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$sitins = [];
try {
    $sitins = $pdo->query("
        SELECT s.*, st.first_name, st.last_name, st.id_number as student_id_number
        FROM sitins s JOIN students st ON s.student_id = st.id
        WHERE s.status = 'active' ORDER BY s.time_in DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$course_counts = [];
try {
    $stmt = $pdo->query("SELECT course, COUNT(*) as cnt FROM students GROUP BY course");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $course_counts[$row['course']] = $row['cnt'];
} catch (Exception $e) {}

$all_reservations = [];
try {
    $all_reservations = $pdo->query("
        SELECT r.*, s.first_name, s.last_name, s.id_number as student_id_number, s.course, s.email, s.id as student_db_id
        FROM reservations r
        JOIN students s ON r.student_id = s.id
        ORDER BY 
            CASE r.status 
                WHEN 'pending' THEN 1 
                WHEN 'approved' THEN 2 
                ELSE 3 
            END,
            r.date ASC,
            r.time_in ASC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$all_feedback = [];
$feedback_stats = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sitin_id INT NOT NULL,
        student_id INT NOT NULL,
        rating INT NOT NULL,
        comment TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $all_feedback = $pdo->query("
        SELECT f.*, s.first_name, s.last_name, s.id_number as student_id_number, s.course, s.email,
               si.lab, si.purpose, si.time_in, si.time_out
        FROM feedback f
        JOIN students s ON f.student_id = s.id
        LEFT JOIN sitins si ON f.sitin_id = si.id
        ORDER BY f.created_at DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $fb_stats = $pdo->query("
        SELECT 
            COUNT(*) as total_feedback,
            ROUND(AVG(rating), 1) as avg_rating,
            COUNT(CASE WHEN rating >= 4 THEN 1 END) as positive,
            COUNT(CASE WHEN rating <= 2 THEN 1 END) as negative,
            si.lab,
            COUNT(*) as count
        FROM feedback f
        LEFT JOIN sitins si ON f.sitin_id = si.id
        GROUP BY si.lab
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($fb_stats as $stat) { $feedback_stats[$stat['lab']] = $stat; }
} catch (Exception $e) {}

$analytics = [];
try {
    $analytics['daily_trends'] = $pdo->query("SELECT DATE(time_in) as date, COUNT(*) as count FROM sitins WHERE time_in >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY DATE(time_in) ORDER BY date ASC")->fetchAll(PDO::FETCH_ASSOC);
    $analytics['hourly_distribution'] = $pdo->query("SELECT HOUR(time_in) as hour, COUNT(*) as count FROM sitins GROUP BY HOUR(time_in) ORDER BY hour ASC")->fetchAll(PDO::FETCH_ASSOC);
    $analytics['course_distribution'] = $pdo->query("SELECT course, COUNT(*) as count FROM students GROUP BY course ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);
    $analytics['year_distribution'] = $pdo->query("SELECT year_level, COUNT(*) as count FROM students GROUP BY year_level ORDER BY year_level ASC")->fetchAll(PDO::FETCH_ASSOC);
    $analytics['lab_utilization'] = $pdo->query("SELECT lab, COUNT(*) as total_sitins, COUNT(DISTINCT student_id) as unique_students FROM sitins WHERE status = 'done' GROUP BY lab ORDER BY total_sitins DESC")->fetchAll(PDO::FETCH_ASSOC);
    $analytics['most_visited_lab'] = !empty($analytics['lab_utilization']) ? $analytics['lab_utilization'][0]['lab'] : '—';
    $analytics['most_active_course'] = !empty($analytics['course_distribution']) ? $analytics['course_distribution'][0]['course'] : '—';
    $peak_hour_data = $pdo->query("SELECT HOUR(time_in) as hour, COUNT(*) as count FROM sitins GROUP BY HOUR(time_in) ORDER BY count DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $analytics['peak_hour'] = $peak_hour_data ? date('g:i A', mktime($peak_hour_data['hour'], 0, 0)) . ' - ' . date('g:i A', mktime($peak_hour_data['hour'] + 1, 0, 0)) : '—';
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Admin Dashboard</title>
  <link rel="stylesheet" href="style.css"/>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
  <style>
:root{--purple-dark:#4c1d95;--purple-mid:#6c3fcf;--purple-light:#a259f7;--yellow:#f5c518;--red:#ef4444;--green:#22c55e;--text:#1a1a2e;--muted:#6b7280;--border:#e5e7eb;--white:#ffffff;--ff:'Poppins',sans-serif;--fb:'DM Sans',sans-serif}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--fb);color:var(--text)}
.dash-body{display:flex;min-height:100vh;background:#f4f2fb;overflow-x:hidden}
.dash-main{flex:1;min-width:0;padding:1.8rem 2rem;overflow-y:auto}
.dash-page{display:none}
.dash-page.active{display:block;animation:fadeUp .3s ease both}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
/* ── FILE UPLOADS PAGE ── */
.file-upload-drop{border:2.5px dashed #ddd6fe;border-radius:14px;padding:2rem 1.5rem;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;background:#faf8ff}
.file-upload-drop:hover,.file-upload-drop.drag-over{border-color:var(--purple-mid);background:#f3f0ff}
.file-upload-drop i{font-size:2.5rem;color:#c4b5fd;display:block;margin-bottom:.75rem}
.file-drop-title{font-size:.95rem;font-weight:700;color:var(--text);margin-bottom:.25rem}
.file-drop-hint{font-size:.75rem;color:var(--muted)}
.file-type-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.18rem .55rem;border-radius:6px;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.ftb-pdf{background:#fee2e2;color:#b91c1c}
.ftb-doc,.ftb-docx{background:#dbeafe;color:#1d4ed8}
.ftb-xls,.ftb-xlsx{background:#dcfce7;color:#15803d}
.ftb-ppt,.ftb-pptx{background:#ffedd5;color:#c2410c}
.ftb-img{background:#fae8ff;color:#86198f}
.ftb-zip{background:#fef9c3;color:#92400e}
.ftb-txt{background:#f3f4f6;color:#374151}
.ftb-other{background:#ede9fe;color:#6c3fcf}
.file-card{background:#fff;border-radius:12px;padding:1rem 1.2rem;border:1px solid #ece9f8;display:flex;align-items:center;gap:1rem;transition:box-shadow .18s}
.file-card:hover{box-shadow:0 4px 20px rgba(108,63,207,.1)}
.file-card-icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
.file-card-meta{flex:1;min-width:0}
.file-card-name{font-weight:700;font-size:.875rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.file-card-info{font-size:.72rem;color:var(--muted);margin-top:.15rem}
.file-cards-grid{display:flex;flex-direction:column;gap:.6rem}
.upload-progress{height:4px;background:#ede9fe;border-radius:99px;overflow:hidden;margin-top:.4rem;display:none}
.upload-progress-bar{height:100%;background:linear-gradient(90deg,var(--purple-mid),var(--purple-light));border-radius:99px;width:0%;transition:width .3s}

/* ── SIDEBAR LABEL ── */
.sidebar{width:240px;min-width:240px;background:var(--white);border-right:1px solid #ece9f8;display:flex;flex-direction:column;height:100vh;position:sticky;top:0;z-index:300;box-shadow:2px 0 20px rgba(108,63,207,.06);transition:transform .28s cubic-bezier(.4,0,.2,1)}
.sb-brand{display:flex;align-items:center;gap:.7rem;padding:1.4rem 1.2rem 1rem;border-bottom:1px solid #ece9f8}
.sb-logo{width:38px;height:38px;object-fit:contain;flex-shrink:0}
.sb-brand-text{display:flex;flex-direction:column;gap:.05rem}
.sb-title{font-family:var(--ff);font-size:.9rem;font-weight:800;color:var(--text);line-height:1.2}
.sb-admin-badge{display:inline-flex;align-items:center;gap:.25rem;background:linear-gradient(135deg,#fef3c7,#fde68a);color:#92400e;font-size:.6rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:.15rem .45rem;border-radius:5px;margin-top:.1rem;width:fit-content}
.sb-nav{padding:.8rem .7rem 0;flex:1;overflow-y:auto}
.sb-nav ul{list-style:none;display:flex;flex-direction:column;gap:.15rem}
.sb-link{display:flex;align-items:center;gap:.8rem;padding:.62rem .9rem;border-radius:10px;text-decoration:none;color:#4b5563;font-size:.875rem;font-weight:500;transition:all .2s;position:relative;cursor:pointer}
.sb-link:hover{background:#f3f0ff;color:var(--purple-mid)}
.sb-link.active{background:linear-gradient(135deg,#ede9fe,#f5f3ff);color:var(--purple-mid);font-weight:700;box-shadow:0 2px 8px rgba(108,63,207,.1)}
.sb-link.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;background:var(--purple-mid);border-radius:0 3px 3px 0}
.sb-icon{width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:7px;font-size:.85rem;flex-shrink:0;background:transparent;transition:background .2s}
.sb-link.active .sb-icon{background:rgba(108,63,207,.12)}
.sb-link:hover .sb-icon{background:rgba(108,63,207,.08)}
.sb-spacer{flex:1}
.sb-user-section{padding:.4rem .7rem .5rem;border-top:1px solid #ece9f8;position:relative}
.sb-user-btn{display:flex;align-items:center;gap:.5rem;padding:.4rem .55rem;border-radius:10px;cursor:pointer;transition:background .2s}
.sb-user-btn:hover{background:#fff4f4}
.sb-avatar{width:30px;height:30px;border-radius:8px;color:#fff;font-family:var(--ff);font-size:.8rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:linear-gradient(135deg,#dc2626,#f97316);box-shadow:0 2px 8px rgba(220,38,38,.4)}
.sb-user-info{flex:1;min-width:0;display:flex;flex-direction:column}
.sb-user-name{font-size:.82rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user-id{font-size:.7rem;color:var(--muted)}
.sb-chevron{font-size:.65rem;color:var(--muted);transition:transform .2s;flex-shrink:0}
.sb-chevron.open{transform:rotate(180deg)}
.sb-user-menu{position:absolute;bottom:calc(100% + .3rem);left:.7rem;right:.7rem;background:var(--white);border:1px solid #ece9f8;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.12);overflow:hidden;display:none;z-index:400}
.sb-user-menu.open{display:block}
.sb-menu-item{display:flex;align-items:center;gap:.6rem;padding:.7rem 1rem;font-size:.85rem;font-weight:500;color:#374151;text-decoration:none;cursor:pointer;transition:background .15s}
.sb-menu-item i{font-size:.85rem;width:16px;text-align:center;color:var(--purple-mid)}
.sb-menu-item:hover{background:#f9fafb}
.sb-menu-item.danger{color:#ef4444}
.sb-menu-item.danger i{color:#ef4444}
.sb-menu-item.danger:hover{background:#fef2f2}
.sb-menu-divider{height:1px;background:#f0ecff}
.dash-topbar{display:none;align-items:center;justify-content:space-between;height:56px;padding:0 1rem;background:var(--white);border-bottom:1px solid #ece9f8;position:fixed;top:0;left:0;right:0;z-index:250;box-shadow:0 2px 10px rgba(0,0,0,.06)}
.dash-topbar-brand{display:flex;align-items:center;gap:.5rem;font-family:var(--ff);font-size:.9rem;font-weight:800;color:var(--text)}
.sb-toggle{background:none;border:none;font-size:1.1rem;color:var(--text);cursor:pointer;padding:.4rem;border-radius:8px;transition:background .15s}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:290}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem}
.page-title{font-family:var(--ff);font-size:1.55rem;font-weight:800;color:var(--text);letter-spacing:-.025em;line-height:1.2}
.page-sub{color:var(--muted);font-size:.875rem;margin-top:.2rem}
.page-actions{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.welcome-banner{background:linear-gradient(135deg,#1e0a3c 0%,var(--purple-dark) 45%,var(--purple-mid) 100%);border-radius:18px;padding:1.8rem 2rem;display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;position:relative;overflow:hidden;box-shadow:0 8px 32px rgba(108,63,207,.35)}
.welcome-title{font-family:var(--ff);font-size:1.55rem;font-weight:800;color:#fff;letter-spacing:-.025em;line-height:1.2}
.welcome-sub{font-size:.86rem;color:rgba(255,255,255,.65);margin-top:.4rem}
.badge-admin{background:linear-gradient(135deg,#fef3c7,#fde68a);color:#92400e;font-family:var(--ff);font-size:.8rem;font-weight:800;padding:.3rem .85rem;border-radius:8px;letter-spacing:.04em}
.home-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.5rem}
.dash-card{background:var(--white);border-radius:16px;padding:1.3rem 1.5rem;box-shadow:0 2px 12px rgba(0,0,0,.05);border:1px solid #ece9f8}
.dash-card.no-pad{padding:0}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem}
.card-header h2{font-family:var(--ff);font-size:.95rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:.5rem}
.card-header h2 i{color:var(--purple-mid);font-size:.88rem}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem}
.stat-card{background:var(--white);border-radius:14px;padding:1.1rem 1.3rem;display:flex;align-items:center;gap:.9rem;border:1px solid #ece9f8;box-shadow:0 2px 10px rgba(0,0,0,.04);transition:transform .2s}
.stat-card:hover{transform:translateY(-2px)}
.stat-card.p{border-left:4px solid var(--purple-mid)}
.stat-card.g{border-left:4px solid var(--green)}
.stat-card.y{border-left:4px solid var(--yellow)}
.sc-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.stat-card.p .sc-icon{background:#ede9fe;color:var(--purple-mid)}
.stat-card.g .sc-icon{background:#dcfce7;color:#16a34a}
.stat-card.y .sc-icon{background:#fef9c3;color:#a16207}
.sc-val{font-family:var(--ff);font-size:1.5rem;font-weight:800;line-height:1;color:var(--text)}
.sc-lbl{font-size:.74rem;color:var(--muted);margin-top:.15rem}
.ann-form{display:flex;flex-direction:column;gap:.7rem;margin-bottom:1.2rem}
.ann-textarea{width:100%;padding:.75rem 1rem;border:1.5px solid var(--border);border-radius:10px;font-family:var(--fb);font-size:.875rem;color:var(--text);resize:vertical;min-height:80px;outline:none;transition:border-color .2s}
.ann-textarea:focus{border-color:var(--purple-mid);box-shadow:0 0 0 3px rgba(108,63,207,.1)}
.ann-list{display:flex;flex-direction:column;gap:.6rem;max-height:280px;overflow-y:auto}
.ann-item{padding:.75rem 1rem;border-radius:10px;background:#fafafa;border:1px solid #f0ecff}
.ann-meta{font-size:.72rem;font-weight:700;color:var(--purple-mid);margin-bottom:.3rem}
.ann-text{font-size:.84rem;color:var(--text);line-height:1.5}
.ann-empty{font-size:.84rem;color:var(--muted);text-align:center;padding:1rem}
.table-wrap{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:.84rem}
.data-table thead tr{background:#fafafa;border-bottom:2px solid #ece9f8}
.data-table th{padding:.75rem 1rem;text-align:left;font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}
.data-table td{padding:.75rem 1rem;border-bottom:1px solid #f4f2fb;color:var(--text);white-space:nowrap}
.data-table tbody tr:hover{background:#faf9ff}
.data-table tbody tr:last-child td{border-bottom:none}
.badge{font-size:.72rem;font-weight:700;padding:.22rem .6rem;border-radius:6px;letter-spacing:.03em}
.badge.purple{background:#ede9fe;color:var(--purple-mid)}
.badge.green{background:#dcfce7;color:#15803d}
.badge.red{background:#fee2e2;color:#b91c1c}
.badge.yellow{background:#fef9c3;color:#92400e}
.badge.blue{background:#dbeafe;color:#1d4ed8}
.btn-primary-sm{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem 1rem;border-radius:9px;border:none;background:linear-gradient(135deg,var(--purple-mid),var(--purple-light));color:#fff;font-size:.82rem;font-weight:700;cursor:pointer;font-family:var(--fb);box-shadow:0 3px 10px rgba(108,63,207,.28);transition:all .18s}
.btn-primary-sm:hover{transform:translateY(-1px)}
.btn-outline-sm{display:inline-flex;align-items:center;gap:.4rem;padding:.38rem .85rem;border-radius:8px;border:1.5px solid var(--border);background:var(--white);font-size:.8rem;font-weight:600;color:#4b5563;cursor:pointer;font-family:var(--fb);transition:all .15s}
.btn-outline-sm:hover{border-color:var(--purple-mid);color:var(--purple-mid);background:#f3f0ff}
.btn-danger-sm{display:inline-flex;align-items:center;gap:.4rem;padding:.38rem .85rem;border-radius:8px;border:1.5px solid #fca5a5;background:#fee2e2;font-size:.8rem;font-weight:600;color:#ef4444;cursor:pointer;font-family:var(--fb);transition:all .15s}
.btn-danger-sm:hover{background:#fecaca}
.btn-green-sm{display:inline-flex;align-items:center;gap:.4rem;padding:.38rem .85rem;border-radius:8px;border:1.5px solid #bbf7d0;background:#dcfce7;font-size:.8rem;font-weight:600;color:#16a34a;cursor:pointer;font-family:var(--fb);transition:all .15s}
.btn-green-sm:hover{background:#bbf7d0}
.btn-end{display:inline-flex;align-items:center;gap:.35rem;padding:.28rem .7rem;font-size:.75rem;font-weight:600;color:#ef4444;background:#fef2f2;border:1px solid #fca5a5;border-radius:7px;cursor:pointer;transition:all .15s}
.btn-end:hover{background:#fee2e2}
.search-wrap{position:relative;display:flex;align-items:center}
.search-wrap i{position:absolute;left:.75rem;color:#9ca3af;font-size:.82rem;pointer-events:none}
.search-input{padding:.5rem .9rem .5rem 2.2rem;border:1.5px solid var(--border);border-radius:9px;font-family:var(--fb);font-size:.84rem;color:var(--text);background:#fafafa;outline:none;width:220px;transition:border-color .2s,box-shadow .2s}
.search-input:focus{border-color:var(--purple-mid);box-shadow:0 0 0 3px rgba(108,63,207,.1);background:var(--white);width:260px}
.table-toolbar{display:flex;align-items:center;justify-content:space-between;gap:.8rem;flex-wrap:wrap;padding:1rem 1.5rem .6rem}
.toolbar-label{font-family:var(--ff);font-size:.92rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:.45rem;white-space:nowrap}
.toolbar-label i{color:var(--purple-mid)}
.tbl-pagination{display:flex;align-items:center;justify-content:space-between;padding:.7rem 1.5rem;border-top:1.5px solid #f0ecff;background:#faf8ff;border-radius:0 0 14px 14px}
.tbl-page-info{font-size:.82rem;color:var(--muted);font-weight:600}
.tbl-page-info strong{color:var(--purple-mid)}
.tbl-page-controls{display:flex;gap:.35rem}
.tbl-page-btn{width:30px;height:30px;border-radius:7px;border:1.5px solid #e9d5ff;background:#fff;color:var(--purple-mid);font-size:.75rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s,border-color .15s}
.tbl-page-btn:hover:not(:disabled){background:#f3e8ff;border-color:var(--purple-mid)}
.tbl-page-btn:disabled{color:#d1d5db;border-color:#f3f4f6;cursor:not-allowed;background:#fafafa}
.modal-overlay{position:fixed;inset:0;background:rgba(15,10,40,.55);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:500;opacity:0;pointer-events:none;transition:opacity .22s;padding:1rem}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal-card{background:var(--white);border-radius:20px;width:100%;max-width:480px;box-shadow:0 24px 64px rgba(0,0,0,.22);transform:translateY(14px);transition:transform .24s;overflow:hidden}
.modal-overlay.open .modal-card{transform:translateY(0)}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.4rem;border-bottom:1px solid #f0ecff}
.modal-header h3{font-family:var(--ff);font-size:.95rem;font-weight:800;display:flex;align-items:center;gap:.45rem}
.modal-header h3 i{color:var(--purple-mid)}
.modal-close{width:30px;height:30px;border-radius:8px;border:none;background:#f3f0ff;color:var(--purple-mid);font-size:.85rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s}
.modal-close:hover{background:#fef2f2;color:var(--red)}
.modal-body{padding:1.4rem}
.modal-footer{display:flex;justify-content:flex-end;gap:.55rem;padding:.9rem 1.4rem;border-top:1px solid #f0ecff}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:.38rem}
.form-group input,.form-group select{width:100%;padding:.65rem .9rem;border:1.5px solid var(--border);border-radius:10px;font-size:.875rem;font-family:var(--fb);outline:none;color:var(--text);background:#fafafa;transition:border-color .18s}
.form-group input:focus,.form-group select:focus{border-color:var(--purple-mid);background:var(--white);box-shadow:0 0 0 3px rgba(108,63,207,.1)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.form-row.three-col{grid-template-columns:1fr 1fr 1fr}
.sitin-search-panel{background:#faf8ff;border:1.5px solid #ede9fe;border-radius:14px;padding:1.2rem 1.4rem;margin-bottom:1.2rem;display:flex;align-items:center;gap:.8rem;flex-wrap:wrap}
.sitin-search-panel input{flex:1;min-width:180px;padding:.6rem 1rem;border:1.5px solid var(--border);border-radius:10px;font-size:.875rem;font-family:var(--fb);outline:none;color:var(--text);background:var(--white);transition:border-color .2s}
.sitin-search-panel input:focus{border-color:var(--purple-mid)}
.status-live{display:flex;align-items:center;gap:.4rem;font-size:.72rem;font-weight:700;color:#16a34a;background:#dcfce7;padding:.2rem .6rem;border-radius:20px}
.live-dot{width:6px;height:6px;border-radius:50%;background:#16a34a;animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.toast{position:fixed!important;top:1rem!important;right:1rem!important;left:auto!important;bottom:auto!important;transform:translateX(110%)!important;width:300px!important;background:#fff!important;border-radius:8px!important;border-left:4px solid #ccc!important;box-shadow:0 4px 20px rgba(0,0,0,.12)!important;padding:.75rem 1rem!important;display:flex!important;align-items:flex-start!important;gap:.65rem!important;opacity:0!important;transition:transform .3s ease,opacity .3s ease!important;z-index:99999!important;font-family:var(--fb)!important;pointer-events:all!important}
.toast.show{transform:translateX(0)!important;opacity:1!important}
.toast.success{border-left-color:#16a34a!important;background:#f0fdf4!important}
.toast.success .lt-icon{color:#16a34a!important}
.toast.error{border-left-color:#dc2626!important;background:#fef2f2!important}
.toast.error .lt-icon{color:#dc2626!important}
.toast.info{border-left-color:#2563eb!important;background:#eff6ff!important}
.toast.info .lt-icon{color:#2563eb!important}
.lt-icon{font-size:1.05rem!important;margin-top:1px!important;flex-shrink:0!important}
.lt-body{flex:1!important}
.lt-title{font-size:.82rem!important;font-weight:700!important;color:#111!important;line-height:1.2!important;margin-bottom:.18rem!important}
.lt-msg{font-size:.75rem!important;font-weight:400!important;color:#444!important;line-height:1.4!important}
.lt-close{background:none!important;border:none!important;cursor:pointer!important;color:#888!important;font-size:.85rem!important;padding:0!important;line-height:1!important;flex-shrink:0!important;margin-top:1px!important}
.lt-close:hover{color:#333!important}
.stars-display{color:#f5c518;font-size:.85rem;letter-spacing:.05rem}
.stats-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem}
.feedback-stat-card{background:linear-gradient(135deg,#fff,#faf9ff);border-radius:12px;padding:1rem;text-align:center;border:1px solid #ede9fe}
.feedback-stat-value{font-family:var(--ff);font-size:1.4rem;font-weight:800;color:var(--purple-mid)}
.feedback-stat-label{font-size:.72rem;color:var(--muted);margin-top:.2rem}
/* Export inline bar */
.export-bar{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap}
.export-bar-sep{width:1px;height:22px;background:#e5e7eb;margin:0 .1rem}
.export-date-input{padding:.38rem .65rem;border:1.5px solid var(--border);border-radius:8px;font-size:.78rem;font-family:var(--fb);color:var(--text);background:#fafafa;outline:none;transition:border-color .18s;height:32px}
.export-date-input:focus{border-color:var(--purple-mid);background:#fff}
.export-date-label{font-size:.72rem;font-weight:700;color:var(--muted);white-space:nowrap}
.export-fmt-link{display:inline-flex;align-items:center;gap:.3rem;padding:.35rem .75rem;border-radius:8px;border:1.5px solid var(--border);background:#fff;font-size:.76rem;font-weight:700;color:#4b5563;cursor:pointer;text-decoration:none;transition:all .15s;height:32px}
.export-fmt-link:hover{background:#f3f0ff;border-color:var(--purple-mid);color:var(--purple-mid)}
.export-fmt-link.csv:hover{background:#f0fdf4;border-color:#16a34a;color:#15803d}
.export-fmt-link.xlsx:hover{background:#eff6ff;border-color:#2563eb;color:#1d4ed8}
.export-fmt-link.pdf:hover{background:#fef2f2;border-color:#dc2626;color:#b91c1c}
.export-btn-main{display:inline-flex;align-items:center;gap:.3rem;padding:.35rem .85rem;border-radius:8px;border:1.5px solid var(--border);background:#fff;font-size:.76rem;font-weight:700;color:#4b5563;cursor:pointer;height:32px;transition:all .15s;font-family:var(--fb)}
.export-btn-main:hover{border-color:var(--purple-mid);color:var(--purple-mid);background:#f3f0ff}
.export-dropdown-wrap{position:relative;display:inline-block}
.export-dropdown-menu{display:none;position:absolute;right:0;top:calc(100% + 4px);background:#fff;border:1.5px solid #e5e7eb;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:999;min-width:130px;overflow:hidden}
.export-dropdown-menu.open{display:block;animation:fadeIn .15s ease}
.export-dropdown-menu a{display:flex;align-items:center;gap:.5rem;padding:.6rem 1rem;font-size:.8rem;font-weight:600;color:#374151;text-decoration:none;transition:background .12s;cursor:pointer}
.export-dropdown-menu a:hover{background:#f3f0ff;color:var(--purple-mid)}
.export-dropdown-menu a i{font-size:.82rem;width:14px}

.filter-group{display:flex;gap:.5rem;align-items:center}
.filter-select{padding:.4rem .7rem;border:1.5px solid var(--border);border-radius:8px;font-size:.8rem;font-family:var(--fb);background:white;cursor:pointer}
.stats-detail-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem}
.stats-detail-card{background:#ffffff;border-radius:12px;padding:1rem;text-align:center;border:1px solid #e5e7eb;box-shadow:0 1px 2px rgba(0,0,0,.05)}
.stats-detail-value{font-family:var(--ff);font-size:1.2rem;font-weight:800;color:var(--purple-mid)}
.stats-detail-label{font-size:.7rem;color:var(--muted);margin-top:.2rem}
.lab-usage-bar{background:#ede9fe;border-radius:10px;height:8px;overflow:hidden;margin-top:.3rem}
.lab-usage-fill{background:linear-gradient(90deg,var(--purple-mid),var(--purple-light));height:100%;border-radius:10px;width:0%}
.lab-usage-item{display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem}
.btn-view-student{display:inline-flex;align-items:center;gap:.35rem;padding:.28rem .7rem;font-size:.72rem;font-weight:600;color:var(--purple-mid);background:#ede9fe;border:none;border-radius:7px;cursor:pointer;transition:all .15s}
.btn-view-student:hover{background:#d9d2fc}
.action-cell{position:relative;white-space:nowrap}
.dot-btn{background:none;border:none;cursor:pointer;padding:8px;border-radius:8px;color:#6b7280;transition:all 0.2s;display:inline-flex;align-items:center;justify-content:center}
.dot-btn:hover{background:#f3f0ff;color:#6c3fcf}
.dropdown-3dot{position:relative;display:inline-block}
.dropdown-content{display:none;position:absolute;right:0;top:100%;background:white;min-width:170px;box-shadow:0 8px 20px rgba(0,0,0,0.15);border-radius:12px;z-index:100;overflow:hidden;margin-top:5px}
.dropdown-content.show{display:block;animation:fadeIn 0.15s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:translateY(0)}}
.dropdown-content a{display:flex;align-items:center;gap:10px;padding:10px 16px;text-decoration:none;color:#374151;font-size:0.8rem;transition:background 0.15s;cursor:pointer}
.dropdown-content a:hover{background:#f9f7ff}
.dropdown-content a i{width:18px;font-size:0.85rem}
.dropdown-content a.approve{color:#16a34a}
.dropdown-content a.approve:hover{background:#f0fdf4}
.dropdown-content a.reject{color:#ef4444}
.dropdown-content a.reject:hover{background:#fef2f2}
.dropdown-content a.view{color:#6c3fcf}
.notification-badge{position:fixed;top:1rem;right:1rem;z-index:1000}
.auto-sitin-toast{background:#22c55e;color:white;padding:.5rem 1rem;border-radius:8px;font-size:.8rem;animation:slideInRight 0.3s ease;margin-bottom:.5rem;box-shadow:0 4px 12px rgba(0,0,0,0.15)}
@keyframes slideInRight{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
/* Reservation Toggle Switch */
.rsv-toggle-knob{position:absolute;cursor:pointer;inset:0;background:#d1d5db;border-radius:34px;transition:background .25s;box-shadow:inset 0 1px 3px rgba(0,0,0,.15)}
.rsv-toggle-knob:before{content:'';position:absolute;height:22px;width:22px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 2px 6px rgba(0,0,0,.2)}
#reservationToggle:checked + .rsv-toggle-knob{background:#6c3fcf}
#reservationToggle:checked + .rsv-toggle-knob:before{transform:translateX(26px)}
#reservationToggle:focus + .rsv-toggle-knob{outline:2px solid #6c3fcf;outline-offset:2px}
@media(max-width:900px){
  .sidebar{position:fixed;left:0;top:0;bottom:0;transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay{display:block;opacity:0;pointer-events:none;transition:opacity .25s}
  .sidebar-overlay.open{opacity:1;pointer-events:auto}
  .dash-topbar{display:flex}
  .dash-main{padding:4.5rem 1rem 1.5rem}
  .home-grid{grid-template-columns:1fr}
  .stats-row{grid-template-columns:1fr 1fr}
  .stats-summary{grid-template-columns:repeat(2,1fr)}
  .stats-detail-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:540px){.stats-row{grid-template-columns:1fr}.form-row{grid-template-columns:1fr}}

/* Reservation Card Styles */
.reservation-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1.25rem;margin-top:0.5rem}
.reservation-card{background:#fff;border-radius:16px;border:1px solid #e5e7eb;overflow:hidden;transition:all 0.2s;box-shadow:0 1px 3px rgba(0,0,0,0.05)}
.reservation-card:hover{transform:translateY(-3px);box-shadow:0 8px 25px rgba(108,63,207,0.12);border-color:#ddd6fe}
.status-badge-res{padding:.25rem .75rem;border-radius:20px;font-size:.7rem;font-weight:700;color:#fff}
.view-student-btn,.approve-rsv-btn,.reject-rsv-btn{transition:all .15s}
.view-student-btn:hover{background:#f3f0ff;border-color:#6c3fcf;color:#6c3fcf}
.approve-rsv-btn:hover{background:#16a34a;transform:translateY(-1px);box-shadow:0 2px 8px rgba(34,197,94,0.3)}
.reject-rsv-btn:hover{background:#fee2e2;border-color:#ef4444;transform:translateY(-1px)}

/* Reservation Tabs */
.rsv-tabs{display:flex;gap:.3rem;background:#f4f2fb;border-radius:12px;padding:.3rem;margin-bottom:0;width:fit-content}
.rsv-tab-btn{display:inline-flex;align-items:center;gap:.45rem;padding:.55rem 1.1rem;border-radius:9px;border:none;background:transparent;font-size:.82rem;font-weight:600;color:#6b7280;cursor:pointer;font-family:var(--fb);transition:all .18s;position:relative}
.rsv-tab-btn.active{background:var(--white);color:var(--purple-mid);box-shadow:0 2px 8px rgba(108,63,207,.12);font-weight:700}
.rsv-tab-btn:hover:not(.active){background:rgba(255,255,255,.6);color:var(--purple-mid)}
.rsv-tab-panel{margin-top:0}
.pending-count{background:#ef4444;color:#fff;font-size:.65rem;font-weight:800;padding:.1rem .4rem;border-radius:20px;margin-left:.2rem}
/* ── PC SEAT MAP ── */
.seatmap-modal-card{max-width:780px}
.seatmap-wrap{display:flex;flex-direction:column;gap:1rem}
.seatmap-info-bar{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;padding:.6rem .8rem;background:#faf8ff;border-radius:10px;border:1px solid #ede9fe;font-size:.82rem}
.seatmap-legend{display:flex;gap:.9rem;flex-wrap:wrap;align-items:center;margin-bottom:.25rem}
.legend-item{display:flex;align-items:center;gap:.35rem;font-size:.76rem;font-weight:600;color:#4b5563}
.legend-dot{width:14px;height:14px;border-radius:4px;flex-shrink:0}
.ld-available{background:#22c55e}
.ld-reserved{background:#f59e0b}
.ld-inuse{background:#ef4444}
.ld-unavailable{background:#9ca3af}
.ld-selected{background:#6c3fcf}
.ld-student{background:#3b82f6}
.pc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(72px,1fr));gap:.55rem;max-height:400px;overflow-y:auto;padding:.25rem}
.pc-seat{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.2rem;padding:.55rem .3rem;border-radius:10px;border:2px solid transparent;cursor:pointer;transition:all .18s;user-select:none;min-height:68px}
.pc-seat.available{background:#dcfce7;border-color:#86efac;color:#15803d}
.pc-seat.available:hover{background:#bbf7d0;border-color:#22c55e;transform:translateY(-2px);box-shadow:0 4px 12px rgba(34,197,94,.25)}
.pc-seat.reserved{background:#fef9c3;border-color:#fde68a;color:#92400e;cursor:not-allowed}
.pc-seat.in_use{background:#fee2e2;border-color:#fca5a5;color:#b91c1c;cursor:not-allowed}
.pc-seat.unavailable{background:#f3f4f6;border-color:#d1d5db;color:#9ca3af;cursor:not-allowed}
.pc-seat.student_choice{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8;cursor:pointer}
.pc-seat.student_choice:hover{background:#bfdbfe;border-color:#3b82f6;transform:translateY(-2px)}
.pc-seat.selected{background:#ede9fe;border-color:#6c3fcf;color:#6c3fcf;box-shadow:0 0 0 3px rgba(108,63,207,.25)}
.pc-seat-icon{font-size:1.3rem;line-height:1}
.pc-seat-label{font-size:.65rem;font-weight:800;letter-spacing:.03em}
.pc-seat-status{font-size:.58rem;font-weight:600;opacity:.8}
.seatmap-action-bar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;padding-top:.5rem;border-top:1px solid #f0ecff}
.selected-pc-display{display:flex;align-items:center;gap:.5rem;font-size:.85rem;font-weight:700;color:var(--purple-mid)}
.reject-reason-wrap{display:none;margin-top:.5rem}
.reject-reason-wrap.show{display:block}
.reject-reason-input{width:100%;padding:.55rem .85rem;border:1.5px solid #fca5a5;border-radius:9px;font-size:.84rem;font-family:var(--fb);outline:none;color:var(--text);background:#fff9f9;resize:none;min-height:60px}
.reject-reason-input:focus{border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.1)}
  </style>
</head>
<body class="dash-body">

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sb-brand">
    <img src="images/ccslogo.png" alt="CCS Logo" class="sb-logo"/>
    <div class="sb-brand-text">
      <span class="sb-title">CCS Sit-in System</span>
      <span class="sb-admin-badge">Admin</span>
    </div>
  </div>
  <nav class="sb-nav">
    <ul>
      <li><a href="#" class="sb-link active" data-page="home"><span class="sb-icon"><i class="fa-solid fa-house"></i></span><span class="sb-label">Home</span></a></li>

      <li style="padding:.4rem .9rem .1rem;font-size:.62rem;font-weight:800;color:#c4b5fd;letter-spacing:.08em;text-transform:uppercase">Sit-in Management</li>
      <li><a href="#" class="sb-link" data-page="search"><span class="sb-icon"><i class="fa-solid fa-magnifying-glass"></i></span><span class="sb-label">Search Student</span></a></li>
      <li><a href="#" class="sb-link" data-page="sitin"><span class="sb-icon"><i class="fa-solid fa-desktop"></i></span><span class="sb-label">Current Sit-in</span></a></li>
      <li><a href="#" class="sb-link" data-page="reservation"><span class="sb-icon"><i class="fa-solid fa-calendar-check"></i></span><span class="sb-label">Reservations</span></a></li>
      <li><a href="#" class="sb-link" data-page="records"><span class="sb-icon"><i class="fa-solid fa-table-list"></i></span><span class="sb-label">Sit-in Records</span></a></li>

      <li style="padding:.4rem .9rem .1rem;font-size:.62rem;font-weight:800;color:#c4b5fd;letter-spacing:.08em;text-transform:uppercase">Students</li>
      <li><a href="#" class="sb-link" data-page="students"><span class="sb-icon"><i class="fa-solid fa-users"></i></span><span class="sb-label">Students</span></a></li>
      <li><a href="#" class="sb-link" data-page="leaderboard"><span class="sb-icon"><i class="fa-solid fa-trophy"></i></span><span class="sb-label">Leaderboard</span></a></li>
      <li><a href="#" class="sb-link" data-page="feedback"><span class="sb-icon"><i class="fa-solid fa-comment-dots"></i></span><span class="sb-label">Feedback</span></a></li>

      <li style="padding:.4rem .9rem .1rem;font-size:.62rem;font-weight:800;color:#c4b5fd;letter-spacing:.08em;text-transform:uppercase">Insights</li>
      <li><a href="#" class="sb-link" data-page="reports"><span class="sb-icon"><i class="fa-solid fa-chart-bar"></i></span><span class="sb-label">Analytics</span></a></li>

      <li style="padding:.4rem .9rem .1rem;font-size:.62rem;font-weight:800;color:#c4b5fd;letter-spacing:.08em;text-transform:uppercase">System</li>
      <li><a href="#" class="sb-link" data-page="controls"><span class="sb-icon"><i class="fa-solid fa-sliders"></i></span><span class="sb-label">Controls</span></a></li>


    </ul>
  </nav>
  <div class="sb-user-section">
    <div class="sb-user-btn" id="userMenuBtn">
      <div class="sb-avatar">A</div>
      <div class="sb-user-info">
        <span class="sb-user-name"><?= htmlspecialchars($admin_name) ?></span>
        <span class="sb-user-id">Administrator</span>
      </div>
      <i class="fa-solid fa-chevron-up sb-chevron" id="userChevron"></i>
    </div>
    <div class="sb-user-menu" id="userMenu">
      <div class="sb-menu-divider"></div>
      <span class="sb-menu-item danger" id="logoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Logout</span>
    </div>
  </div>
</aside>

<!-- MOBILE TOPBAR -->
<header class="dash-topbar" id="dashTopbar">
  <button class="sb-toggle" id="sbToggle"><i class="fa-solid fa-bars"></i></button>
  <div class="dash-topbar-brand">
    <img src="images/ccslogo.png" alt="Logo" style="width:30px;height:30px;object-fit:contain"/>
    <span>CCS Admin</span>
  </div>
  <div class="sb-avatar" style="width:32px;height:32px;border-radius:8px;font-size:.82rem">A</div>
</header>

<main class="dash-main">

  <!-- HOME PAGE -->
  <div class="dash-page active" id="page-home">
    <div class="welcome-banner">
      <div><div class="welcome-title">Welcome back, <?= htmlspecialchars($admin_name) ?>!</div><div class="welcome-sub">Full control over the sit-in monitoring system.</div></div>
    </div>
    <div class="stats-row">
      <div class="stat-card p"><div class="sc-icon"><i class="fa-solid fa-users"></i></div><div><div class="sc-val" id="stat-total"><?= $total ?></div><div class="sc-lbl">Students Registered</div></div></div>
      <div class="stat-card g"><div class="sc-icon"><i class="fa-solid fa-desktop"></i></div><div><div class="sc-val" id="stat-currently"><?= $currently ?></div><div class="sc-lbl">Currently Sitting In</div></div></div>
      <div class="stat-card y"><div class="sc-icon"><i class="fa-solid fa-clock-rotate-left"></i></div><div><div class="sc-val" id="stat-total-sitin"><?= $total_sitin ?></div><div class="sc-lbl">Total Sit-ins</div></div></div>
    </div>
    <div class="home-grid">
      <div class="dash-card">
        <div class="card-header"><h2><i class="fa-solid fa-chart-pie"></i> Course Distribution</h2></div>
        <canvas id="courseChart" height="160" style="max-height:420px"></canvas>
        <div style="margin-top:.8rem;display:flex;flex-wrap:wrap;gap:.5rem" id="chartLegend"></div>
      </div>
      <div class="dash-card">
        <div class="card-header"><h2><i class="fa-solid fa-bullhorn"></i> Announcements</h2></div>
        <div class="ann-form">
          <textarea class="ann-textarea" id="annText" placeholder="Write a new announcement..."></textarea>
          <button class="btn-primary-sm" id="annSubmitBtn" style="align-self:flex-start"><i class="fa-solid fa-paper-plane"></i> Post Announcement</button>
        </div>
        <h3 style="font-family:var(--ff);font-size:.88rem;font-weight:800;color:var(--text);margin-bottom:.7rem">Recent Announcements</h3>
        <div class="ann-list" id="annList">
          <?php if (empty($announcements)): ?>
          <div class="ann-empty">No announcements yet.</div>
          <?php else: foreach ($announcements as $a): ?>
          <div class="ann-item">
            <div class="ann-meta"><?= htmlspecialchars($a['admin_name']) ?> | <?= date('M d, Y', strtotime($a['created_at'])) ?></div>
            <div class="ann-text"><?= htmlspecialchars($a['content']) ?></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- SEARCH STUDENT PAGE -->
  <div class="dash-page" id="page-search">
    <div class="page-header"><div><div class="page-title">Search Student</div><div class="page-sub">Look up a student by their ID number.</div></div></div>
    <div class="dash-card">
      <div class="sitin-search-panel">
        <i class="fa-solid fa-magnifying-glass" style="color:var(--purple-mid)"></i>
        <input type="text" id="searchInput" placeholder="Enter student ID number..." maxlength="20" autocomplete="off"/>
        <button class="btn-primary-sm" id="doSearchBtn"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
      </div>
      <div id="searchResultArea" style="display:none"></div>
      <div id="searchEmpty" style="text-align:center;padding:2rem;color:var(--muted)">
        <i class="fa-solid fa-user-magnifying-glass" style="font-size:2.5rem;color:#ddd6fe;display:block;margin-bottom:.7rem"></i>
        <p>Enter a student ID number above to view their profile.</p>
      </div>
    </div>
  </div>

  <!-- STUDENTS PAGE -->
  <div class="dash-page" id="page-students">
    <div class="page-header">
      <div><div class="page-title">Students Information</div><div class="page-sub">Manage all registered students.</div></div>
      <div class="page-actions">
        <button class="btn-primary-sm" id="openAddStudentBtn"><i class="fa-solid fa-user-plus"></i> Add Student</button>
        <button class="btn-danger-sm" id="resetAllSessionsBtn"><i class="fa-solid fa-rotate-right"></i> Reset All Sessions</button>
        <div class="export-dropdown-wrap" style="position:relative">
          <button class="export-btn-main" id="studentsExportDropBtn"><i class="fa-solid fa-download"></i> Export <i class="fa-solid fa-chevron-down" style="font-size:.65rem;margin-left:.2rem"></i></button>
          <div class="export-dropdown-menu" id="studentsExportMenu">
            <a href="admin.php?export=students&format=csv"><i class="fa-solid fa-file-csv"></i> CSV</a>
            <a href="admin.php?export=students&format=xlsx"><i class="fa-solid fa-file-excel"></i> Excel</a>
            <a href="admin.php?export=students&format=pdf"><i class="fa-solid fa-file-pdf"></i> PDF</a>
          </div>
        </div>
      </div>
    </div>
    <div class="dash-card no-pad">
      <div class="table-toolbar">
        <span class="toolbar-label"><i class="fa-solid fa-users"></i> All Students</span>
        <div class="search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="text" class="search-input" id="studentsSearch" placeholder="Search students..."/></div>
      </div>
      <div class="table-wrap">
        <table class="data-table" id="studentsTable">
          <thead><tr><th>ID Number</th><th>Name</th><th>Year Level</th><th>Course</th><th>Remaining Session</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($students as $s): ?>
            <tr>
              <td><code style="font-size:.82rem;background:#f3f0ff;color:var(--purple-mid);padding:.15rem .45rem;border-radius:5px"><?= htmlspecialchars($s['id_number']) ?></code></td>
              <td><strong><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></strong></td>
              <td><?= $s['year_level'] ?></td>
              <td><span class="badge purple"><?= htmlspecialchars($s['course']) ?></span></td>
              <td><?= $s['remaining_session'] ?? 30 ?></td>
              <td style="display:flex;gap:.4rem">
                <button class="btn-outline-sm edit-student-btn" data-id="<?= $s['id'] ?>" data-first="<?= htmlspecialchars($s['first_name']) ?>" data-last="<?= htmlspecialchars($s['last_name']) ?>" data-course="<?= htmlspecialchars($s['course']) ?>" data-year="<?= $s['year_level'] ?>" data-sessions="<?= $s['remaining_session'] ?? 30 ?>"><i class="fa-solid fa-pen"></i> Edit</button>
                <button class="btn-danger-sm delete-student-btn" data-id="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?>"><i class="fa-solid fa-trash"></i> Delete</button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="tbl-pagination" id="studentsPagination">
        <span class="tbl-page-info" id="studentsPaginationInfo"></span>
        <div class="tbl-page-controls">
          <button class="tbl-page-btn" id="studentsPrevBtn" disabled><i class="fa-solid fa-chevron-left"></i></button>
          <button class="tbl-page-btn" id="studentsNextBtn" disabled><i class="fa-solid fa-chevron-right"></i></button>
        </div>
      </div>
    </div>
  </div>

  <!-- CURRENT SIT-IN PAGE -->
  <div class="dash-page" id="page-sitin">
    <div class="page-header"><div><div class="page-title">Current Sit-in Sessions</div><div class="page-sub">Monitor all active sit-in sessions.</div></div></div>
    <div class="dash-card no-pad">
      <div class="table-toolbar">
        <span class="toolbar-label"><i class="fa-solid fa-desktop"></i> Active Sessions</span>
        <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
          <div class="search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="text" class="search-input" id="sitinSearchFilter" placeholder="Filter..."/></div>
          <select id="sitinLabFilter" class="filter-select"><option value="all">All Labs</option><?php foreach($all_labs as $lb): ?><option value="<?= htmlspecialchars($lb['name']) ?>"><?= htmlspecialchars($lb['name']) ?></option><?php endforeach; ?></select>
        </div>
      </div>
      <div class="table-wrap">
        <table class="data-table" id="sitinTable">
          <thead><tr><th>Sit ID</th><th>ID Number</th><th>Name</th><th>Purpose</th><th>Lab</th><th>Session Left</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
            <?php if (empty($sitins)): ?>
            <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:2rem">No active sit-in sessions</div></tr>
            <?php else: foreach ($sitins as $sit): ?>
            <tr data-sitin-id="<?= $sit['id'] ?>">
              <td><code style="font-size:.82rem;background:#f3f0ff;color:var(--purple-mid);padding:.15rem .45rem;border-radius:5px"><?= $sit['id'] ?></code></td>
              <td><?= htmlspecialchars($sit['student_id_number']) ?></div>
              <td><strong><?= htmlspecialchars($sit['first_name'].' '.$sit['last_name']) ?></strong></div>
              <td><?= htmlspecialchars($sit['purpose'] ?? '—') ?></div>
              <td><span class="badge purple"><?= htmlspecialchars($sit['lab'] ?? '—') ?></span></div>
              <td><?= htmlspecialchars($sit['remaining_session'] ?? '—') ?></div>
              <td><span class="badge green"><i class="fa-solid fa-circle"></i> Active</span></div>
              <td><button class="btn-end end-sitin-btn" data-id="<?= $sit['id'] ?>"><i class="fa-solid fa-right-from-bracket"></i> End</button></div>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="tbl-pagination" id="sitinPagination">
        <span class="tbl-page-info" id="sitinPaginationInfo"></span>
        <div class="tbl-page-controls">
          <button class="tbl-page-btn" id="sitinPrevBtn" disabled><i class="fa-solid fa-chevron-left"></i></button>
          <button class="tbl-page-btn" id="sitinNextBtn" disabled><i class="fa-solid fa-chevron-right"></i></button>
        </div>
      </div>
    </div>
  </div>

  <!-- SIT-IN RECORDS PAGE -->
  <div class="dash-page" id="page-records">
    <div class="page-header">
      <div><div class="page-title">Sit-in Records</div><div class="page-sub">Complete log of all sit-in sessions.</div></div>
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <span class="export-date-label">From</span>
        <input type="date" class="export-date-input" id="recordsExportFrom">
        <span class="export-date-label">To</span>
        <input type="date" class="export-date-input" id="recordsExportTo">
        <div class="export-dropdown-wrap" style="position:relative">
          <button class="export-btn-main" id="recordsExportDropBtn"><i class="fa-solid fa-download"></i> Export <i class="fa-solid fa-chevron-down" style="font-size:.65rem;margin-left:.2rem"></i></button>
          <div class="export-dropdown-menu" id="recordsExportMenu">
            <a href="admin.php?export=records&format=csv" id="recordsExportCsv"><i class="fa-solid fa-file-csv"></i> CSV</a>
            <a href="admin.php?export=records&format=xlsx" id="recordsExportXlsx"><i class="fa-solid fa-file-excel"></i> Excel</a>
            <a href="admin.php?export=records&format=pdf" id="recordsExportPdf"><i class="fa-solid fa-file-pdf"></i> PDF</a>
          </div>
        </div>
      </div>
    </div>
    <div class="dash-card no-pad">
      <div class="table-toolbar">
        <span class="toolbar-label"><i class="fa-solid fa-table-list"></i> All Records</span>
        <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
          <div class="search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="text" class="search-input" id="recordsSearch" placeholder="Search records..."/></div>
          <select id="recordsLabFilter" class="filter-select"><option value="all">All Labs</option><?php foreach($all_labs as $lb): ?><option value="<?= htmlspecialchars($lb['name']) ?>"><?= htmlspecialchars($lb['name']) ?></option><?php endforeach; ?></select>
          <select id="recordsStatusFilter" class="filter-select"><option value="all">All Status</option><option value="active">Active</option><option value="done">Done</option></select>
        </div>
      </div>
      <div class="table-wrap">
        <table class="data-table" id="recordsTable">
          <thead><tr><th>#</th><th>ID Number</th><th>Name</th><th>Purpose</th><th>Lab</th><th>Time In</th><th>Time Out</th><th>Status</th><th>View</th></tr></thead>
          <tbody>
            <?php
            try {
              $allSitins = $pdo->query("SELECT s.*, st.id as student_db_id, st.first_name, st.last_name, st.id_number as student_id_number FROM sitins s JOIN students st ON s.student_id = st.id ORDER BY s.time_in DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
              foreach ($allSitins as $i => $r): ?>
              <tr data-lab="<?= htmlspecialchars($r['lab'] ?? '') ?>" data-status="<?= htmlspecialchars($r['status'] ?? '') ?>">
                <td style="color:var(--muted);font-size:.8rem"><?= $i+1 ?></td>
                <td><code style="font-size:.82rem;background:#f3f0ff;color:var(--purple-mid);padding:.15rem .45rem;border-radius:5px"><?= htmlspecialchars($r['student_id_number']) ?></code></td>
                <td><strong><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></strong></td>
                <td><?= htmlspecialchars($r['purpose'] ?? '—') ?></td>
                <td><span class="badge purple"><?= htmlspecialchars($r['lab'] ?? '—') ?></span></td>
                <td><?= $r['time_in'] ? date('M d, H:i', strtotime($r['time_in'])) : '—' ?></td>
                <td><?= $r['time_out'] ? date('M d, H:i', strtotime($r['time_out'])) : '—' ?></td>
                <td><span class="badge <?= $r['status']==='active'?'green':($r['status']==='done'?'purple':'red') ?>"><?= ucfirst($r['status']) ?></span></td>
                <td><button class="btn-view-student view-student-btn" data-student-id="<?= $r['student_db_id'] ?>"><i class="fa-solid fa-eye"></i> View</button></td>
              </tr>
              <?php endforeach;
            } catch (Exception $e) { echo '<tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--muted)">No records available.</div></td>'; } ?>
          </tbody>
        </table>
      </div>
      <div class="tbl-pagination" id="recordsPagination">
        <span class="tbl-page-info" id="recordsPaginationInfo"></span>
        <div class="tbl-page-controls">
          <button class="tbl-page-btn" id="recordsPrevBtn" disabled><i class="fa-solid fa-chevron-left"></i></button>
          <button class="tbl-page-btn" id="recordsNextBtn" disabled><i class="fa-solid fa-chevron-right"></i></button>
        </div>
      </div>
    </div>
  </div>

  <!-- ANALYTICS PAGE -->
  <div class="dash-page" id="page-reports">
    <div class="page-header">
      <div><div class="page-title"> Analytics & Reports</div><div class="page-sub">Comprehensive analytics across all laboratories and student activity.</div></div>
      <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
        <span class="export-date-label">From</span>
        <input type="date" class="export-date-input" id="analyticsExportFrom">
        <span class="export-date-label">To</span>
        <input type="date" class="export-date-input" id="analyticsExportTo">
        <div class="export-dropdown-wrap" style="position:relative">
          <button class="export-btn-main" id="analyticsExportDropBtn"><i class="fa-solid fa-download"></i> Export <i class="fa-solid fa-chevron-down" style="font-size:.65rem;margin-left:.2rem"></i></button>
          <div class="export-dropdown-menu" id="analyticsExportMenu">
            <a href="admin.php?export=analytics&format=csv" id="analyticsExportCsv"><i class="fa-solid fa-file-csv"></i> CSV</a>
            <a href="admin.php?export=analytics&format=xlsx" id="analyticsExportXlsx"><i class="fa-solid fa-file-excel"></i> Excel</a>
            <a href="admin.php?export=analytics&format=pdf" id="analyticsExportPdf"><i class="fa-solid fa-file-pdf"></i> PDF</a>
          </div>
        </div>
      </div>
    </div>
    
    <div class="stats-detail-grid">
      <div class="stats-detail-card"><div class="stats-detail-value" id="totalStudentsAnalytics"><?= $total ?></div><div class="stats-detail-label">Total Students</div></div>
      <div class="stats-detail-card"><div class="stats-detail-value" id="totalSitinsAnalytics"><?= $total_sitin ?></div><div class="stats-detail-label">Total Sit-ins</div></div>
      <div class="stats-detail-card"><div class="stats-detail-value"><?= $total > 0 ? round($total_sitin / $total, 1) : 0 ?></div><div class="stats-detail-label">Avg Sit-ins/Student</div></div>
      <div class="stats-detail-card"><div class="stats-detail-value"><?= $currently ?></div><div class="stats-detail-label">Currently Active</div></div>
    </div>

   <div class="stats-detail-grid">
  <div class="stats-detail-card">
    <div class="stats-detail-value"><i class="fa-solid fa-building"></i> <?= htmlspecialchars($analytics['most_visited_lab'] ?? '—') ?></div>
    <div class="stats-detail-label">Most Visited Lab</div>
  </div>
  <div class="stats-detail-card">
    <div class="stats-detail-value"><i class="fa-solid fa-graduation-cap"></i> <?= htmlspecialchars($analytics['most_active_course'] ?? '—') ?></div>
    <div class="stats-detail-label">Most Active Course</div>
  </div>
  <div class="stats-detail-card">
    <div class="stats-detail-value"><i class="fa-solid fa-clock"></i> <?= htmlspecialchars($analytics['peak_hour'] ?? '—') ?></div>
    <div class="stats-detail-label">Peak Hour</div>
  </div>
  <div class="stats-detail-card">
    <div class="stats-detail-value"><i class="fa-solid fa-chart-line"></i> <?= round($total_sitin / max(1, $total), 1) ?></div>
    <div class="stats-detail-label">Avg Sessions/Student</div>
  </div>
</div>
    
    <div class="home-grid" style="margin-bottom:1.5rem">
      <div class="dash-card"><div class="card-header"><h2><i class="fa-solid fa-chart-line"></i> Daily Sit-in Trends (Last 30 Days)</h2></div><canvas id="dailyTrendChart" height="180"></canvas></div>
      <div class="dash-card"><div class="card-header"><h2><i class="fa-solid fa-chart-simple"></i> Hourly Distribution</h2></div><canvas id="hourlyChart" height="180"></canvas></div>
    </div>
    
    <div class="home-grid" style="margin-bottom:1.5rem">
      <div class="dash-card"><div class="card-header"><h2><i class="fa-solid fa-graduation-cap"></i> Course Distribution</h2></div><canvas id="courseDistChart" height="180"></canvas></div>
      <div class="dash-card"><div class="card-header"><h2><i class="fa-solid fa-chart-pie"></i> Year Level Distribution</h2></div><canvas id="yearDistChart" height="180"></canvas></div>
    </div>

    <div class="dash-card" style="margin-bottom:1.5rem">
      <div class="card-header"><h2><i class="fa-solid fa-building"></i> Lab Sit-in Activity <span style="font-size:.72rem;font-weight:500;color:var(--muted)">(synced with <?= count($all_labs) ?> registered labs — purple=active, grey=inactive)</span></h2></div>
      <canvas id="labUtilChart" height="120"></canvas>
    </div>
    
    <div class="dash-card no-pad">
      <div class="card-header" style="padding:1rem 1.5rem;margin:0"><h2><i class="fa-solid fa-building"></i> Lab Utilization Report <span style="font-size:.72rem;font-weight:500;color:var(--muted);margin-left:.5rem">(All <?= count($all_labs) ?> registered labs)</span></h2></div>
      <div class="table-wrap">
        <table class="data-table" id="analyticsLabTable">
          <thead><tr><th>Laboratory</th><th>Status</th><th>Total Sit-ins</th><th>Unique Students</th><th>Approved Reservations</th><th>Utilization Rate</th></tr></thead>
          <tbody>
            <?php
            // Build a map from lab_utilization results
            $labUtilMap = [];
            foreach ($analytics['lab_utilization'] ?? [] as $lu) { $labUtilMap[$lu['lab']] = $lu; }
            $maxSitins = 0;
            foreach ($all_labs as $lb) { $cnt = $labUtilMap[$lb['name']]['total_sitins'] ?? 0; if($cnt > $maxSitins) $maxSitins = $cnt; }
            foreach ($all_labs as $lb):
              $lu = $labUtilMap[$lb['name']] ?? ['total_sitins'=>0,'unique_students'=>0];
              $percent = $maxSitins > 0 ? round(($lu['total_sitins'] / $maxSitins) * 100) : 0;
              try { $rsvCount = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE lab=? AND status='approved'"); $rsvCount->execute([$lb['name']]); $rsvCnt = $rsvCount->fetchColumn(); } catch(Exception $e){ $rsvCnt=0; }
            ?>
            <tr>
              <td><span class="badge purple"><i class="fa-solid fa-building" style="margin-right:.3rem;font-size:.7rem"></i><?= htmlspecialchars($lb['name']) ?></span></td>
              <td><?php if($lb['is_active']): ?><span class="badge green">Active</span><?php else: ?><span class="badge red">Inactive</span><?php endif; ?></td>
              <td><strong><?= $lu['total_sitins'] ?></strong></td>
              <td><?= $lu['unique_students'] ?></td>
              <td><?= $rsvCnt ?></td>
              <td style="min-width:180px">
                <div style="display:flex;align-items:center;gap:.5rem">
                  <div class="lab-usage-bar" style="flex:1"><div class="lab-usage-fill" style="width:<?= $percent ?>%"></div></div>
                  <span style="font-size:.7rem;min-width:30px"><?= $percent ?>%</span>
                </div>
               </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($all_labs)): ?>
            <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">No laboratories registered.</div></td>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- FEEDBACK PAGE -->
  <div class="dash-page" id="page-feedback">
    <div class="page-header">
      <div>
        <div class="page-title">Feedback Reports</div>
        <div class="page-sub">
          Student feedback from sit-in sessions.
        </div>
      </div>
      <div class="page-actions">
        <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
          <span class="export-date-label">From</span>
          <input type="date" class="export-date-input" id="feedbackExportFrom">
          <span class="export-date-label">To</span>
          <input type="date" class="export-date-input" id="feedbackExportTo">
          <div class="export-dropdown-wrap" style="position:relative">
            <button class="export-btn-main" id="feedbackExportDropBtn"><i class="fa-solid fa-download"></i> Export <i class="fa-solid fa-chevron-down" style="font-size:.65rem;margin-left:.2rem"></i></button>
            <div class="export-dropdown-menu" id="feedbackExportMenu">
              <a href="admin.php?export=feedback&format=csv" id="feedbackExportCsv"><i class="fa-solid fa-file-csv"></i> CSV</a>
              <a href="admin.php?export=feedback&format=xlsx" id="feedbackExportXlsx"><i class="fa-solid fa-file-excel"></i> Excel</a>
              <a href="admin.php?export=feedback&format=pdf" id="feedbackExportPdf"><i class="fa-solid fa-file-pdf"></i> PDF</a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Real-time stat cards -->
    <div class="stats-summary" id="feedbackStatsRow">
      <div class="feedback-stat-card"><div class="feedback-stat-value" id="fbStatTotal">—</div><div class="feedback-stat-label">Total Feedback</div></div>
      <div class="feedback-stat-card"><div class="feedback-stat-value" id="fbStatAvg">—</div><div class="feedback-stat-label">Average Rating</div></div>
      <div class="feedback-stat-card"><div class="feedback-stat-value" id="fbStatPositive" style="color:#16a34a">—</div><div class="feedback-stat-label">Positive (4-5★)</div></div>
      <div class="feedback-stat-card"><div class="feedback-stat-value" id="fbStatNegative" style="color:#ef4444">—</div><div class="feedback-stat-label">Negative (1-2★)</div></div>
    </div>

    <div class="dash-card no-pad">
      <div class="table-toolbar">
        <span class="toolbar-label"><i class="fa-solid fa-comments"></i> Feedback List</span>
        <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
          <div class="search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="text" class="search-input" id="feedbackSearch" placeholder="Search feedback..."/></div>
          <select id="feedbackRatingFilter" class="filter-select">
            <option value="all">All Ratings</option>
            <option value="5">5 ★ (Excellent)</option>
            <option value="4">4 ★ (Good)</option>
            <option value="3">3 ★ (Average)</option>
            <option value="2">2 ★ (Poor)</option>
            <option value="1">1 ★ (Very Poor)</option>
          </select>
        </div>
      </div>

      <!-- Loading skeleton -->
      <div id="fbLoadingRow" style="text-align:center;padding:2.5rem;color:var(--muted);font-size:.85rem">
        <i class="fa-solid fa-spinner fa-spin" style="font-size:1.4rem;color:#a78bfa;display:block;margin-bottom:.6rem"></i> Loading feedback…
      </div>

      <!-- Empty state -->
      <div id="fbEmptyState" style="display:none;text-align:center;padding:3rem;color:var(--muted)">
        <i class="fa-solid fa-comments" style="font-size:3rem;color:#ddd6fe;display:block;margin-bottom:1rem"></i>
        <h3 style="font-family:var(--ff);color:var(--text);margin-bottom:.4rem">No Feedback Yet</h3>
        <p style="font-size:.875rem">Feedback submitted by students will appear here.</p>
      </div>

      <div class="table-wrap" id="fbTableWrap" style="display:none">
        <table class="data-table" id="feedbackTable">
          <thead><tr><th>#</th><th>Date</th><th>ID Number</th><th>Student</th><th>Course</th><th>Lab</th><th>Purpose</th><th>Rating</th><th>Comment</th></tr></thead>
          <tbody id="feedbackTbody"></tbody>
        </table>
      </div>
      <div class="tbl-pagination" id="feedbackPagination" style="display:none">
        <span class="tbl-page-info" id="feedbackPaginationInfo"></span>
        <div class="tbl-page-controls">
          <button class="tbl-page-btn" id="feedbackPrevBtn" disabled><i class="fa-solid fa-chevron-left"></i></button>
          <button class="tbl-page-btn" id="feedbackNextBtn" disabled><i class="fa-solid fa-chevron-right"></i></button>
        </div>
      </div>
    </div>
  </div>

  <!-- RESERVATION PAGE - TABBED LAYOUT -->
  <div class="dash-page" id="page-reservation">
    <div class="page-header">
      <div><div class="page-title"> Reservation Management</div><div class="page-sub">Manage laboratories, review requests, and view activity logs.</div></div>
    </div>

    <!-- Tab Navigation -->
    <div class="rsv-tabs">
      <button class="rsv-tab-btn active" data-rsv-tab="requests"><i class="fa-solid fa-inbox"></i> Reservation Requests <span id="pendingBadge" class="pending-count" style="display:none"></span></button>
      <button class="rsv-tab-btn" data-rsv-tab="logs"><i class="fa-solid fa-scroll"></i> Activity Logs</button>
    </div>
    <!-- TAB: Reservation Requests -->
    <div class="rsv-tab-panel" id="rsv-tab-requests" style="display:block">
      <!-- Status Tabs + Filters Row -->
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem;margin:1rem 0 1rem">
        <!-- Left: Status Tab Pills -->
        <div style="display:flex;gap:.45rem;flex-wrap:wrap">
          <button class="rsv-status-tab active" data-status-tab="current" style="display:inline-flex;align-items:center;gap:.4rem;padding:.45rem 1rem;border-radius:20px;border:2px solid #6c3fcf;background:#6c3fcf;color:#fff;font-size:.8rem;font-weight:700;cursor:pointer;transition:all .15s"><i class="fa-solid fa-circle-dot"></i> Current <span class="rsv-status-count" id="countCurrent" style="background:rgba(255,255,255,0.25);padding:.05rem .45rem;border-radius:10px;font-size:.72rem;font-weight:800;margin-left:.15rem"></span></button>
          <button class="rsv-status-tab" data-status-tab="upcoming" style="display:inline-flex;align-items:center;gap:.4rem;padding:.45rem 1rem;border-radius:20px;border:2px solid #e5e7eb;background:#fff;color:#6b7280;font-size:.8rem;font-weight:700;cursor:pointer;transition:all .15s"><i class="fa-solid fa-calendar-check"></i> Upcoming <span class="rsv-status-count" id="countUpcoming" style="background:#f3f4f6;padding:.05rem .45rem;border-radius:10px;font-size:.72rem;font-weight:800;margin-left:.15rem"></span></button>
          <button class="rsv-status-tab" data-status-tab="completed" style="display:inline-flex;align-items:center;gap:.4rem;padding:.45rem 1rem;border-radius:20px;border:2px solid #e5e7eb;background:#fff;color:#6b7280;font-size:.8rem;font-weight:700;cursor:pointer;transition:all .15s"><i class="fa-solid fa-circle-check"></i> Completed <span class="rsv-status-count" id="countCompleted" style="background:#f3f4f6;padding:.05rem .45rem;border-radius:10px;font-size:.72rem;font-weight:800;margin-left:.15rem"></span></button>
          <button class="rsv-status-tab" data-status-tab="denied" style="display:inline-flex;align-items:center;gap:.4rem;padding:.45rem 1rem;border-radius:20px;border:2px solid #e5e7eb;background:#fff;color:#6b7280;font-size:.8rem;font-weight:700;cursor:pointer;transition:all .15s"><i class="fa-solid fa-ban"></i> Denied <span class="rsv-status-count" id="countDenied" style="background:#f3f4f6;padding:.05rem .45rem;border-radius:10px;font-size:.72rem;font-weight:800;margin-left:.15rem"></span></button>
        </div>
        <!-- Right: Lab Filter + Search -->
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
          <select id="reservationLabFilter" class="filter-select">
            <option value="all">All Labs</option>
            <?php foreach($all_labs as $lb): ?><option value="<?= htmlspecialchars($lb['name']) ?>"><?= htmlspecialchars($lb['name']) ?></option><?php endforeach; ?>
          </select>
          <div class="search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="text" class="search-input" id="reservationSearch" placeholder="Search student or lab..."/></div>
        </div>
      </div>

      <?php if (empty($all_reservations)): ?>
      <div class="dash-card" style="text-align:center;padding:3rem;color:var(--muted)"><i class="fa-solid fa-calendar-check" style="font-size:3rem;color:#ddd6fe;display:block;margin-bottom:1rem"></i><h3 style="font-family:var(--ff);color:var(--text);margin-bottom:.4rem">No Reservations Yet</h3><p style="font-size:.875rem">Student reservations will appear here once submitted.</p></div>
      <?php else: ?>
      <div class="reservation-cards-grid" id="reservationCardsGrid">
        <?php
          foreach ($all_reservations as $r):
          $statusBg = $r['status'] === 'approved' ? '#22c55e' : ($r['status'] === 'pending' ? '#f59e0b' : ($r['status'] === 'done' ? '#6c3fcf' : ($r['status'] === 'cancelled' ? '#ef4444' : '#9ca3af')));
          $statusText = $r['status'] === 'cancelled' ? 'Denied' : ($r['status'] === 'done' ? 'Completed' : ($r['status'] === 'approved' ? 'Approved' : ($r['status'] === 'pending' ? 'Pending' : ucfirst($r['status']))));
          $reservationDate = !empty($r['date']) ? date('M d, Y', strtotime($r['date'])) : '—';
          $timeIn = !empty($r['time_in']) ? date('h:i A', strtotime($r['time_in'])) : '—';
          $initial = isset($r['first_name']) && strlen($r['first_name']) > 0 ? strtoupper(substr($r['first_name'], 0, 1)) : 'S';
        ?>
        <div class="reservation-card" data-status="<?= $r['status'] ?>" data-lab="<?= htmlspecialchars($r['lab']??'') ?>" data-student-id="<?= $r['student_db_id'] ?>" data-reservation-id="<?= $r['id'] ?>">
          <div style="background:linear-gradient(135deg,#6c3fcf,#a259f7);padding:0.9rem 1.2rem;color:#fff">
            <div style="display:flex;align-items:center;justify-content:space-between">
              <div><div style="font-size:.7rem;opacity:0.8;letter-spacing:0.5px">LABORATORY</div><div style="font-size:1.3rem;font-weight:800"><?= htmlspecialchars($r['lab']??'—') ?></div></div>
              <div class="status-badge-res" style="background:<?= $statusBg ?>"><?= $statusText ?></div>
            </div>
          </div>
          <div style="padding:1rem 1.2rem">
            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem">
              <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#ede9fe,#f5f3ff);display:flex;align-items:center;justify-content:center;color:#6c3fcf;font-weight:800;font-size:1rem"><?= $initial ?></div>
              <div><div style="font-weight:800;color:#1a1a2e"><?= htmlspecialchars($r['first_name'] ?? '') . ' ' . htmlspecialchars($r['last_name'] ?? '') ?></div><div style="font-size:.7rem;color:#6b7280">ID: <?= htmlspecialchars($r['student_id_number'] ?? '—') ?></div></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:1rem">
              <div><div style="font-size:.65rem;color:#6b7280;margin-bottom:.15rem">COURSE</div><div style="font-size:.8rem;font-weight:600"><?= htmlspecialchars($r['course'] ?? '—') ?></div></div>
              <div><div style="font-size:.65rem;color:#6b7280;margin-bottom:.15rem">PURPOSE</div><div style="font-size:.8rem;font-weight:600"><?= htmlspecialchars($r['purpose'] ?? '—') ?></div></div>
              <div><div style="font-size:.65rem;color:#6b7280;margin-bottom:.15rem">DATE</div><div style="font-size:.8rem;font-weight:600"><?= $reservationDate ?></div></div>
              <div><div style="font-size:.65rem;color:#6b7280;margin-bottom:.15rem">TIME</div><div style="font-size:.8rem;font-weight:600"><?= $timeIn ?></div></div>
              <?php $displayPc = $r['admin_pc'] ?: $r['pc_number']; ?>
              <div style="grid-column:1/-1"><div style="font-size:.65rem;color:#6b7280;margin-bottom:.15rem">REQUESTED PC</div><div style="font-size:.8rem;font-weight:600"><?= $displayPc ? '<span style="background:#ede9fe;color:#6c3fcf;padding:.1rem .45rem;border-radius:5px;font-size:.78rem;font-weight:700"><i class="fa-solid fa-desktop" style="font-size:.68rem"></i> PC-'.str_pad($displayPc,2,'0',STR_PAD_LEFT).'</span>' : '<span style="color:#9ca3af">No preference</span>' ?></div></div>
            </div>
            <div style="display:flex;gap:.6rem;margin-top:.5rem;border-top:1px solid #f0ecff;padding-top:.9rem">
              <button class="view-student-btn" data-student-id="<?= $r['student_db_id'] ?>" style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.5rem;border-radius:8px;border:1.5px solid #e5e7eb;background:#fff;color:#4b5563;font-size:.75rem;font-weight:600;cursor:pointer"><i class="fa-solid fa-eye"></i> View</button>
              <?php if ($r['status'] === 'pending'): ?>
                <button class="approve-rsv-btn" data-id="<?= $r['id'] ?>" data-student="<?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?>" data-lab="<?= htmlspecialchars($r['lab']??'') ?>" data-date="<?= htmlspecialchars($r['date']??'') ?>" data-time="<?= htmlspecialchars($r['time_in']??'') ?>" data-purpose="<?= htmlspecialchars($r['purpose']??'') ?>" data-pc="<?= htmlspecialchars($r['pc_number']??'') ?>" style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.5rem;border-radius:8px;border:none;background:#22c55e;color:#fff;font-size:.75rem;font-weight:700;cursor:pointer"><i class="fa-solid fa-check"></i> Approve</button>
                <button class="reject-rsv-btn" data-id="<?= $r['id'] ?>" data-student="<?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?>" data-lab="<?= htmlspecialchars($r['lab']??'') ?>" data-date="<?= htmlspecialchars($r['date']??'') ?>" data-time="<?= htmlspecialchars($r['time_in']??'') ?>" data-purpose="<?= htmlspecialchars($r['purpose']??'') ?>" data-pc="<?= htmlspecialchars($r['pc_number']??'') ?>" style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.5rem;border-radius:8px;border:1.5px solid #fecaca;background:#fef2f2;color:#ef4444;font-size:.75rem;font-weight:600;cursor:pointer"><i class="fa-solid fa-ban"></i> Deny</button>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- TAB: Lab Management (Lab Management) -->


    <!-- TAB: Activity Logs -->
    <div class="rsv-tab-panel" id="rsv-tab-logs" style="display:none">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin:1rem 0 .75rem">
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
          <select id="logsTypeFilter" class="filter-select">
            <option value="all">All Actions</option>
            <option value="RESERVATION_APPROVED">Approved</option>
            <option value="RESERVATION_DENIED">Denied</option>
          </select>
          <div class="search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="text" class="search-input" id="logsSearch" placeholder="Search logs..."/></div>
        </div>
        <button class="btn-outline-sm" id="refreshLogsBtn"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
      </div>
      <div class="dash-card no-pad">
        <div class="table-wrap">
          <table class="data-table" id="logsTable">
            <thead>
              <tr><th>#</th><th>Date &amp; Time</th><th>Admin</th><th>Action</th><th>Description</th></tr>
            </thead>
            <tbody id="logsTableBody">
              <?php if(empty($activity_logs)): ?>
              <tr><td colspan="5" style="text-align:center;padding:3rem;color:var(--muted)"><i class="fa-solid fa-scroll" style="font-size:2rem;color:#ddd6fe;display:block;margin-bottom:.7rem"></i>No activity logs yet.</div></td>
              <?php else: foreach($activity_logs as $i => $log):
                $actionColor = [
                  'RESERVATION_APPROVED'  => 'green',
                  'RESERVATION_DENIED'    => 'red',
                  'RESERVATION_CANCELLED' => 'red',
                ][$log['action_type']] ?? 'purple';
              ?>
              <tr data-action-type="<?= htmlspecialchars($log['action_type']) ?>">
                <td style="color:var(--muted);font-size:.8rem"><?= $i+1 ?> </div>
                <td style="white-space:nowrap;font-size:.8rem"><?= date('M d, Y H:i', strtotime($log['created_at'])) ?></div>
                <td><strong><?= htmlspecialchars($log['admin_name']) ?></strong></div>
                <td><span class="badge <?= $actionColor ?>"><?= htmlspecialchars(str_replace('_',' ', $log['action_type'])) ?></span></div>
                <td style="max-width:350px;white-space:normal;font-size:.83rem"><?= htmlspecialchars($log['description']) ?></div>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- end page-reservation -->
  <!-- ═══════════════════════════ CONTROLS PAGE ═══════════════════════════ -->
  <div class="dash-page" id="page-controls">
    <div class="page-header">
      <div>
        <div class="page-title">Controls</div>
        <div class="page-sub">Manage laboratories, software availability, and system settings.</div>
      </div>
    </div>

    <!-- Inner tabs -->
    <div class="rsv-tabs" style="margin-bottom:1.25rem">
      <button class="rsv-tab-btn active" data-ctrl-tab="lab-management"><i class="fa-solid fa-display"></i> Lab Management</button>
      <button class="rsv-tab-btn" data-ctrl-tab="file-uploads"><i class="fa-solid fa-folder-open"></i> File Uploads</button>
    </div>

    <!-- Lab Management panel -->
    <div class="ctrl-tab-panel" id="ctrl-tab-lab-management" style="display:block">

      <!-- Reservation System Toggle -->
      <div class="dash-card" style="margin-bottom:1.25rem" id="reservationToggleCard">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem">
          <div style="display:flex;align-items:center;gap:.9rem">
            <div style="width:46px;height:46px;border-radius:13px;background:#ede9fe;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:var(--purple-mid);flex-shrink:0" id="rsvToggleIcon">
              <i class="fa-solid <?= $reservation_enabled ? 'fa-calendar-check' : 'fa-calendar-xmark' ?>"></i>
            </div>
            <div>
              <div style="font-family:var(--ff);font-size:.97rem;font-weight:800;color:var(--text)">Reservation System</div>
              <div style="font-size:.8rem;color:var(--muted);margin-top:.1rem">Allow students to submit lab reservations from their dashboard.</div>
              <div style="margin-top:.35rem;display:flex;align-items:center;gap:.4rem">
                <span id="rsvStatusDot" style="width:8px;height:8px;border-radius:50%;background:<?= $reservation_enabled ? '#6c3fcf' : '#9ca3af' ?>;display:inline-block;<?= $reservation_enabled ? 'animation:pulse 1.5s infinite' : '' ?>"></span>
                <span id="rsvStatusText" style="font-size:.78rem;font-weight:700;color:var(--muted)"><?= $reservation_enabled ? 'Enabled — Students can make reservations' : 'Disabled — Reservations are blocked' ?></span>
              </div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:.75rem">
            <span style="font-size:.78rem;font-weight:600;color:var(--muted)" id="rsvLabelLeft"><?= $reservation_enabled ? '' : 'Disable' ?></span>
            <label class="rsv-toggle-switch" style="cursor:pointer;display:inline-block;position:relative;width:54px;height:28px">
              <input type="checkbox" id="reservationToggle" <?= $reservation_enabled ? 'checked' : '' ?> style="opacity:0;width:0;height:0;position:absolute">
              <span class="rsv-toggle-knob"></span>
            </label>
            <span style="font-size:.78rem;font-weight:600;color:var(--muted)" id="rsvLabelRight"><?= $reservation_enabled ? 'Disable' : 'Enable' ?></span>
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-top:1rem">

        <!-- Add Lab Form -->
        <div class="dash-card">
          <div class="card-header"><h2><i class="fa-solid fa-plus-circle"></i> Add New Laboratory</h2></div>
          <div class="form-group"><label>Laboratory Name</label><input type="text" id="newLabName" placeholder="e.g., Lab 546"/></div>
          <div class="form-group"><label>Capacity (seats)</label><input type="number" id="newLabCapacity" value="40" min="1" max="200"/></div>
          <button class="btn-primary-sm" id="addLabBtn" style="width:100%;justify-content:center"><i class="fa-solid fa-plus"></i> Add Laboratory</button>
        </div>

        <!-- Lab Stats Summary -->
        <div class="dash-card">
          <div class="card-header"><h2><i class="fa-solid fa-chart-simple"></i> Labs Overview</h2></div>
          <div id="labsOverviewStats" style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
            <?php
            $totalLabs = count($all_labs);
            $activeLabs = count(array_filter($all_labs, fn($l) => $l['is_active']));
            ?>
            <div style="background:#f3f0ff;border-radius:10px;padding:.75rem 1rem;text-align:center"><div id="ovTotalLabs" style="font-family:var(--ff);font-size:1.4rem;font-weight:800;color:var(--purple-mid)"><?= $totalLabs ?></div><div style="font-size:.72rem;color:var(--muted)">Total Labs</div></div>
            <div style="background:#dcfce7;border-radius:10px;padding:.75rem 1rem;text-align:center"><div id="ovActiveLabs" style="font-family:var(--ff);font-size:1.4rem;font-weight:800;color:#15803d"><?= $activeLabs ?></div><div style="font-size:.72rem;color:var(--muted)">Active Labs</div></div>
            <div style="background:#fef9c3;border-radius:10px;padding:.75rem 1rem;text-align:center"><div id="ovInactiveLabs" style="font-family:var(--ff);font-size:1.4rem;font-weight:800;color:#92400e"><?= $totalLabs - $activeLabs ?></div><div style="font-size:.72rem;color:var(--muted)">Inactive Labs</div></div>
            <div style="background:#ede9fe;border-radius:10px;padding:.75rem 1rem;text-align:center"><div id="ovTotalCapacity" style="font-family:var(--ff);font-size:1.4rem;font-weight:800;color:var(--purple-mid)"><?= array_sum(array_column($all_labs,'capacity')) ?></div><div style="font-size:.72rem;color:var(--muted)">Total Capacity</div></div>
          </div>
        </div>
      </div>

      <!-- Labs Table -->
      <div class="dash-card no-pad" style="margin-top:1rem">
        <div class="table-toolbar">
          <h2 style="font-family:var(--ff);font-size:.92rem;font-weight:800"><i class="fa-solid fa-building"></i> All Laboratories</h2>
          <div class="search-wrap"><i class="fa-solid fa-magnifying-glass"></i><input type="text" class="search-input" id="labsSearch" placeholder="Search labs..."/></div>
        </div>
        <div class="table-wrap">
          <table class="data-table" id="labsTable">
            <thead><tr><th>#</th><th>Laboratory Name</th><th>Capacity</th><th>Status</th><th>Usage (Reservations)</th><th>Actions</th></tr></thead>
            <tbody id="labsTableBody">
              <?php foreach ($all_labs as $i => $lb):
                try { $labUsage = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE lab=? AND status='approved'"); $labUsage->execute([$lb['name']]); $usageCount = $labUsage->fetchColumn(); } catch(Exception $e){ $usageCount=0; }
              ?>
              <tr data-lab-id="<?= $lb['id'] ?>">
                <td style="color:var(--muted);font-size:.8rem"><?= $i+1 ?></td>
                <td><strong><i class="fa-solid fa-building" style="color:var(--purple-mid);margin-right:.4rem"></i><?= htmlspecialchars($lb['name']) ?></strong></td>
                <td><span class="badge blue"><?= $lb['capacity'] ?> seats</span></td>
                <td>
                  <?php if($lb['is_active']): ?>
                  <span class="badge green"><i class="fa-solid fa-circle" style="font-size:.5rem"></i> Active</span>
                  <?php else: ?>
                  <span class="badge red"><i class="fa-solid fa-circle" style="font-size:.5rem"></i> Inactive</span>
                  <?php endif; ?>
                 </div>
                </td>
                <td><span style="font-weight:700;color:var(--purple-mid)"><?= $usageCount ?></span> approved reservations</div>
                 </div>
                </td>
                <td style="display:flex;gap:.4rem">
                  <button class="btn-outline-sm edit-lab-btn" data-id="<?= $lb['id'] ?>" data-name="<?= htmlspecialchars($lb['name']) ?>" data-capacity="<?= $lb['capacity'] ?>"><i class="fa-solid fa-pen"></i> Edit</button>
                  <button class="btn-outline-sm toggle-lab-btn" data-id="<?= $lb['id'] ?>" data-active="<?= $lb['is_active'] ?>">
                    <i class="fa-solid <?= $lb['is_active'] ? 'fa-toggle-on' : 'fa-toggle-off' ?>"></i> <?= $lb['is_active'] ? 'Disable' : 'Enable' ?>
                  </button>
                  <button class="btn-danger-sm delete-lab-btn" data-id="<?= $lb['id'] ?>" data-name="<?= htmlspecialchars($lb['name']) ?>"><i class="fa-solid fa-trash"></i> Delete</button>
                 </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if(empty($all_labs)): ?> <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">No laboratories found.</div></td><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Danger Zone -->
      <div class="dash-card" style="margin-top:1.5rem;border:2px solid #fecaca">
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem">
          <div style="width:40px;height:40px;border-radius:11px;background:#fef2f2;display:flex;align-items:center;justify-content:center;color:#ef4444;font-size:1.1rem;flex-shrink:0"><i class="fa-solid fa-triangle-exclamation"></i></div>
          <div>
            <div style="font-family:var(--ff);font-size:.97rem;font-weight:800;color:#dc2626">Danger Zone</div>
            <div style="font-size:.78rem;color:var(--muted)">These actions are irreversible. Proceed with caution.</div>
          </div>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;padding:1rem;background:#fef2f2;border-radius:10px;border:1.5px dashed #fca5a5">
          <div>
            <div style="font-weight:700;font-size:.88rem;color:#dc2626"><i class="fa-solid fa-trash-can" style="margin-right:.35rem"></i>Reset All Sit-in Records</div>
            <div style="font-size:.76rem;color:var(--muted);margin-top:.2rem">Permanently deletes every sit-in log and resets all student sessions back to 30.</div>
          </div>
          <button id="openResetRecordsBtn" style="padding:.5rem 1.1rem;border-radius:8px;border:2px solid #ef4444;background:#fff;color:#ef4444;font-size:.8rem;font-weight:700;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:.4rem"><i class="fa-solid fa-rotate-left"></i> Reset Records</button>
        </div>
      </div>
    </div>

    <!-- File Uploads panel -->
    <div class="ctrl-tab-panel" id="ctrl-tab-file-uploads" style="display:none">
      <div style="display:grid;grid-template-columns:1fr 1.6fr;gap:1.25rem;align-items:start">

        <!-- Upload Card -->
        <div class="dash-card">
          <div class="card-header"><h2><i class="fa-solid fa-cloud-arrow-up"></i> Upload File</h2></div>

          <div class="file-upload-drop" id="fileDropZone">
            <i class="fa-solid fa-file-arrow-up"></i>
            <div class="file-drop-title">Click or drag & drop a file</div>
            <div class="file-drop-hint">PDF, Word, Excel, PowerPoint, TXT, Images, ZIP · Max 20 MB</div>
            <input type="file" id="fileUploadInput" style="display:none"
              accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.gif,.webp,.zip"/>
          </div>
          <div class="upload-progress" id="uploadProgress"><div class="upload-progress-bar" id="uploadProgressBar"></div></div>

          <div id="fileSelectedLabel" style="display:none;margin-top:.7rem;padding:.55rem .85rem;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:9px;font-size:.8rem;color:#15803d;align-items:center;gap:.5rem">
            <i class="fa-solid fa-file-circle-check"></i>
            <span id="fileSelectedName" style="font-weight:700;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
            <span id="fileSelectedSize" style="color:#4ade80;flex-shrink:0"></span>
          </div>

          <div style="margin-top:1rem;display:flex;flex-direction:column;gap:.6rem">
            <div class="form-group" style="margin:0">
              <label style="font-size:.78rem;font-weight:700;color:#374151;display:block;margin-bottom:.3rem">Description <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
              <textarea id="fileDescription" rows="2" placeholder="Brief description of this file…"
                style="width:100%;padding:.55rem .75rem;border:1.5px solid var(--border);border-radius:9px;font-size:.82rem;font-family:var(--fb);outline:none;resize:vertical;transition:border-color .2s"
                onfocus="this.style.borderColor='var(--purple-mid)'" onblur="this.style.borderColor='var(--border)'"></textarea>
            </div>
          </div>

          <button id="doUploadFileBtn" class="btn-primary-sm" style="width:100%;margin-top:1rem;justify-content:center" disabled>
            <i class="fa-solid fa-upload"></i> Upload File
          </button>
        </div>

        <!-- File Library Card -->
        <div class="dash-card">
          <div class="card-header" style="margin-bottom:.75rem">
            <h2><i class="fa-solid fa-folder-open"></i> File Library <span id="fileCountBadge" style="background:#ede9fe;color:var(--purple-mid);font-size:.68rem;font-weight:800;padding:.1rem .45rem;border-radius:6px;margin-left:.3rem">0</span></h2>
          </div>

          <div id="fileLibraryList" class="file-cards-grid">
            <div style="text-align:center;padding:2rem;color:var(--muted)"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>Loading files…</div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <!-- LEADERBOARD PAGE -->
  <div class="dash-page" id="page-leaderboard">
    <div class="page-header">
      <div><div class="page-title">Leaderboard</div><div class="page-sub">Top students ranked by sit-in score — based on total sessions, hours logged, and consistency.</div></div>
      <div class="page-actions">
        <button class="btn-outline-sm" id="refreshLeaderboardBtn"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
      </div>
    </div>

    <!-- Score formula legend -->
    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:1.25rem;background:#faf8ff;border:1.5px solid #ede9fe;border-radius:12px;padding:.7rem 1.1rem;font-size:.78rem;color:var(--muted)">
      <i class="fa-solid fa-circle-info" style="color:var(--purple-mid)"></i>
      <span><strong style="color:var(--text)">Score formula:</strong> (Sit-in Points × 0.5) + (Total Hours × 0.3)</span>
      <span style="color:#d1d5db">·</span>
      <span>1 Sit-in Point = every 3 completed sessions</span>
      <span style="color:#d1d5db">·</span>
      <span>Top 3 students shown</span>
    </div>

    <!-- Podium (top 3) -->
    <div id="leaderboardPodium" style="display:none;margin-bottom:1.5rem"></div>

    <!-- Full table -->
    <div class="dash-card no-pad">
      <div class="table-wrap">
        <table class="data-table" id="leaderboardTable">
          <thead>
            <tr>
              <th style="width:52px;text-align:center">Rank</th>
              <th>Student</th>
              <th>Course &amp; Year</th>
              <th style="text-align:center">Sessions</th>
              <th style="text-align:center">Rating</th>
              <th>Top Purpose</th>
              <th style="text-align:center">Last Seen</th>
              <th style="text-align:center">Score</th>
            </tr>
          </thead>
          <tbody id="leaderboardBody">
            <tr><td colspan="8" style="text-align:center;padding:3rem;color:var(--muted)"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;color:#ddd6fe;display:block;margin-bottom:.7rem"></i>Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</main>

<!-- MODALS -->
<div class="modal-overlay" id="searchModal"><div class="modal-card"><div class="modal-header"><h3><i class="fa-solid fa-id-card"></i> Student Profile</h3><button class="modal-close" id="closeSearchModal"><i class="fa-solid fa-xmark"></i></button></div><div class="modal-body" id="searchModalBody">—</div><div class="modal-footer"><button class="btn-outline-sm" id="closeSearchModal2">Close</button><button class="btn-primary-sm" id="sitInFromSearchBtn"><i class="fa-solid fa-desktop"></i> Start Sit-in</button></div></div></div>
<div class="modal-overlay" id="studentDetailsModal"><div class="modal-card"><div class="modal-header"><h3><i class="fa-solid fa-user-graduate"></i> Student Details</h3><button class="modal-close" id="closeStudentDetailsModal"><i class="fa-solid fa-xmark"></i></button></div><div class="modal-body" id="studentDetailsBody">—</div><div class="modal-footer"><button class="btn-outline-sm" id="closeStudentDetailsModal2">Close</button></div></div></div>
<div class="modal-overlay" id="sitinModal"><div class="modal-card"><div class="modal-header"><h3><i class="fa-solid fa-desktop"></i> Sit-in Form</h3><button class="modal-close" id="closeSitinModal"><i class="fa-solid fa-xmark"></i></button></div><div class="modal-body"><input type="hidden" id="sf_db_id"/><div class="form-group"><label>ID Number</label><input type="text" id="sf_id" readonly style="background:#f9fafb"/></div><div class="form-group"><label>Student Name</label><input type="text" id="sf_name" readonly style="background:#f9fafb"/></div><div class="form-group"><label>Purpose</label><select id="sf_purpose"><option value="">Select Purpose</option><option value="C Programming">C Programming</option><option value="Java">Java</option><option value="C#">C#</option><option value="ASP.Net">ASP.Net</option><option value="PHP">PHP</option><option value="Research">Research</option><option value="Project Work">Project Work</option><option value="Online Exam">Online Exam</option></select></div><div class="form-group"><label>Lab</label><select id="sf_lab"><option value="">Select Lab</option><?php foreach($all_labs as $lb): ?><option value="<?= htmlspecialchars($lb['name']) ?>"><?= htmlspecialchars($lb['name']) ?></option><?php endforeach; ?></select></div><div class="form-group" id="sf_pc_wrap" style="display:none"><label>PC Number <span style="font-size:.75rem;color:#9ca3af;font-weight:400">(optional)</span></label><select id="sf_pc_number"><option value="">— Select PC —</option></select><div id="sf_pc_loading" style="display:none;font-size:.78rem;color:var(--muted);margin-top:.3rem"><i class="fa-solid fa-spinner fa-spin"></i> Loading seats…</div></div><div class="form-group"><label>Remaining Session</label><input type="number" id="sf_sessions" readonly style="background:#f9fafb"/></div></div><div class="modal-footer"><button class="btn-outline-sm" id="closeSitinModal2">Cancel</button><button class="btn-primary-sm" id="confirmSitinBtn"><i class="fa-solid fa-right-to-bracket"></i> Start Sit-in</button></div></div></div>
<div class="modal-overlay" id="editLabModal"><div class="modal-card"><div class="modal-header"><h3><i class="fa-solid fa-building"></i> Edit Laboratory</h3><button class="modal-close" id="closeEditLabModal"><i class="fa-solid fa-xmark"></i></button></div><div class="modal-body"><input type="hidden" id="edit_lab_id"/><div class="form-group"><label>Laboratory Name</label><input type="text" id="edit_lab_name" placeholder="e.g., Lab 546"/></div><div class="form-group"><label>Capacity (seats)</label><input type="number" id="edit_lab_capacity" min="1" max="200"/></div></div><div class="modal-footer"><button class="btn-outline-sm" id="closeEditLabModal2">Cancel</button><button class="btn-primary-sm" id="saveEditLabBtn"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button></div></div></div>
<div class="modal-overlay" id="editStudentModal"><div class="modal-card"><div class="modal-header"><h3><i class="fa-solid fa-user-pen"></i> Edit Student</h3><button class="modal-close" id="closeEditModal"><i class="fa-solid fa-xmark"></i></button></div><div class="modal-body"><input type="hidden" id="edit_student_id"/><div class="form-row"><div class="form-group"><label>First Name</label><input type="text" id="edit_first"/></div><div class="form-group"><label>Last Name</label><input type="text" id="edit_last"/></div></div><div class="form-row"><div class="form-group"><label>Course</label><select id="edit_course"><option value="BSIT">BSIT – BS Information Technology</option><option value="BSCS">BSCS – BS Computer Science</option><option value="BSIS">BSIS – BS Information Systems</option><option value="BSDA">BSDA – BS Data Analytics</option><option value="ACT">ACT – Associate in Computer Technology</option><option value="BSECE">BSECE – BS Electronics Engineering</option><option value="BSEE">BSEE – BS Electrical Engineering</option><option value="BSCE">BSCE – BS Civil Engineering</option><option value="BSME">BSME – BS Mechanical Engineering</option><option value="BSCpE">BSCpE – BS Computer Engineering</option><option value="BSIE">BSIE – BS Industrial Engineering</option><option value="BSBA">BSBA – BS Business Administration</option><option value="BSED">BSED – BS Education</option><option value="BSPSYCH">BSPSYCH – BS Psychology</option><option value="BSCRIM">BSCRIM – BS Criminology</option><option value="Other">Other</option></select></div><div class="form-group"><label>Year Level</label><select id="edit_year"><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div></div><div class="form-group"><label>Remaining Session</label><input type="number" id="edit_sessions" min="0" max="30"/></div></div><div class="modal-footer"><button class="btn-outline-sm" id="closeEditModal2">Cancel</button><button class="btn-primary-sm" id="saveEditBtn"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button></div></div></div>
<div class="modal-overlay" id="addStudentModal"><div class="modal-card"><div class="modal-header"><h3><i class="fa-solid fa-user-plus"></i> Add New Student</h3><button class="modal-close" id="closeAddModal"><i class="fa-solid fa-xmark"></i></button></div><div class="modal-body"><div class="form-row"><div class="form-group"><label>ID Number *</label><input type="text" id="add_id_number" placeholder="e.g., 12345678"/></div><div class="form-group"><label>Last Name *</label><input type="text" id="add_last_name"/></div></div><div class="form-row"><div class="form-group"><label>First Name *</label><input type="text" id="add_first_name"/></div><div class="form-group"><label>Middle Name</label><input type="text" id="add_middle_name"/></div></div><div class="form-row"><div class="form-group"><label>Course</label><select id="add_course"><option value="BSIT">BSIT – BS Information Technology</option><option value="BSCS">BSCS – BS Computer Science</option><option value="BSIS">BSIS – BS Information Systems</option><option value="BSDA">BSDA – BS Data Analytics</option><option value="ACT">ACT – Associate in Computer Technology</option><option value="BSECE">BSECE – BS Electronics Engineering</option><option value="BSEE">BSEE – BS Electrical Engineering</option><option value="BSCE">BSCE – BS Civil Engineering</option><option value="BSME">BSME – BS Mechanical Engineering</option><option value="BSCpE">BSCpE – BS Computer Engineering</option><option value="BSIE">BSIE – BS Industrial Engineering</option><option value="BSBA">BSBA – BS Business Administration</option><option value="BSED">BSED – BS Education</option><option value="BSPSYCH">BSPSYCH – BS Psychology</option><option value="BSCRIM">BSCRIM – BS Criminology</option><option value="Other">Other</option></select></div><div class="form-group"><label>Year Level</label><select id="add_year"><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div></div><div class="form-group"><label>Email</label><input type="email" id="add_email" placeholder="student@example.com"/></div><div class="form-group"><label>Address</label><input type="text" id="add_address" placeholder="Student address"/></div><div class="form-group"><small style="color:var(--muted)"></small></div></div><div class="modal-footer"><button class="btn-outline-sm" id="closeAddModal2">Cancel</button><button class="btn-primary-sm" id="confirmAddStudentBtn"><i class="fa-solid fa-save"></i> Add Student</button></div></div></div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {

  /* PAGE SWITCHING */
  const sbLinks = document.querySelectorAll('.sb-link[data-page]');
  const pages   = document.querySelectorAll('.dash-page');
  function switchPage(id) {
    pages.forEach(p => p.classList.remove('active'));
    sbLinks.forEach(l => l.classList.remove('active'));
    const target = document.getElementById('page-' + id);
    if (target) target.classList.add('active');
    sbLinks.forEach(l => { if (l.dataset.page === id) l.classList.add('active'); });
    closeSidebar();
    if (id === 'reports') loadAnalyticsCharts();
    if (id === 'reservation') updatePendingBadge();
    if (id === 'controls') loadFiles();
    if (id === 'leaderboard') { loadLeaderboard(); }
  }
  sbLinks.forEach(l => l.addEventListener('click', e => {
    e.preventDefault();
    switchPage(l.dataset.page);
  }));

  /* RESERVATION SUB-TABS */
  function switchRsvTab(tabId) {
    document.querySelectorAll('.rsv-tab-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.rsv-tab-btn[data-rsv-tab]').forEach(b => b.classList.remove('active'));
    const panel = document.getElementById('rsv-tab-' + tabId);
    if (panel) panel.style.display = 'block';
    document.querySelectorAll(`.rsv-tab-btn[data-rsv-tab="${tabId}"]`).forEach(b => b.classList.add('active'));
    if (tabId === 'logs') loadActivityLogs();
  }
  document.querySelectorAll('.rsv-tab-btn[data-rsv-tab]').forEach(btn => {
    btn.addEventListener('click', e => { e.preventDefault(); switchRsvTab(btn.dataset.rsvTab); });
  });

  /* ── Controls page tab switching ── */
  function switchCtrlTab(tabId) {
    document.querySelectorAll('.ctrl-tab-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.rsv-tab-btn[data-ctrl-tab]').forEach(b => b.classList.remove('active'));
    const panel = document.getElementById('ctrl-tab-' + tabId);
    if (panel) panel.style.display = 'block';
    document.querySelectorAll(`.rsv-tab-btn[data-ctrl-tab="${tabId}"]`).forEach(b => b.classList.add('active'));
    if (tabId === 'file-uploads') loadFiles();
  }
  document.querySelectorAll('.rsv-tab-btn[data-ctrl-tab]').forEach(btn => {
    btn.addEventListener('click', e => { e.preventDefault(); switchCtrlTab(btn.dataset.ctrlTab); });
  });

  function updatePendingBadge() {
    const pending = document.querySelectorAll('.reservation-card[data-status="pending"]').length;
    const badge = document.getElementById('pendingBadge');
    if (badge) { badge.textContent = pending; badge.style.display = pending > 0 ? 'inline' : 'none'; }
  }
  updatePendingBadge();

  /* USER MENU */
  const userMenuBtn = document.getElementById('userMenuBtn');
  const userMenu    = document.getElementById('userMenu');
  const userChevron = document.getElementById('userChevron');
  userMenuBtn?.addEventListener('click', e => { e.stopPropagation(); const open = userMenu.classList.toggle('open'); userChevron?.classList.toggle('open', open); });
  document.addEventListener('click', e => { if (!userMenuBtn?.contains(e.target) && !userMenu?.contains(e.target)) { userMenu?.classList.remove('open'); userChevron?.classList.remove('open'); } });

  /* LOGOUT */
  document.getElementById('logoutBtn')?.addEventListener('click', async () => {
    const fd = new FormData(); fd.append('_action','logout');
    try { await fetch('admin.php', { method:'POST', body:fd }); } catch {}
    window.location.href = 'login.php';
  });

  /* MOBILE SIDEBAR */
  const sidebar        = document.getElementById('sidebar');
  const sbToggle       = document.getElementById('sbToggle');
  const sidebarOverlay = document.getElementById('sidebarOverlay');
  function closeSidebar() { sidebar?.classList.remove('open'); sidebarOverlay?.classList.remove('open'); }
  sbToggle?.addEventListener('click', () => { const open = sidebar.classList.toggle('open'); sidebarOverlay.classList.toggle('open', open); });
  sidebarOverlay?.addEventListener('click', closeSidebar);

  /* COURSE DISTRIBUTION CHART */
  const courseData = <?= json_encode($course_counts) ?>;
  const labels = Object.keys(courseData), values = Object.values(courseData);
  const colors = ['#6c3fcf','#a259f7','#f5c518','#22c55e','#ef4444','#3b82f6','#f97316','#ec4899'];
  if (labels.length && document.getElementById('courseChart')) {
    new Chart(document.getElementById('courseChart'), { type:'pie', data:{ labels, datasets:[{ data:values, backgroundColor:colors.slice(0,labels.length), borderWidth:2, borderColor:'#fff' }] }, options:{ responsive:true, plugins:{ legend:{ display:false } } } });
    const legend = document.getElementById('chartLegend');
    labels.forEach((l,i) => { const el=document.createElement('div'); el.style.cssText='display:flex;align-items:center;gap:.4rem;font-size:.78rem;font-weight:600'; el.innerHTML=`<span style="width:12px;height:12px;border-radius:3px;background:${colors[i]};flex-shrink:0"></span>${l}: ${values[i]}`; legend.appendChild(el); });
  }

  /* ANNOUNCEMENT */
  document.getElementById('annSubmitBtn')?.addEventListener('click', async () => {
    const text = document.getElementById('annText')?.value.trim();
    if (!text) { showToast('Please enter an announcement.', 'error'); return; }
    const fd = new FormData(); fd.append('_action','add_announcement'); fd.append('announcement',text);
    try {
      const res = await fetch('admin.php',{method:'POST',body:fd}); const data = await res.json();
      if (data.success) {
        showToast('Announcement posted!','success'); document.getElementById('annText').value='';
        const list=document.getElementById('annList'), item=document.createElement('div'), now=new Date();
        item.className='ann-item'; item.innerHTML=`<div class="ann-meta">CCS Admin | ${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}</div><div class="ann-text">${escapeHtml(text)}</div>`;
        const empty=list.querySelector('.ann-empty'); if(empty) empty.remove(); list.prepend(item);
      } else showToast(data.message,'error');
    } catch { showToast('Server error.','error'); }
  });

  function escapeHtml(str) { return str.replace(/[&<>]/g, function(m){ if(m==='&') return '&amp;'; if(m==='<') return '&lt;'; if(m==='>') return '&gt;'; return m;}); }

  /* MODAL HELPERS */
  function openModal(id) { document.getElementById(id)?.classList.add('open'); }
  function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }
  window.openModal  = openModal;
  window.closeModal = closeModal;
  ['closeSearchModal','closeSearchModal2'].forEach(id => document.getElementById(id)?.addEventListener('click', () => closeModal('searchModal')));
  document.getElementById('searchModal')?.addEventListener('click', e => { if(e.target.id==='searchModal') closeModal('searchModal'); });
  ['closeSitinModal','closeSitinModal2'].forEach(id => document.getElementById(id)?.addEventListener('click', () => closeModal('sitinModal')));
  document.getElementById('sitinModal')?.addEventListener('click', e => { if(e.target.id==='sitinModal') closeModal('sitinModal'); });
  ['closeEditModal','closeEditModal2'].forEach(id => document.getElementById(id)?.addEventListener('click', () => closeModal('editStudentModal')));
  document.getElementById('editStudentModal')?.addEventListener('click', e => { if(e.target.id==='editStudentModal') closeModal('editStudentModal'); });
  ['closeStudentDetailsModal','closeStudentDetailsModal2'].forEach(id => document.getElementById(id)?.addEventListener('click', () => closeModal('studentDetailsModal')));
  document.getElementById('studentDetailsModal')?.addEventListener('click', e => { if(e.target.id==='studentDetailsModal') closeModal('studentDetailsModal'); });
  ['closeAddModal','closeAddModal2'].forEach(id => document.getElementById(id)?.addEventListener('click', () => closeModal('addStudentModal')));
  document.getElementById('addStudentModal')?.addEventListener('click', e => { if(e.target.id==='addStudentModal') closeModal('addStudentModal'); });

  ['closeEditLabModal','closeEditLabModal2'].forEach(id => document.getElementById(id)?.addEventListener('click', () => closeModal('editLabModal')));
  document.getElementById('editLabModal')?.addEventListener('click', e => { if(e.target.id==='editLabModal') closeModal('editLabModal'); });

  document.getElementById('saveEditLabBtn')?.addEventListener('click', async () => {
    const id       = document.getElementById('edit_lab_id')?.value;
    const name     = document.getElementById('edit_lab_name')?.value.trim();
    const capacity = document.getElementById('edit_lab_capacity')?.value || '40';
    if (!name) { showToast('Lab name is required.','error'); return; }
    const fd = new FormData(); fd.append('_action','edit_lab'); fd.append('lab_id',id); fd.append('lab_name',name); fd.append('lab_capacity',capacity);
    try {
      const res = await fetch('admin.php',{method:'POST',body:fd}); const data = await res.json();
      if (data.success) {
        showToast(data.message,'success');
        closeModal('editLabModal');
        const row = document.querySelector(`tr[data-lab-id="${id}"]`);
        if (row) {
          row.querySelector('td:nth-child(2) strong').innerHTML = `<i class="fa-solid fa-building" style="color:var(--purple-mid);margin-right:.4rem"></i>${escapeHtml(data.name)}`;
          row.querySelector('td:nth-child(3) .badge').textContent = `${data.capacity} seats`;
          const editBtn = row.querySelector('.edit-lab-btn');
          if (editBtn) { editBtn.dataset.name = data.name; editBtn.dataset.capacity = data.capacity; }
        }
        refreshLabsOverview();
      } else showToast(data.message,'error');
    } catch { showToast('Server error.','error'); }
  });

  /* ADD STUDENT */
  document.getElementById('openAddStudentBtn')?.addEventListener('click', () => openModal('addStudentModal'));
  document.getElementById('confirmAddStudentBtn')?.addEventListener('click', async () => {
    const fd = new FormData();
    fd.append('_action','add_student');
    fd.append('id_number', document.getElementById('add_id_number')?.value.trim() || '');
    fd.append('first_name', document.getElementById('add_first_name')?.value.trim() || '');
    fd.append('last_name', document.getElementById('add_last_name')?.value.trim() || '');
    fd.append('middle_name', document.getElementById('add_middle_name')?.value.trim() || '');
    fd.append('course', document.getElementById('add_course')?.value || 'BSIT');
    fd.append('year_level', document.getElementById('add_year')?.value || '1');
    fd.append('email', document.getElementById('add_email')?.value.trim() || '');
    fd.append('address', document.getElementById('add_address')?.value.trim() || '');
    try {
      const res = await fetch('admin.php',{method:'POST',body:fd}); const data = await res.json();
      if (data.success) { showToast(data.message,'success'); closeModal('addStudentModal'); _studentsSnapshot=''; loadStudentsTable(); }
      else showToast(data.message,'error');
    } catch { showToast('Server error.','error'); }
  });

  /* ── LABS MANAGEMENT ── */
  document.getElementById('addLabBtn')?.addEventListener('click', async () => {
    const name = document.getElementById('newLabName')?.value.trim();
    const capacity = document.getElementById('newLabCapacity')?.value || '40';
    if (!name) { showToast('Enter a lab name.', 'error'); return; }
    const fd = new FormData(); fd.append('_action','add_lab'); fd.append('lab_name',name); fd.append('lab_capacity',capacity);
    try {
      const res = await fetch('admin.php',{method:'POST',body:fd}); const data = await res.json();
      if (data.success) {
        showToast(data.message,'success');
        document.getElementById('newLabName').value = '';
        document.getElementById('newLabCapacity').value = '40';
        const tbody = document.getElementById('labsTableBody');
        const empty = tbody.querySelector('td[colspan]')?.closest('tr');
        if (empty) empty.remove();
        const rows = tbody.querySelectorAll('tr').length;
        const newRow = `<tr data-lab-id="${data.id}">
          <td style="color:var(--muted);font-size:.8rem">${rows+1}</td>
          <td><strong><i class="fa-solid fa-building" style="color:var(--purple-mid);margin-right:.4rem"></i>${escapeHtml(name)}</strong></div>
          <td><span class="badge blue">${capacity} seats</span></div>
          <td><span class="badge green"><i class="fa-solid fa-circle" style="font-size:.5rem"></i> Active</span></div>
          <td><span style="font-weight:700;color:var(--purple-mid)">0</span> approved reservations</div>
           </div>
          </td>
          <td style="display:flex;gap:.4rem">
            <button class="btn-outline-sm edit-lab-btn" data-id="${data.id}" data-name="${escapeHtml(name)}" data-capacity="${capacity}"><i class="fa-solid fa-pen"></i> Edit</button>
            <button class="btn-outline-sm toggle-lab-btn" data-id="${data.id}" data-active="1"><i class="fa-solid fa-toggle-on"></i> Disable</button>
            <button class="btn-danger-sm delete-lab-btn" data-id="${data.id}" data-name="${escapeHtml(name)}"><i class="fa-solid fa-trash"></i> Delete</button>
           </div>
        </tr>`;
        tbody.insertAdjacentHTML('beforeend', newRow);
        bindLabButtons();
        const opt = `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`;
        ['sitinLabFilter','recordsLabFilter','reservationLabFilter'].forEach(id => {
          const sel = document.getElementById(id); if(sel) sel.insertAdjacentHTML('beforeend', opt);
        });
        const sfLab = document.getElementById('sf_lab'); if(sfLab) sfLab.insertAdjacentHTML('beforeend', opt);
        refreshLabsOverview();
      } else showToast(data.message,'error');
    } catch { showToast('Server error.','error'); }
  });

  function bindLabButtons() {
    document.querySelectorAll('.edit-lab-btn:not([data-bound])').forEach(btn => {
      btn.setAttribute('data-bound','1');
      btn.addEventListener('click', () => {
        document.getElementById('edit_lab_id').value    = btn.dataset.id;
        document.getElementById('edit_lab_name').value  = btn.dataset.name;
        document.getElementById('edit_lab_capacity').value = btn.dataset.capacity;
        openModal('editLabModal');
      });
    });
    document.querySelectorAll('.delete-lab-btn:not([data-bound])').forEach(btn => {
      btn.setAttribute('data-bound','1');
      btn.addEventListener('click', async () => {
        if (!confirm(`Delete lab "${btn.dataset.name}"? This cannot be undone.`)) return;
        const fd = new FormData(); fd.append('_action','delete_lab'); fd.append('lab_id',btn.dataset.id);
        try {
          const res = await fetch('admin.php',{method:'POST',body:fd}); const data = await res.json();
          if (data.success) { showToast('Lab deleted.','success'); btn.closest('tr').remove(); refreshLabsOverview(); }
          else showToast(data.message,'error');
        } catch { showToast('Server error.','error'); }
      });
    });
    document.querySelectorAll('.toggle-lab-btn:not([data-bound])').forEach(btn => {
      btn.setAttribute('data-bound','1');
      btn.addEventListener('click', async () => {
        const newActive = btn.dataset.active === '1' ? 0 : 1;
        const fd = new FormData(); fd.append('_action','toggle_lab'); fd.append('lab_id',btn.dataset.id); fd.append('is_active',newActive);
        try {
          const res = await fetch('admin.php',{method:'POST',body:fd}); const data = await res.json();
          if (data.success) {
            btn.dataset.active = newActive;
            const statusCell = btn.closest('tr').querySelector('td:nth-child(4)');
            if (newActive) {
              btn.innerHTML = '<i class="fa-solid fa-toggle-on"></i> Disable';
              if(statusCell) statusCell.innerHTML = '<span class="badge green"><i class="fa-solid fa-circle" style="font-size:.5rem"></i> Active</span>';
            } else {
              btn.innerHTML = '<i class="fa-solid fa-toggle-off"></i> Enable';
              if(statusCell) statusCell.innerHTML = '<span class="badge red"><i class="fa-solid fa-circle" style="font-size:.5rem"></i> Inactive</span>';
            }
            showToast(newActive ? 'Lab enabled.' : 'Lab disabled.','success');
            refreshLabsOverview();
          }
        } catch { showToast('Server error.','error'); }
      });
    });
  }

  /* ── Labs Overview: recalculate from live DB ── */
  async function refreshLabsOverview() {
    try {
      const fd = new FormData(); fd.append('_action','get_labs');
      const res = await fetch('admin.php',{method:'POST',body:fd});
      const data = await res.json();
      if (!data.success || !data.labs) return;
      const labs = data.labs;
      const total    = labs.length;
      const active   = labs.filter(l => parseInt(l.is_active)).length;
      const inactive = total - active;
      const capacity = labs.reduce((sum, l) => sum + parseInt(l.capacity || 0), 0);
      const animate = (el, val) => {
        if (!el) return;
        const prev = parseInt(el.textContent) || 0;
        if (prev === val) return;
        el.style.transition = 'transform .15s,opacity .15s';
        el.style.transform = 'scale(1.2)';
        el.style.opacity = '0.4';
        setTimeout(() => { el.textContent = val; el.style.transform = 'scale(1)'; el.style.opacity = '1'; }, 150);
      };
      animate(document.getElementById('ovTotalLabs'),    total);
      animate(document.getElementById('ovActiveLabs'),   active);
      animate(document.getElementById('ovInactiveLabs'), inactive);
      animate(document.getElementById('ovTotalCapacity'),capacity);
    } catch {}
  }

  bindLabButtons();

  document.getElementById('labsSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#labsTable tbody tr').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });

  /* ── ACTIVITY LOGS ── */
  async function loadActivityLogs() {
    const fd = new FormData(); fd.append('_action','get_activity_logs');
    try {
      const res = await fetch('admin.php',{method:'POST',body:fd}); const data = await res.json();
      if (!data.success) return;
      const tbody = document.getElementById('logsTableBody');
      if (!tbody) return;
      const actionColors = { RESERVATION_APPROVED:'green', RESERVATION_DENIED:'red', RESERVATION_CANCELLED:'red' };
      tbody.innerHTML = data.logs.length === 0
        ? '<tr><td colspan="5" style="text-align:center;padding:3rem;color:var(--muted)"><i class="fa-solid fa-scroll" style="font-size:2rem;color:#ddd6fe;display:block;margin-bottom:.7rem"></i>No activity logs yet.</div></tr>'
        : data.logs.map((log,i) => {
            const color = actionColors[log.action_type] || 'purple';
            const dt = new Date(log.created_at.replace(' ','T')).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'});
            return `<tr data-action-type="${log.action_type}">
              <td style="color:var(--muted);font-size:.8rem">${i+1} </div>
              <td style="white-space:nowrap;font-size:.8rem">${dt}</div>
              <td><strong>${escapeHtml(log.admin_name||'Admin')}</strong></div>
              <td><span class="badge ${color}">${log.action_type.replace(/_/g,' ')}</span></div>
              <td style="max-width:350px;white-space:normal;font-size:.83rem">${escapeHtml(log.description||'')}</div>
            </tr>`;
          }).join('');
      applyLogsFilter();
    } catch {}
  }

  function applyLogsFilter() {
    const type = document.getElementById('logsTypeFilter')?.value || 'all';
    const q = document.getElementById('logsSearch')?.value?.toLowerCase() || '';
    document.querySelectorAll('#logsTable tbody tr').forEach(row => {
      const matchType = type === 'all' || row.dataset.actionType === type;
      const matchQ = !q || row.textContent.toLowerCase().includes(q);
      row.style.display = matchType && matchQ ? '' : 'none';
    });
  }
  document.getElementById('logsTypeFilter')?.addEventListener('change', applyLogsFilter);
  document.getElementById('logsSearch')?.addEventListener('input', applyLogsFilter);
  document.getElementById('refreshLogsBtn')?.addEventListener('click', () => { showToast('Logs refreshed.','success'); loadActivityLogs(); });

  /* RESERVATION CARD ACTIONS */
  function initReservationCardActions() {
    document.querySelectorAll('.view-student-btn').forEach(btn => {
      if (btn.hasAttribute('data-bound')) return;
      btn.setAttribute('data-bound', 'true');
      btn.addEventListener('click', async (e) => {
        e.preventDefault();
        const fd = new FormData(); fd.append('_action','view_student_details'); fd.append('student_id', btn.dataset.studentId);
        try {
          const res = await fetch('admin.php',{method:'POST',body:fd}); const data = await res.json();
          if (data.success) {
            const photoHtml = data.photo ? `<img src="${data.photo}" style="width:100%;height:100%;object-fit:cover" alt="avatar"/>` : data.name.charAt(0).toUpperCase();
            const photoStyle = data.photo ? 'background:none;padding:0;overflow:hidden' : '';
            document.getElementById('studentDetailsBody').innerHTML = `<div style="display:flex;align-items:center;gap:1rem;padding:.8rem 1rem;background:linear-gradient(135deg,#faf8ff,#f5f3ff);border-radius:12px;margin-bottom:1rem"><div style="width:58px;height:58px;border-radius:13px;background:linear-gradient(135deg,var(--purple-mid),var(--purple-light));color:#fff;font-family:var(--ff);font-size:1.3rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;${photoStyle}">${photoHtml}</div><div style="flex:1"><div style="font-family:var(--ff);font-size:.92rem;font-weight:800;color:var(--text)">${data.name}</div><div style="font-size:.79rem;color:var(--purple-mid);font-weight:600;margin-top:.1rem">${data.course} — ${data.year}</div><div style="font-size:.74rem;color:var(--muted);margin-top:.1rem">ID: ${data.id_number}</div></div><div style="text-align:center"><div style="font-family:var(--ff);font-size:1.45rem;font-weight:800;color:var(--purple-mid)">${data.remaining_session}</div><div style="font-size:.69rem;color:var(--muted)">Sessions Left</div></div></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#f0ecff;border-radius:10px;overflow:hidden"><div style="background:#fff;padding:.75rem 1rem"><div style="font-size:.69rem;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:.2rem">Course</div><div style="font-size:.84rem;font-weight:600">${data.course}</div></div><div style="background:#fff;padding:.75rem 1rem"><div style="font-size:.69rem;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:.2rem">Year Level</div><div style="font-size:.84rem;font-weight:600">${data.year}</div></div><div style="background:#fff;padding:.75rem 1rem;grid-column:1/-1"><div style="font-size:.69rem;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:.2rem">Email</div><div style="font-size:.84rem;font-weight:600">${data.email}</div></div><div style="background:#fff;padding:.75rem 1rem;grid-column:1/-1"><div style="font-size:.69rem;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:.2rem">Sessions Used</div><div style="font-size:.84rem;font-weight:600">${data.used_sessions}</div></div></div>`;
            openModal('studentDetailsModal');
          } else showToast(data.message,'error');
        } catch { showToast('Server error.','error'); }
      });
    });
    document.querySelectorAll('.approve-rsv-btn').forEach(btn => {
      if (btn.hasAttribute('data-bound')) return;
      btn.setAttribute('data-bound', 'true');
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const rsvId   = btn.dataset.id;
        const student = btn.dataset.student || 'Student';
        const lab     = btn.dataset.lab     || '';
        const date    = btn.dataset.date    || '';
        const time    = btn.dataset.time    || '';
        const purpose = btn.dataset.purpose || '';
        const pc      = btn.dataset.pc      || '';
        if (typeof window.openSeatmapApproval === 'function') {
          window.openSeatmapApproval(rsvId, student, lab, date, time, purpose, pc);
        }
      });
    });
    document.querySelectorAll('.reject-rsv-btn').forEach(btn => {
      if (btn.hasAttribute('data-bound')) return;
      btn.setAttribute('data-bound', 'true');
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const rsvId   = btn.dataset.id;
        const student = btn.dataset.student || 'Student';
        const lab     = btn.dataset.lab     || '';
        const date    = btn.dataset.date    || '';
        const time    = btn.dataset.time    || '';
        const purpose = btn.dataset.purpose || '';
        const pc      = btn.dataset.pc      || '';
        if (typeof window.openSeatmapApproval === 'function') {
          window.openSeatmapApproval(rsvId, student, lab, date, time, purpose, pc);
          setTimeout(() => document.getElementById('seatmapRejectBtn')?.click(), 200);
        }
      });
    });
  }
  window.initReservationCardActions = initReservationCardActions;
  initReservationCardActions();

  /* SEARCH STUDENT */
  let currentSearchData = null;
  async function runSearch(idNum) {
    if (!idNum) { showToast('Please enter an ID number.','error'); return; }
    const fd = new FormData(); fd.append('_action','search_student'); fd.append('id_number',idNum);
    try {
      const res = await fetch('admin.php',{method:'POST',body:fd}); const data = await res.json();
      if (!data.success) { showToast(data.message,'error'); return; }
      currentSearchData = data;
      const photoHtml = data.photo ? `<img src="${data.photo}" style="width:100%;height:100%;object-fit:cover" alt="avatar"/>` : data.first_name.charAt(0).toUpperCase();
      const photoStyle = data.photo ? 'background:none;padding:0;overflow:hidden' : '';
      document.getElementById('searchModalBody').innerHTML = `<div style="display:flex;align-items:center;gap:1rem;padding:.8rem 1rem;background:linear-gradient(135deg,#faf8ff,#f5f3ff);border-radius:12px;margin-bottom:1rem"><div style="width:58px;height:58px;border-radius:13px;background:linear-gradient(135deg,var(--purple-mid),var(--purple-light));color:#fff;font-family:var(--ff);font-size:1.3rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;${photoStyle}">${photoHtml}</div><div style="flex:1"><div style="font-family:var(--ff);font-size:.92rem;font-weight:800;color:var(--text)">${data.name}</div><div style="font-size:.79rem;color:var(--purple-mid);font-weight:600;margin-top:.1rem">${data.course} — ${data.year}</div><div style="font-size:.74rem;color:var(--muted);margin-top:.1rem">ID: ${data.id_number}</div></div><div style="text-align:center"><div style="font-family:var(--ff);font-size:1.45rem;font-weight:800;color:var(--purple-mid)">${data.sessions}</div><div style="font-size:.69rem;color:var(--muted)">Sessions Left</div></div></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#f0ecff;border-radius:10px;overflow:hidden"><div style="background:#fff;padding:.75rem 1rem"><div style="font-size:.69rem;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:.2rem">Course</div><div style="font-size:.84rem;font-weight:600">${data.course}</div></div><div style="background:#fff;padding:.75rem 1rem"><div style="font-size:.69rem;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:.2rem">Year Level</div><div style="font-size:.84rem;font-weight:600">${data.year}</div></div><div style="background:#fff;padding:.75rem 1rem;grid-column:1/-1"><div style="font-size:.69rem;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:.2rem">Email</div><div style="font-size:.84rem;font-weight:600">${data.email}</div></div><div style="background:#fff;padding:.75rem 1rem;grid-column:1/-1"><div style="font-size:.69rem;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:.2rem">Sessions Used</div><div style="font-size:.84rem;font-weight:600">${data.used}</div></div></div>`;
      openModal('searchModal');
    } catch { showToast('Server error.','error'); }
  }
  document.getElementById('doSearchBtn')?.addEventListener('click', () => runSearch(document.getElementById('searchInput')?.value.trim()));
  document.getElementById('searchInput')?.addEventListener('keydown', e => { if(e.key==='Enter') runSearch(document.getElementById('searchInput').value.trim()); });
  document.getElementById('sitInFromSearchBtn')?.addEventListener('click', () => {
    if (!currentSearchData) return;
    document.getElementById('sf_db_id').value = currentSearchData.student_db_id;
    document.getElementById('sf_id').value = currentSearchData.id_number;
    document.getElementById('sf_name').value = currentSearchData.name;
    document.getElementById('sf_sessions').value = currentSearchData.sessions;
    closeModal('searchModal'); openModal('sitinModal');
  });
  document.getElementById('confirmSitinBtn')?.addEventListener('click', async () => {
    const purpose = document.getElementById('sf_purpose')?.value, lab = document.getElementById('sf_lab')?.value, db_id = document.getElementById('sf_db_id')?.value;
    const pc_number = document.getElementById('sf_pc_number')?.value || '';
    if (!purpose||!lab) { showToast('Please select Purpose and Lab.','error'); return; }
    try {
      const chkFd = new FormData(); chkFd.append('_action','check_active_session'); chkFd.append('student_id', db_id);
      const chkRes = await fetch('admin.php',{method:'POST',body:chkFd}); const chkData = await chkRes.json();
      if (chkData.success && chkData.has_active_sitin) { showToast('Student already has an active sit-in session.','error'); return; }
      if (chkData.success && chkData.has_active_rsv) { showToast('Student already has a pending/approved reservation.','error'); return; }
    } catch {}
    const fd = new FormData(); fd.append('_action','log_sitin'); fd.append('student_db_id',db_id); fd.append('purpose',purpose); fd.append('lab',lab);
    if (pc_number) fd.append('pc_number', pc_number);
    try {
      const res = await fetch('admin.php',{method:'POST',body:fd}); const data = await res.json();
      if (data.success) {
        showToast('Sit-in logged!','success'); closeModal('sitinModal');
        document.getElementById('sf_purpose').value=''; document.getElementById('sf_lab').value='';
        document.getElementById('sf_pc_number').innerHTML='<option value="">— Select PC —</option>';
        document.getElementById('sf_pc_wrap').style.display='none';
        if(document.getElementById('sitinSearchInput')) document.getElementById('sitinSearchInput').value='';
        switchPage('sitin');
        const idNum = document.getElementById('sf_id').value;
        const newSessions = parseInt(document.getElementById('sf_sessions').value||'0')-1;
        document.querySelectorAll('#studentsTable tbody tr').forEach(row => { const code=row.querySelector('code'); if(code&&code.textContent.trim()===idNum){ const cells=row.querySelectorAll('td'); if(cells[4])cells[4].textContent=newSessions; } });
        triggerSitinRefresh();
      } else showToast(data.message,'error');
    } catch { showToast('Server error.','error'); }
  });

  /* Load PC seats when lab changes in sit-in modal */
  document.getElementById('sf_lab')?.addEventListener('change', async function() {
    const lab = this.value;
    const wrap = document.getElementById('sf_pc_wrap');
    const sel  = document.getElementById('sf_pc_number');
    const loading = document.getElementById('sf_pc_loading');
    sel.innerHTML = '<option value="">— Select PC —</option>';
    if (!lab) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';
    loading.style.display = 'block';
    sel.disabled = true;
    try {
      const fd = new FormData(); fd.append('_action','get_lab_seats'); fd.append('lab_name', lab);
      const res  = await fetch('admin.php', {method:'POST', body:fd});
      const data = await res.json();
      loading.style.display = 'none';
      sel.disabled = false;
      if (!data.success) { sel.innerHTML = '<option value="">— Could not load seats —</option>'; return; }
      const seats = data.seats || [];
      seats.forEach(s => {
        const pn   = s.pc_number;
        const label= s.label || ('PC-' + String(pn).padStart(2,'0'));
        const status = s.status; // 'available','in_use','reserved','unavailable'
        const opt  = document.createElement('option');
        opt.value  = pn;
        if (status === 'available') {
          opt.textContent = label + ' — Available';
        } else if (status === 'in_use') {
          opt.textContent = label + ' — In Use';
          opt.disabled = true;
          opt.style.color = '#ef4444';
        } else if (status === 'reserved') {
          opt.textContent = label + ' — Reserved';
          opt.disabled = true;
          opt.style.color = '#f59e0b';
        } else {
          opt.textContent = label + ' — Unavailable';
          opt.disabled = true;
          opt.style.color = '#9ca3af';
        }
        sel.appendChild(opt);
      });
      const availCount = seats.filter(s => s.status === 'available').length;
      sel.insertAdjacentHTML('afterbegin', `<option value="">— Select PC (${availCount} available) —</option>`);
      sel.selectedIndex = 0;
    } catch(e) {
      loading.style.display = 'none';
      sel.disabled = false;
      sel.innerHTML = '<option value="">— Error loading seats —</option>';
    }
  });

  /* RESET ALL SESSIONS */
  document.getElementById('resetAllSessionsBtn')?.addEventListener('click', async () => {
    if (!confirm('Reset all student sessions to 30?')) return;
    const fd = new FormData(); fd.append('_action','reset_sessions');
    try { const res=await fetch('admin.php',{method:'POST',body:fd}); const data=await res.json(); if(data.success){showToast('All sessions reset to 30!','success'); _studentsSnapshot=''; loadStudentsTable();} else showToast(data.message,'error'); } catch { showToast('Server error.','error'); }
  });

  /* RESET SITIN RECORDS */
  document.getElementById('openResetRecordsBtn')?.addEventListener('click', () => {
    document.getElementById('resetConfirmInput').value = '';
    document.getElementById('resetRecordsError').textContent = '';
    openModal('resetRecordsModal');
  });
  document.getElementById('confirmResetRecordsBtn')?.addEventListener('click', async () => {
    const val = document.getElementById('resetConfirmInput').value.trim();
    const errEl = document.getElementById('resetRecordsError');
    if (val !== 'RESET') { errEl.textContent = 'Type RESET exactly to confirm.'; return; }
    const btn = document.getElementById('confirmResetRecordsBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Clearing…';
    const fd = new FormData(); fd.append('_action','reset_sitin_records'); fd.append('confirm','RESET');
    try {
      const res = await fetch('admin.php',{method:'POST',body:fd});
      const data = await res.json();
      if (data.success) {
        closeModal('resetRecordsModal');
        showToast('All sit-in records cleared!', 'success');
      } else {
        errEl.textContent = data.message || 'Something went wrong.';
      }
    } catch { errEl.textContent = 'Server error. Try again.'; }
    btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Yes, Reset Everything';
  });

  /* EDIT STUDENT */
  attachStudentRowListeners();
  // attachStudentRowListeners is defined globally below loadStudentsTable

  document.getElementById('saveEditBtn')?.addEventListener('click', async () => {
    const fd = new FormData(); fd.append('_action','edit_student'); fd.append('student_id',document.getElementById('edit_student_id').value); fd.append('first_name',document.getElementById('edit_first').value.trim()); fd.append('last_name',document.getElementById('edit_last').value.trim()); fd.append('course',document.getElementById('edit_course').value); fd.append('year_level',document.getElementById('edit_year').value); fd.append('remaining_session',document.getElementById('edit_sessions').value);
    try {
      const res=await fetch('admin.php',{method:'POST',body:fd});
      const data=await res.json();
      if(data.success){ showToast('Student updated!','success'); closeModal('editStudentModal'); _studentsSnapshot=''; loadStudentsTable(); }
      else showToast(data.message,'error');
    } catch { showToast('Server error.','error'); }
  });

  /* FILTERS */
  /* ── RESERVATION STATUS TABS + FILTER ── */
  let activeStatusTab = 'current'; // default tab

  // Tab mapping: which card statuses each tab shows
  const TAB_STATUSES = {
    upcoming:  ['pending'],
    current:   ['approved'],
    completed: ['done'],
    denied:    ['cancelled'],
  };

  function updateTabCounts() {
    const cards = document.querySelectorAll('.reservation-card');
    const counts = { pending:0, approved:0, done:0, cancelled:0 };
    cards.forEach(c => { if (counts[c.dataset.status] !== undefined) counts[c.dataset.status]++; });
    const el = (id, n) => { const el = document.getElementById(id); if (el) el.textContent = n > 0 ? n : ''; };
    el('countUpcoming',  counts.pending);
    el('countCurrent',   counts.approved);
    el('countCompleted', counts.done);
    el('countDenied',    counts.cancelled);
  }

  function applyReservationFilters() {
    const allowed = TAB_STATUSES[activeStatusTab] || [];
    const lab = document.getElementById('reservationLabFilter')?.value || 'all';
    const q   = (document.getElementById('reservationSearch')?.value || '').toLowerCase();
    document.querySelectorAll('.reservation-card').forEach(card => {
      const matchTab = allowed.includes(card.dataset.status);
      const matchLab = lab === 'all' || (card.dataset.lab||'') === lab;
      const matchQ   = !q   || card.textContent.toLowerCase().includes(q);
      card.style.display = (matchTab && matchLab && matchQ) ? '' : 'none';
    });
    updateTabCounts();
    // Show empty state if no cards visible
    const grid = document.getElementById('reservationCardsGrid');
    if (!grid) return;
    const anyVisible = Array.from(grid.querySelectorAll('.reservation-card')).some(c => c.style.display !== 'none');
    let emptyMsg = grid.querySelector('.rsv-empty-tab');
    if (!anyVisible) {
      if (!emptyMsg) {
        emptyMsg = document.createElement('div');
        emptyMsg.className = 'rsv-empty-tab';
        emptyMsg.style.cssText = 'grid-column:1/-1;text-align:center;padding:3rem;color:var(--muted)';
        const labels = {upcoming:'No upcoming reservations.',current:'No currently active reservations.',completed:'No completed reservations.',denied:'No denied reservations.'};
        const icons  = {upcoming:'fa-calendar-check',current:'fa-circle-dot',completed:'fa-circle-check',denied:'fa-ban'};
        emptyMsg.innerHTML = `<i class="fa-solid ${icons[activeStatusTab]||'fa-calendar'}" style="font-size:2.5rem;color:#ddd6fe;display:block;margin-bottom:.75rem"></i><div style="font-weight:700;font-size:.9rem">${labels[activeStatusTab]||'Nothing here.'}</div>`;
        grid.appendChild(emptyMsg);
      }
    } else {
      emptyMsg?.remove();
    }
  }

  // Tab click handler
  document.querySelectorAll('.rsv-status-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      activeStatusTab = btn.dataset.statusTab;
      document.querySelectorAll('.rsv-status-tab').forEach(b => {
        const isActive = b === btn;
        b.classList.toggle('active', isActive);
        b.style.background = isActive ? '#6c3fcf' : '#fff';
        b.style.color       = isActive ? '#fff'    : '#6b7280';
        b.style.borderColor = isActive ? '#6c3fcf' : '#e5e7eb';
        const count = b.querySelector('.rsv-status-count');
        if (count) count.style.background = isActive ? 'rgba(255,255,255,0.25)' : '#f3f4f6';
      });
      applyReservationFilters();
    });
  });

  document.getElementById('reservationLabFilter')?.addEventListener('change', applyReservationFilters);
  const searchInput = document.getElementById('reservationSearch');
  if (searchInput) searchInput.addEventListener('input', applyReservationFilters);

  // Run on load
  applyReservationFilters();
  const ratingFilter = document.getElementById('feedbackRatingFilter');
  if (ratingFilter) ratingFilter.addEventListener('change', () => { const filter = ratingFilter.value; document.querySelectorAll('#feedbackTable tbody tr').forEach(row => { row.style.display = (filter === 'all' || row.dataset.rating === filter) ? '' : 'none'; }); updatePagination('feedback'); });

  /* ── UNIVERSAL PAGINATION ENGINE ── */
  const PAGE_SIZE = 10;
  const paginationState = {};

  function initPagination(key) {
    paginationState[key] = { page: 1 };
  }

  function getVisibleRows(tableId) {
    return [...document.querySelectorAll(`#${tableId} tbody tr`)].filter(r => {
      const col = r.querySelector('td[colspan]');
      return !col && r.getAttribute('data-hidden') !== 'filtered';
    });
  }

  function updatePagination(key) {
    const cfg = {
      students: { table: 'studentsTable', prev: 'studentsPrevBtn', next: 'studentsNextBtn', info: 'studentsPaginationInfo' },
      sitin:    { table: 'sitinTable',    prev: 'sitinPrevBtn',    next: 'sitinNextBtn',    info: 'sitinPaginationInfo' },
      records:  { table: 'recordsTable',  prev: 'recordsPrevBtn',  next: 'recordsNextBtn',  info: 'recordsPaginationInfo' },
      feedback: { table: 'feedbackTable', prev: 'feedbackPrevBtn', next: 'feedbackNextBtn', info: 'feedbackPaginationInfo' },
    }[key];
    if (!cfg) return;
    if (!paginationState[key]) paginationState[key] = { page: 1 };

    // First unhide all filtered rows to get correct count, then re-apply
    const allRows = [...document.querySelectorAll(`#${cfg.table} tbody tr`)].filter(r => !r.querySelector('td[colspan]'));
    const visibleRows = allRows.filter(r => r.getAttribute('data-hidden') !== 'filtered');
    const total = visibleRows.length;
    const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    if (paginationState[key].page > totalPages) paginationState[key].page = 1;
    const page = paginationState[key].page;
    const start = (page - 1) * PAGE_SIZE;
    const end = Math.min(start + PAGE_SIZE, total);

    let visIdx = 0;
    allRows.forEach(row => {
      if (row.getAttribute('data-hidden') === 'filtered') {
        row.style.display = 'none';
        return;
      }
      row.style.display = (visIdx >= start && visIdx < end) ? '' : 'none';
      visIdx++;
    });

    const infoEl = document.getElementById(cfg.info);
    if (infoEl) {
      infoEl.innerHTML = total === 0
        ? 'No results'
        : `Showing <strong>${start + 1}–${end}</strong> of <strong>${total}</strong> results`;
    }
    const prevBtn = document.getElementById(cfg.prev);
    const nextBtn = document.getElementById(cfg.next);
    if (prevBtn) prevBtn.disabled = page <= 1;
    if (nextBtn) nextBtn.disabled = page >= totalPages;
  }

  function applyFilterAndPaginate(key, filterFn) {
    const tableIds = {
      students: 'studentsTable', sitin: 'sitinTable',
      records: 'recordsTable', feedback: 'feedbackTable'
    };
    const tableId = tableIds[key];
    if (!tableId) return;
    document.querySelectorAll(`#${tableId} tbody tr`).forEach(row => {
      if (row.querySelector('td[colspan]')) return;
      row.setAttribute('data-hidden', filterFn(row) ? '' : 'filtered');
    });
    if (!paginationState[key]) paginationState[key] = { page: 1 };
    paginationState[key].page = 1;
    updatePagination(key);
  }
  window.updatePagination      = updatePagination;
  window.applyFilterAndPaginate = applyFilterAndPaginate;

  // Init all
  ['students','sitin','records','feedback'].forEach(k => {
    initPagination(k);
    updatePagination(k);
    const prevId = { students:'studentsPrevBtn', sitin:'sitinPrevBtn', records:'recordsPrevBtn', feedback:'feedbackPrevBtn' }[k];
    const nextId = { students:'studentsNextBtn', sitin:'sitinNextBtn', records:'recordsNextBtn', feedback:'feedbackNextBtn' }[k];
    document.getElementById(prevId)?.addEventListener('click', () => {
      if (paginationState[k].page > 1) { paginationState[k].page--; updatePagination(k); }
    });
    document.getElementById(nextId)?.addEventListener('click', () => {
      const total = getVisibleRows({ students:'studentsTable', sitin:'sitinTable', records:'recordsTable', feedback:'feedbackTable' }[k]).length;
      if (paginationState[k].page < Math.ceil(total / PAGE_SIZE)) { paginationState[k].page++; updatePagination(k); }
    });
  });

  /* TABLE SEARCH */
  document.getElementById('studentsSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    applyFilterAndPaginate('students', row => row.textContent.toLowerCase().includes(q));
  });
  document.getElementById('sitinSearchFilter')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    const lab = document.getElementById('sitinLabFilter')?.value || 'all';
    applyFilterAndPaginate('sitin', row => {
      const rowLab = row.querySelector('.badge.purple')?.textContent?.trim() || '';
      return (lab === 'all' || rowLab === lab) && row.textContent.toLowerCase().includes(q);
    });
  });
  /* ══════════════════════════════════════════════
     REAL-TIME FEEDBACK ENGINE
     Polls every 8 seconds; also fires on filter/search change.
     Handles its own pagination independently.
  ══════════════════════════════════════════════ */
  (function() {
    const POLL_MS     = 8000;
    const PER_PAGE    = 10;
    let   fbData      = [];   // full result set from server
    let   fbPage      = 1;
    let   fbPollTimer = null;
    let   fbLastId    = 0;    // track newest feedback id for "new" highlight

    const tbody   = document.getElementById('feedbackTbody');
    const wrap    = document.getElementById('fbTableWrap');
    const loading = document.getElementById('fbLoadingRow');
    const empty   = document.getElementById('fbEmptyState');
    const pgInfo  = document.getElementById('feedbackPaginationInfo');
    const pgWrap  = document.getElementById('feedbackPagination');
    const prevBtn = document.getElementById('feedbackPrevBtn');
    const nextBtn = document.getElementById('feedbackNextBtn');

    function esc(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

    function fmtDate(ts) {
      if (!ts) return '—';
      const d = new Date(ts.replace(' ','T'));
      return d.toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'});
    }

    function stars(r) {
      return '<span class="stars-display" style="color:#f59e0b">'+
        '★'.repeat(r)+'<span style="color:#d1d5db">'+'☆'.repeat(5-r)+'</span></span>';
    }

    function renderPage() {
      if (!tbody) return;
      if (fbData.length === 0) {
        wrap.style.display  = 'none';
        pgWrap.style.display= 'none';
        empty.style.display = 'block';
        document.getElementById('fbStatTotal').textContent    = '0';
        document.getElementById('fbStatAvg').textContent      = '—';
        document.getElementById('fbStatPositive').textContent = '0';
        document.getElementById('fbStatNegative').textContent = '0';
        return;
      }
      empty.style.display  = 'none';
      wrap.style.display   = '';
      pgWrap.style.display = '';

      const pages  = Math.ceil(fbData.length / PER_PAGE);
      if (fbPage > pages) fbPage = pages;
      const start  = (fbPage - 1) * PER_PAGE;
      const slice  = fbData.slice(start, start + PER_PAGE);

      tbody.innerHTML = slice.map((f, i) => {
        const isNew = fbLastId && parseInt(f.id) > fbLastId;
        const rowStyle = isNew ? 'background:linear-gradient(90deg,#f0fdf4,#fff);animation:fbNewRow .8s ease' : '';
        return `<tr data-rating="${esc(f.rating)}" style="${rowStyle}">
          <td style="color:var(--muted);font-size:.8rem">${start + i + 1}</td>
          <td style="white-space:nowrap">${fmtDate(f.created_at)}</td>
          <td><code style="font-size:.82rem;background:#f3f0ff;color:var(--purple-mid);padding:.15rem .45rem;border-radius:5px">${esc(f.student_id_number)}</code></td>
          <td><strong>${esc(f.first_name + ' ' + f.last_name)}</strong></td>
          <td><span class="badge purple">${esc(f.course)}</span></td>
          <td><span class="badge blue">${esc(f.lab || '—')}</span></td>
          <td style="max-width:200px;white-space:normal;font-size:.82rem">${esc(f.purpose || '—')}</td>
          <td>${stars(parseInt(f.rating))}</td>
          <td style="max-width:250px;white-space:normal;font-size:.8rem;color:#4b5563">${esc(f.comment || '—')}</td>
        </tr>`;
      }).join('');

      // Pagination controls
      pgInfo.textContent = `Showing ${start+1}–${Math.min(start+PER_PAGE, fbData.length)} of ${fbData.length}`;
      prevBtn.disabled = fbPage <= 1;
      nextBtn.disabled = fbPage >= pages;
    }

    function updateStats(stats) {
      if (!stats) return;
      document.getElementById('fbStatTotal').textContent    = stats.total || 0;
      document.getElementById('fbStatAvg').textContent      = stats.avg_rating ? stats.avg_rating + ' ★' : '—';
      document.getElementById('fbStatPositive').textContent = stats.positive || 0;
      document.getElementById('fbStatNegative').textContent = stats.negative || 0;
    }

    async function loadFeedback(isFirstLoad) {
      if (isFirstLoad) { loading.style.display='block'; wrap.style.display='none'; empty.style.display='none'; }
      try {
        const fd = new FormData();
        fd.append('_action', 'fetch_feedback');
        fd.append('rating',  document.getElementById('feedbackRatingFilter')?.value || 'all');
        fd.append('search',  document.getElementById('feedbackSearch')?.value || '');
        const res  = await fetch('admin.php', {method:'POST', body:fd});
        const data = await res.json();
        loading.style.display = 'none';
        if (!data.success) return;

        // Detect new rows for highlight
        const newMax = data.feedback.length ? Math.max(...data.feedback.map(f=>parseInt(f.id))) : 0;
        if (!isFirstLoad && newMax > fbLastId && fbLastId > 0) {
          showToast('New feedback received!', 'success');
        }
        fbLastId = newMax;
        fbData   = data.feedback;
        updateStats(data.stats);
        renderPage();
      } catch(e) {
        loading.style.display = 'none';
      }
    }

    function startPolling() {
      if (fbPollTimer) clearInterval(fbPollTimer);
      fbPollTimer = setInterval(() => {
        if (document.getElementById('page-feedback')?.classList.contains('active')) {
          loadFeedback(false);
        }
      }, POLL_MS);
    }

    // Expose so adminRefreshRegistry can trigger it
    window._loadFeedback = function() { loadFeedback(!fbData.length); };

    // Pagination button handlers
    prevBtn?.addEventListener('click', () => { if (fbPage > 1) { fbPage--; renderPage(); } });
    nextBtn?.addEventListener('click', () => {
      if (fbPage < Math.ceil(fbData.length / PER_PAGE)) { fbPage++; renderPage(); }
    });

    // Filter/search triggers immediate reload + reset to page 1
    function onFilterChange() { fbPage = 1; loadFeedback(false); }
    document.getElementById('feedbackSearch')?.addEventListener('input', onFilterChange);
    document.getElementById('feedbackRatingFilter')?.addEventListener('change', onFilterChange);

    // Initial load if already on feedback page
    if (document.getElementById('page-feedback')?.classList.contains('active')) {
      loadFeedback(true);
    }

    // Inject keyframe for new-row highlight
    if (!document.getElementById('fbNewRowStyle')) {
      const s = document.createElement('style');
      s.id = 'fbNewRowStyle';
      s.textContent = '@keyframes fbNewRow{0%{background:linear-gradient(90deg,#bbf7d0,#fff)}100%{background:transparent}}';
      document.head.appendChild(s);
    }

    startPolling();
  })();

  /* SITIN LAB FILTER */
  document.getElementById('sitinLabFilter')?.addEventListener('change', function() {
    const lab = this.value;
    const q = document.getElementById('sitinSearchFilter')?.value?.toLowerCase() || '';
    applyFilterAndPaginate('sitin', row => {
      const rowLab = row.querySelector('.badge.purple')?.textContent?.trim() || '';
      return (lab === 'all' || rowLab === lab) && (!q || row.textContent.toLowerCase().includes(q));
    });
  });

  /* RECORDS LAB + STATUS FILTER */
  function applyRecordsFilter() {
    const lab = document.getElementById('recordsLabFilter')?.value || 'all';
    const status = document.getElementById('recordsStatusFilter')?.value || 'all';
    const q = document.getElementById('recordsSearch')?.value?.toLowerCase() || '';
    applyFilterAndPaginate('records', row => {
      const matchLab = lab === 'all' || (row.dataset.lab || '') === lab;
      const matchStatus = status === 'all' || (row.dataset.status || '') === status;
      const matchQ = !q || row.textContent.toLowerCase().includes(q);
      return matchLab && matchStatus && matchQ;
    });
  }
  document.getElementById('recordsLabFilter')?.addEventListener('change', applyRecordsFilter);
  document.getElementById('recordsStatusFilter')?.addEventListener('change', applyRecordsFilter);
  document.getElementById('recordsSearch')?.addEventListener('input', applyRecordsFilter);

  /* ANALYTICS CHARTS */
  let dailyChart=null, hourlyChart=null, courseDistChart=null, yearDistChart=null, labChart=null;
  function loadAnalyticsCharts() {
    const dailyData = <?= json_encode($analytics['daily_trends'] ?? []) ?>;
    const hourlyData = <?= json_encode($analytics['hourly_distribution'] ?? []) ?>;
    const courseDistData = <?= json_encode($analytics['course_distribution'] ?? []) ?>;
    const yearDistData = <?= json_encode($analytics['year_distribution'] ?? []) ?>;

    const allLabsData = <?php
      $labChartData = [];
      foreach($all_labs as $lb) {
        $cnt = 0;
        try { $s=$pdo->prepare("SELECT COUNT(*) FROM sitins WHERE lab=? AND status='done'"); $s->execute([$lb['name']]); $cnt=(int)$s->fetchColumn(); } catch(Exception $e){}
        $labChartData[] = ['lab' => $lb['name'], 'count' => $cnt, 'active' => (bool)$lb['is_active']];
      }
      echo json_encode($labChartData);
    ?>;

    if (allLabsData.length && document.getElementById('labUtilChart')) {
      if (labChart) labChart.destroy();
      const labLabels = allLabsData.map(l => l.lab);
      const labValues = allLabsData.map(l => l.count);
      const labColors = allLabsData.map(l => l.active ? '#6c3fcf' : '#d1d5db');
      labChart = new Chart(document.getElementById('labUtilChart'), {
        type:'bar',
        data:{ labels:labLabels, datasets:[{ label:'Completed Sit-ins', data:labValues, backgroundColor:labColors, borderRadius:6 }] },
        options:{ responsive:true, maintainAspectRatio:true, plugins:{ legend:{display:false} }, scales:{ y:{ beginAtZero:true, ticks:{stepSize:1} } } }
      });
    }

    if (dailyData.length && document.getElementById('dailyTrendChart')) {
      if (dailyChart) dailyChart.destroy();
      const labels = dailyData.map(d => new Date(d.date).toLocaleDateString('en-US',{month:'short',day:'numeric'}));
      const values = dailyData.map(d => d.count);
      dailyChart = new Chart(document.getElementById('dailyTrendChart'), { type:'line', data:{ labels, datasets:[{ label:'Sit-ins', data:values, borderColor:'#6c3fcf', backgroundColor:'rgba(108,63,207,0.1)', fill:true, tension:0.3 }] }, options:{ responsive:true, maintainAspectRatio:true, plugins:{ legend:{ position:'top' } } } });
    }
    if (hourlyData.length && document.getElementById('hourlyChart')) {
      if (hourlyChart) hourlyChart.destroy();
      const labels = hourlyData.map(h => `${h.hour}:00`);
      const values = hourlyData.map(h => h.count);
      hourlyChart = new Chart(document.getElementById('hourlyChart'), { type:'bar', data:{ labels, datasets:[{ label:'Sit-ins', data:values, backgroundColor:'#a259f7', borderRadius:6 }] }, options:{ responsive:true, maintainAspectRatio:true, plugins:{ legend:{ display:false } } } });
    }
    if (courseDistData.length && document.getElementById('courseDistChart')) {
      if (courseDistChart) courseDistChart.destroy();
      const labels = courseDistData.map(c => c.course);
      const values = courseDistData.map(c => c.count);
      const bgColors = ['#6c3fcf','#a259f7','#f5c518','#22c55e','#ef4444','#3b82f6'];
      courseDistChart = new Chart(document.getElementById('courseDistChart'), { type:'pie', data:{ labels, datasets:[{ data:values, backgroundColor:bgColors.slice(0,labels.length), borderWidth:0 }] }, options:{ responsive:true, maintainAspectRatio:true, plugins:{ legend:{ position:'right', labels:{ font:{ size:10 } } } } } });
    }
    if (yearDistData.length && document.getElementById('yearDistChart')) {
      if (yearDistChart) yearDistChart.destroy();
      const labels = yearDistData.map(y => { const suffix = y.year_level===1?'st':y.year_level===2?'nd':y.year_level===3?'rd':'th'; return `${y.year_level}${suffix} Year`; });
      const values = yearDistData.map(y => y.count);
      const bgColors = ['#22c55e','#f5c518','#f97316','#ef4444'];
      yearDistChart = new Chart(document.getElementById('yearDistChart'), { type:'doughnut', data:{ labels, datasets:[{ data:values, backgroundColor:bgColors.slice(0,labels.length), borderWidth:0 }] }, options:{ responsive:true, maintainAspectRatio:true, plugins:{ legend:{ position:'right', labels:{ font:{ size:10 } } } } } });
    }
  }

  /* TOAST */
  function showToast(msg, type='success') {
    document.querySelector('.toast')?.remove();
    const titles = { success:'Success', error:'Error', info:'Info' };
    const icons  = { success:'fa-circle-check', error:'fa-circle-xmark', info:'fa-circle-info' };
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = `
      <i class="fa-solid ${icons[type]||icons.info} lt-icon"></i>
      <div class="lt-body">
        <div class="lt-title">${titles[type]||'Notice'}</div>
        <div class="lt-msg">${msg}</div>
      </div>
      <button class="lt-close" aria-label="Dismiss"><i class="fa-solid fa-xmark"></i></button>`;
    document.body.appendChild(t);
    t.querySelector('.lt-close').addEventListener('click', () => {
      t.classList.remove('show');
      setTimeout(() => t.remove(), 300);
    });
    requestAnimationFrame(()=>requestAnimationFrame(()=>t.classList.add('show')));
    setTimeout(()=>{t.classList.remove('show');setTimeout(()=>t.remove(),300);},3500);
  }
  window.showToast = showToast;

  /* LIVE POLLING */
  (function startPolling() {
    const INTERVAL = 5000;
    let isPolling = false;
    function renderSitinRow(sit) { return `<tr data-sitin-id="${sit.id}"><td><code style="font-size:.82rem;background:#f3f0ff;color:var(--purple-mid);padding:.15rem .45rem;border-radius:5px">${sit.id}</code></div><td>${sit.student_id_number}</div><td><strong>${sit.first_name} ${sit.last_name}</strong></div><td>${sit.purpose??'—'}</div><td><span class="badge purple">${sit.lab??'—'}</span></div><td>${sit.remaining_session??'—'}</div><td><span class="badge green"><i class="fa-solid fa-circle"></i> Active</span></div><td><button class="btn-end end-sitin-btn" data-id="${sit.id}"><i class="fa-solid fa-right-from-bracket"></i> End</button></div></tr>`; }
    function renderRecordRow(r,i) { const sc=r.status==='active'?'green':r.status==='done'?'purple':'red'; const ti=r.time_in?new Date(r.time_in.replace(' ','T')).toLocaleString('en-US',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}):'—'; const to=r.time_out?new Date(r.time_out.replace(' ','T')).toLocaleString('en-US',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}):'—'; return `<tr data-lab="${r.lab??''}" data-status="${r.status??''}"><td style="color:var(--muted);font-size:.8rem">${i+1}</div><td><code style="font-size:.82rem;background:#f3f0ff;color:var(--purple-mid);padding:.15rem .45rem;border-radius:5px">${r.student_id_number}</code></div><td><strong>${r.first_name} ${r.last_name}</strong></div><td>${r.purpose??'—'}</div><td><span class="badge purple">${r.lab??'—'}</span></div><td>${ti}</div><td>${to}</div><td><span class="badge ${sc}">${r.status.charAt(0).toUpperCase()+r.status.slice(1)}</span></div><td><button class="btn-view-student view-student-btn" data-student-id="${r.student_id??''}"><i class="fa-solid fa-eye"></i> View</button></div></tr>`; }
    function bindEndButtons() { document.querySelectorAll('.end-sitin-btn:not([data-bound])').forEach(btn => { btn.setAttribute('data-bound','1'); btn.addEventListener('click', async () => { if (!confirm('End this sit-in session?')) return; const fd=new FormData(); fd.append('_action','end_sitin'); fd.append('sitin_id',btn.dataset.id); try { const res=await fetch('admin.php',{method:'POST',body:fd}); const data=await res.json(); if(data.success){showToast('Sit-in ended.','success'); const row=btn.closest('tr'); if(row){ const studentId=row.querySelector('[data-student-id]')?.dataset?.studentId; if(studentId){ const rsvCard=document.querySelector(`.reservation-card[data-student-id="${studentId}"][data-status="approved"]`); if(rsvCard){ rsvCard.dataset.status='done'; const badge=rsvCard.querySelector('.status-badge-res'); if(badge){badge.style.background='#6c3fcf';badge.textContent='Completed';} } } row.remove(); } await doPoll();}else showToast(data.message,'error'); } catch { showToast('Server error.','error'); } }); }); }
    async function doPoll() {
      if (isPolling) return; isPolling=true;
      try {
        const fd=new FormData(); fd.append('_action','poll_live');
        const res=await fetch('admin.php',{method:'POST',body:fd}); const data=await res.json();
        if (!data.success) return;
        const elTotal=document.getElementById('stat-total'),elCur=document.getElementById('stat-currently'),elTot=document.getElementById('stat-total-sitin');
        if(elTotal) elTotal.textContent=data.total; if(elCur) elCur.textContent=data.currently; if(elTot) elTot.textContent=data.total_sitin;
        const sitinTbody=document.querySelector('#sitinTable tbody');
        if (sitinTbody) {
          const domIds=new Set([...sitinTbody.querySelectorAll('tr[data-sitin-id]')].map(r=>r.dataset.sitinId));
          const liveIds=new Set(data.sitins.map(s=>String(s.id)));
          domIds.forEach(id=>{ if(!liveIds.has(id)) sitinTbody.querySelector(`tr[data-sitin-id="${id}"]`)?.remove(); });
          data.sitins.forEach(sit=>{ if(!domIds.has(String(sit.id))){ const empty=sitinTbody.querySelector('td[colspan]')?.closest('tr'); if(empty) empty.remove(); sitinTbody.insertAdjacentHTML('afterbegin',renderSitinRow(sit)); } });
          if(data.sitins.length===0&&sitinTbody.querySelectorAll('tr[data-sitin-id]').length===0&&!sitinTbody.querySelector('td[colspan]')) sitinTbody.innerHTML='<tr><td colspan="8" style="text-align:center;color:var(--muted);padding:2rem">No active sit-in sessions</div><td>';
          bindEndButtons();
          updatePagination('sitin');
        }
        const recTbody=document.querySelector('#recordsTable tbody');
        if(recTbody&&data.records) { const savedPage=paginationState['records']?.page||1; recTbody.innerHTML=data.records.map((r,i)=>renderRecordRow(r,i)).join(''); applyRecordsFilter(); if(!paginationState['records']) paginationState['records']={page:1}; paginationState['records'].page=savedPage; initReservationCardActions(); updatePagination('records'); }
      } catch {} finally { isPolling=false; }
    }
    bindEndButtons();
    setInterval(doPoll, INTERVAL);
    window.triggerSitinRefresh = () => { isPolling = false; doPoll(); };
  })();

  /* RESERVATION POLLING */
  (function startReservationPoll() {
    async function pollReservations() {
      try {
        const fd = new FormData(); fd.append('_action','poll_reservations');
        const res = await fetch('admin.php',{method:'POST',body:fd}); const data = await res.json();
        if (!data.success || !data.reservations) return;
        const container = document.getElementById('reservationCardsGrid');
        if (!container) return;
        if (data.reservations.length === 0) {
          container.innerHTML = '<div class="dash-card" style="text-align:center;padding:3rem;color:var(--muted);grid-column:1/-1"><i class="fa-solid fa-calendar-check" style="font-size:3rem;color:#ddd6fe;display:block;margin-bottom:1rem"></i><h3 style="font-family:var(--ff);color:var(--text);margin-bottom:.4rem">No Reservations Yet</h3><p style="font-size:.875rem">Student reservations will appear here once submitted.</p></div>';
          return;
        }
        let cardsHtml = '';
        for (const r of data.reservations) {
          const statusBg = r.status === 'approved' ? '#22c55e' : (r.status === 'pending' ? '#f59e0b' : (r.status === 'done' ? '#6c3fcf' : (r.status === 'cancelled' ? '#ef4444' : '#9ca3af')));
          const statusText = r.status === 'cancelled' ? 'Denied' : (r.status === 'done' ? 'Completed' : (r.status === 'approved' ? 'Approved' : (r.status === 'pending' ? 'Pending' : r.status.charAt(0).toUpperCase() + r.status.slice(1))));
          const reservationDate = r.date ? new Date(r.date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—';
          const timeIn = r.time_in ? (()=>{ try{ return new Date('1970-01-01T'+r.time_in).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}); }catch{ return r.time_in; } })() : '—';
          const initial = (r.first_name && r.first_name.length > 0) ? r.first_name.charAt(0).toUpperCase() : 'S';
          const labVal = (r.lab||'').replace(/"/g,'&quot;');
          let actionButtons = '';
          if (r.status === 'pending') {
            actionButtons = `<button class="approve-rsv-btn" data-id="${r.id}" data-student="${(r.first_name||'')+' '+(r.last_name||'')}" data-lab="${labVal}" data-date="${r.date||''}" data-time="${r.time_in||''}" data-purpose="${(r.purpose||'').replace(/"/g,'&quot;')}" data-pc="${r.pc_number||''}" style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.5rem;border-radius:8px;border:none;background:#22c55e;color:#fff;font-size:.75rem;font-weight:700;cursor:pointer"><i class="fa-solid fa-check"></i> Approve</button><button class="reject-rsv-btn" data-id="${r.id}" data-student="${(r.first_name||'')+' '+(r.last_name||'')}" data-lab="${labVal}" data-date="${r.date||''}" data-time="${r.time_in||''}" data-purpose="${(r.purpose||'').replace(/"/g,'&quot;')}" data-pc="${r.pc_number||''}" style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.5rem;border-radius:8px;border:1.5px solid #fecaca;background:#fef2f2;color:#ef4444;font-size:.75rem;font-weight:600;cursor:pointer"><i class="fa-solid fa-ban"></i> Deny</button>`;
          }
          cardsHtml += `<div class="reservation-card" data-status="${r.status}" data-lab="${labVal}" data-student-id="${r.student_db_id}" data-reservation-id="${r.id}">
            <div style="background:linear-gradient(135deg,#6c3fcf,#a259f7);padding:0.9rem 1.2rem;color:#fff">
              <div style="display:flex;align-items:center;justify-content:space-between">
                <div><div style="font-size:.7rem;opacity:0.8;letter-spacing:0.5px">LABORATORY</div><div style="font-size:1.3rem;font-weight:800">${r.lab || '—'}</div></div>
                <div class="status-badge-res" style="background:${statusBg}">${statusText}</div>
              </div>
            </div>
            <div style="padding:1rem 1.2rem">
              <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem">
                <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#ede9fe,#f5f3ff);display:flex;align-items:center;justify-content:center;color:#6c3fcf;font-weight:800;font-size:1rem">${initial}</div>
                <div><div style="font-weight:800;color:#1a1a2e">${r.first_name||''} ${r.last_name||''}</div><div style="font-size:.7rem;color:#6b7280">ID: ${r.student_id_number||'—'}</div></div>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:1rem">
                <div><div style="font-size:.65rem;color:#6b7280;margin-bottom:.15rem">COURSE</div><div style="font-size:.8rem;font-weight:600">${r.course||'—'}</div></div>
                <div><div style="font-size:.65rem;color:#6b7280;margin-bottom:.15rem">PURPOSE</div><div style="font-size:.8rem;font-weight:600">${r.purpose||'—'}</div></div>
                <div><div style="font-size:.65rem;color:#6b7280;margin-bottom:.15rem">DATE</div><div style="font-size:.8rem;font-weight:600">${reservationDate}</div></div>
                <div><div style="font-size:.65rem;color:#6b7280;margin-bottom:.15rem">TIME</div><div style="font-size:.8rem;font-weight:600">${timeIn}</div></div>
                <div style="grid-column:1/-1"><div style="font-size:.65rem;color:#6b7280;margin-bottom:.15rem">REQUESTED PC</div><div style="font-size:.8rem;font-weight:600">${(()=>{ const pc = r.admin_pc || r.pc_number; return pc ? `<span style="background:#ede9fe;color:#6c3fcf;padding:.1rem .45rem;border-radius:5px;font-size:.78rem;font-weight:700"><i class="fa-solid fa-desktop" style="font-size:.68rem"></i> PC-${String(pc).padStart(2,'0')}</span>` : '<span style="color:#9ca3af">No preference</span>'; })()}</div></div>
              </div>
              <div style="display:flex;gap:.6rem;margin-top:.5rem;border-top:1px solid #f0ecff;padding-top:.9rem">
                <button class="view-student-btn" data-student-id="${r.student_db_id}" style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.5rem;border-radius:8px;border:1.5px solid #e5e7eb;background:#fff;color:#4b5563;font-size:.75rem;font-weight:600;cursor:pointer"><i class="fa-solid fa-eye"></i> View</button>
                ${actionButtons}
              </div>
            </div>
          </div>`;
        }
        container.innerHTML = cardsHtml;
        initReservationCardActions();
        updatePendingBadge();
        applyReservationFilters();
      } catch {}
    }
    setInterval(pollReservations, 8000);
  })();

  });

</script>
<!-- SEAT MAP APPROVAL MODAL -->
<style>
/* ── Approval Flow Steps ── */
.approval-step { display:none; }
.approval-step.active { display:block; }

/* Step indicator */
.approval-steps-indicator {
  display:flex;
  align-items:center;
  gap:0;
  margin-bottom:1.2rem;
  background:#f8f5ff;
  border-radius:12px;
  padding:.55rem .9rem;
  border:1.5px solid #ede9fe;
}
.step-dot {
  display:flex;
  align-items:center;
  gap:.45rem;
  font-size:.75rem;
  font-weight:700;
  color:#c4b5fd;
  transition:color .2s;
}
.step-dot.active { color:var(--purple-mid); }
.step-dot.done { color:#16a34a; }
.step-dot .dot {
  width:26px;height:26px;border-radius:50%;
  background:#e9d5ff;color:#7c3aed;
  display:flex;align-items:center;justify-content:center;
  font-size:.72rem;font-weight:800;
  transition:background .2s,color .2s;
  flex-shrink:0;
}
.step-dot.active .dot { background:var(--purple-mid);color:#fff; }
.step-dot.done .dot { background:#16a34a;color:#fff; }
.step-connector { flex:1;height:2px;background:#e9d5ff;margin:0 .5rem; }
.step-connector.done { background:#16a34a; }

/* Lab picker grid */
.lab-picker-grid {
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
  gap:.75rem;
  margin-top:.75rem;
}
.lab-picker-card {
  border:2px solid #e9d5ff;
  border-radius:12px;
  padding:.8rem 1rem;
  cursor:pointer;
  background:#fff;
  transition:border-color .18s,background .18s,transform .15s,box-shadow .18s;
  text-align:center;
  position:relative;
  overflow:hidden;
}
.lab-picker-card:hover {
  border-color:var(--purple-mid);
  background:#faf5ff;
  transform:translateY(-2px);
  box-shadow:0 4px 16px rgba(108,63,207,.12);
}
.lab-picker-card.selected {
  border-color:var(--purple-mid);
  background:linear-gradient(135deg,#f3e8ff,#ede9fe);
  box-shadow:0 0 0 3px rgba(108,63,207,.18);
}
.lab-picker-card.is-requested {
  border-color:#3b82f6;
}
.lab-picker-card .lpc-name {
  font-weight:800;
  font-size:.88rem;
  color:#1a1a2e;
  margin-bottom:.2rem;
}
.lab-picker-card .lpc-tag {
  font-size:.65rem;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.5px;
  color:#9ca3af;
  margin-top:.3rem;
}
.lab-picker-card.is-requested .lpc-tag { color:#2563eb; }
.lab-picker-card .lpc-check {
  position:absolute;top:.45rem;right:.45rem;
  width:18px;height:18px;border-radius:50%;
  background:var(--purple-mid);color:#fff;
  font-size:.65rem;
  display:none;align-items:center;justify-content:center;
}
.lab-picker-card.selected .lpc-check { display:flex; }
.lab-step-action {
  display:flex;align-items:center;justify-content:space-between;
  margin-top:1rem;padding-top:.85rem;border-top:1.5px solid #f0ecff;
  flex-wrap:wrap;gap:.5rem;
}
.lab-step-hint {
  font-size:.78rem;color:var(--muted);
  display:flex;align-items:center;gap:.35rem;
}
/* Lab changed notice */
.lab-changed-notice {
  background:#fffbeb;border:1.5px solid #fcd34d;border-radius:8px;
  padding:.5rem .85rem;font-size:.78rem;font-weight:600;color:#92400e;
  display:none;align-items:center;gap:.4rem;margin-bottom:.75rem;
}
.lab-changed-notice.show { display:flex; }
/* PC count badge */
.lpc-avail {
  font-size:.7rem;font-weight:700;
  background:#dcfce7;color:#15803d;
  border-radius:999px;padding:.1rem .55rem;
  display:inline-block;margin-top:.3rem;
}
/* Step 2 enhanced info bar */
.seatmap-info-bar-v2 {
  display:flex;flex-wrap:wrap;gap:.5rem .9rem;
  align-items:center;
  background:#f8f5ff;border:1.5px solid #ede9fe;border-radius:10px;
  padding:.6rem .9rem;margin-bottom:.8rem;font-size:.8rem;
}
.seatmap-info-bar-v2 .info-chip {
  display:flex;align-items:center;gap:.3rem;color:#374151;font-weight:500;
}
.seatmap-info-bar-v2 .info-chip strong { color:var(--purple-mid); }
.seatmap-info-bar-v2 .change-lab-btn {
  margin-left:auto;padding:.28rem .7rem;border-radius:7px;border:1.5px solid var(--purple-mid);
  background:#fff;color:var(--purple-mid);font-size:.72rem;font-weight:700;cursor:pointer;
  display:flex;align-items:center;gap:.3rem;
  transition:background .15s;
}
.seatmap-info-bar-v2 .change-lab-btn:hover { background:#f3e8ff; }
</style>

<div class="modal-overlay" id="seatmapModal">
  <div class="modal-card seatmap-modal-card" style="max-width:820px">
    <div class="modal-header">
      <h3><i class="fa-solid fa-desktop"></i> <span id="seatmapModalTitle">Approve Reservation</span></h3>
      <button class="modal-close" id="closeSeatmapModal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" style="padding:1.2rem 1.4rem">

      <!-- Step Indicator -->
      <div class="approval-steps-indicator" id="approvalStepsIndicator">
        <div class="step-dot active" id="stepDot1">
          <div class="dot" id="stepNum1">1</div>
          <span>Select Lab</span>
        </div>
        <div class="step-connector" id="stepConn1"></div>
        <div class="step-dot" id="stepDot2">
          <div class="dot" id="stepNum2">2</div>
          <span>Assign PC</span>
        </div>
        <div class="step-connector" id="stepConn2"></div>
        <div class="step-dot" id="stepDot3">
          <div class="dot" id="stepNum3">3</div>
          <span>Confirm</span>
        </div>
      </div>

      <!-- ── STEP 1: Lab Selection ── -->
      <div class="approval-step active" id="approvalStep1">
        <div style="margin-bottom:.75rem">
          <div style="font-size:.82rem;font-weight:700;color:#374151;margin-bottom:.25rem">
            <i class="fa-solid fa-user" style="color:var(--purple-mid)"></i>
            Reservation for: <strong id="seatmapStudentNameS1" style="color:var(--purple-mid)">—</strong>
          </div>
          <div style="font-size:.75rem;color:var(--muted)">
            <i class="fa-regular fa-calendar"></i> <span id="seatmapDateS1">—</span>
            &nbsp;·&nbsp;
            <i class="fa-regular fa-clock"></i> <span id="seatmapTimeS1">—</span>
            &nbsp;·&nbsp;
            <i class="fa-solid fa-tag"></i> <span id="seatmapPurposeS1">—</span>
          </div>
        </div>

        <div style="font-size:.8rem;font-weight:700;color:#374151;margin-bottom:.15rem">
          <i class="fa-solid fa-building" style="color:var(--purple-mid)"></i>
          Select Laboratory
          <span style="font-size:.72rem;font-weight:500;color:var(--muted);margin-left:.4rem">Student requested: <strong id="studentRequestedLab" style="color:#2563eb">—</strong></span>
        </div>
        <div class="lab-picker-grid" id="labPickerGrid">
          <div style="grid-column:1/-1;text-align:center;padding:1.5rem;color:var(--muted)"><i class="fa-solid fa-spinner fa-spin"></i> Loading labs…</div>
        </div>
        <div class="lab-step-action">
          <div class="lab-step-hint">
            <i class="fa-solid fa-circle-info" style="color:#a78bfa"></i>
            Click a lab to select it, then click <strong>Continue</strong>
          </div>
          <div style="display:flex;gap:.5rem">
            <button class="btn-danger-sm" id="labStepRejectBtn"><i class="fa-solid fa-ban"></i> Deny</button>
            <button class="btn-green-sm" id="labStepContinueBtn" disabled><i class="fa-solid fa-arrow-right"></i> Continue</button>
          </div>
        </div>
        <!-- Reject reason (step 1) -->
        <div class="reject-reason-wrap" id="rejectReasonWrapS1">
          <label style="font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:.35rem"><i class="fa-solid fa-comment" style="color:#ef4444"></i> Reason for denial <span style="color:#9ca3af;font-weight:400">(optional)</span></label>
          <textarea class="reject-reason-input" id="rejectReasonInputS1" placeholder="e.g. Lab is fully booked, maintenance scheduled…" rows="2"></textarea>
          <div style="display:flex;gap:.5rem;margin-top:.5rem;justify-content:flex-end">
            <button class="btn-outline-sm" id="cancelRejectBtnS1">Cancel</button>
            <button class="btn-danger-sm" id="confirmRejectBtnS1"><i class="fa-solid fa-ban"></i> Confirm Denial</button>
          </div>
        </div>
      </div>

      <!-- ── STEP 2: PC Seat Map ── -->
      <div class="approval-step" id="approvalStep2">
        <!-- Lab changed notice -->
        <div class="lab-changed-notice" id="labChangedNotice">
          <i class="fa-solid fa-triangle-exclamation"></i>
          Lab changed from <strong id="labChangedFrom">—</strong> to <strong id="labChangedTo">—</strong>.
          Student's original PC choice may not be available.
        </div>

        <!-- Info bar -->
        <div class="seatmap-info-bar-v2" id="seatmapInfoBar">
          <div class="info-chip"><i class="fa-solid fa-user" style="color:var(--purple-mid)"></i> <span id="seatmapStudentName">—</span></div>
          <div class="info-chip"><i class="fa-solid fa-building" style="color:var(--purple-mid)"></i> <strong id="seatmapLabName">—</strong></div>
          <div class="info-chip"><i class="fa-regular fa-calendar" style="color:var(--purple-mid)"></i> <span id="seatmapDate">—</span></div>
          <div class="info-chip"><i class="fa-regular fa-clock" style="color:var(--purple-mid)"></i> <span id="seatmapTime">—</span></div>
          <div class="info-chip"><i class="fa-solid fa-tag" style="color:var(--purple-mid)"></i> <span id="seatmapPurpose">—</span></div>
          <div class="info-chip"><i class="fa-solid fa-desktop" style="color:#3b82f6"></i> Student: <strong id="seatmapStudentPc" style="color:#1d4ed8">—</strong></div>
          <button class="change-lab-btn" id="backToLabStepBtn"><i class="fa-solid fa-arrow-left"></i> Change Lab</button>
        </div>

        <div class="seatmap-legend">
          <span class="legend-item"><span class="legend-dot ld-available"></span>Available</span>
          <span class="legend-item"><span class="legend-dot ld-student"></span>Student's Choice</span>
          <span class="legend-item"><span class="legend-dot ld-selected"></span>Admin Selection</span>
          <span class="legend-item"><span class="legend-dot ld-reserved"></span>Reserved</span>
          <span class="legend-item"><span class="legend-dot ld-inuse"></span>In Use</span>
          <span class="legend-item"><span class="legend-dot ld-unavailable"></span>Unavailable</span>
        </div>
        <div id="seatmapLoading" style="text-align:center;padding:2rem;color:var(--muted)"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>Loading seat map…</div>
        <div class="pc-grid" id="seatmapGrid" style="display:none"></div>
        <div class="seatmap-action-bar">
          <div class="selected-pc-display">
            <i class="fa-solid fa-computer"></i>
            <span id="selectedPcLabel">No PC selected — will use student's choice</span>
          </div>
          <div style="display:flex;gap:.5rem">
            <button class="btn-danger-sm" id="seatmapRejectBtn"><i class="fa-solid fa-ban"></i> Deny</button>
            <button class="btn-green-sm" id="seatmapApproveBtn"><i class="fa-solid fa-check"></i> Approve</button>
          </div>
        </div>
        <!-- Reject reason form (step 2) -->
        <div class="reject-reason-wrap" id="rejectReasonWrap">
          <label style="font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:.35rem"><i class="fa-solid fa-comment" style="color:#ef4444"></i> Reason for denial <span style="color:#9ca3af;font-weight:400">(optional)</span></label>
          <textarea class="reject-reason-input" id="rejectReasonInput" placeholder="e.g. Lab is fully booked, maintenance scheduled…" rows="2"></textarea>
          <div style="display:flex;gap:.5rem;margin-top:.5rem;justify-content:flex-end">
            <button class="btn-outline-sm" id="cancelRejectBtn">Cancel</button>
            <button class="btn-danger-sm" id="confirmRejectBtn"><i class="fa-solid fa-ban"></i> Confirm Denial</button>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>

/* ══════════════════════════════════════════════
   REAL-TIME STUDENTS TABLE
   Polls every 10 seconds when the students page is active.
   Also fires once when navigating to the students page.
══════════════════════════════════════════════ */
let _studentsSnapshot = ''; // used to skip re-render when data hasn't changed

async function loadStudentsTable() {
    const tbody = document.querySelector('#studentsTable tbody');
    if (!tbody) return;
    try {
        const fd = new FormData();
        fd.append('_action', 'get_students');
        const res  = await fetch('admin.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;

        // Bail out early if nothing has changed (avoids flicker / losing search state)
        const snapshot = JSON.stringify(data.students);
        if (snapshot === _studentsSnapshot) return;
        _studentsSnapshot = snapshot;

        // Preserve current search query so we can re-apply it after re-render
        const searchVal = (document.getElementById('studentsSearch')?.value || '').toLowerCase();

        tbody.innerHTML = data.students.length === 0
            ? `<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:2rem">No students found.</td></tr>`
            : data.students.map(s => `
                <tr>
                  <td><code style="font-size:.82rem;background:#f3f0ff;color:var(--purple-mid);padding:.15rem .45rem;border-radius:5px">${escHtml(s.id_number)}</code></td>
                  <td><strong>${escHtml(s.first_name + ' ' + s.last_name)}</strong></td>
                  <td>${escHtml(String(s.year_level))}</td>
                  <td><span class="badge purple">${escHtml(s.course)}</span></td>
                  <td>${s.remaining_session ?? 30}</td>
                  <td style="display:flex;gap:.4rem">
                    <button class="btn-outline-sm edit-student-btn"
                      data-id="${s.id}"
                      data-first="${escAttr(s.first_name)}"
                      data-last="${escAttr(s.last_name)}"
                      data-course="${escAttr(s.course)}"
                      data-year="${s.year_level}"
                      data-sessions="${s.remaining_session ?? 30}">
                      <i class="fa-solid fa-pen"></i> Edit
                    </button>
                    <button class="btn-danger-sm delete-student-btn"
                      data-id="${s.id}"
                      data-name="${escAttr(s.first_name + ' ' + s.last_name)}">
                      <i class="fa-solid fa-trash"></i> Delete
                    </button>
                  </td>
                </tr>`).join('');

        // Re-attach row-level button listeners (edit / delete)
        attachStudentRowListeners();

        // Re-apply search filter and pagination
        if (searchVal) {
            window.applyFilterAndPaginate('students', row => row.textContent.toLowerCase().includes(searchVal));
        } else {
            window.updatePagination('students');
        }
    } catch(e) { /* silently ignore network errors */ }
}

function escHtml(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(str) {
    return String(str ?? '').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function attachStudentRowListeners() {
    document.querySelectorAll('.edit-student-btn:not([data-bound])').forEach(btn => {
        btn.setAttribute('data-bound', '1');
        btn.addEventListener('click', () => {
            document.getElementById('edit_student_id').value = btn.dataset.id;
            document.getElementById('edit_first').value      = btn.dataset.first;
            document.getElementById('edit_last').value       = btn.dataset.last;
            const courseEl = document.getElementById('edit_course');
            courseEl.value = btn.dataset.course;
            // If the stored course isn't in the list, add it dynamically
            if (courseEl.value !== btn.dataset.course) {
                const opt = document.createElement('option');
                opt.value = btn.dataset.course;
                opt.textContent = btn.dataset.course;
                courseEl.appendChild(opt);
                courseEl.value = btn.dataset.course;
            }
            document.getElementById('edit_year').value     = btn.dataset.year;
            document.getElementById('edit_sessions').value = btn.dataset.sessions;
            window.openModal('editStudentModal');
        });
    });
    document.querySelectorAll('.delete-student-btn:not([data-bound])').forEach(btn => {
        btn.setAttribute('data-bound', '1');
        btn.addEventListener('click', async () => {
            if (!confirm(`Delete ${btn.dataset.name}? This cannot be undone.`)) return;
            const fd = new FormData();
            fd.append('_action', 'delete_student');
            fd.append('student_id', btn.dataset.id);
            try {
                const res  = await fetch('admin.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    window.showToast('Student deleted.', 'success');
                    _studentsSnapshot = '';
                    loadStudentsTable();
                } else {
                    window.showToast(data.message, 'error');
                }
            } catch { window.showToast('Server error.', 'error'); }
        });
    });
}

  const adminRefreshRegistry = {
    'home': () => { if(typeof doPoll === 'function') doPoll(); },
    'sitin': () => { if(typeof triggerSitinRefresh === 'function') triggerSitinRefresh(); },
    'reservation': () => { if(typeof updatePendingBadge === 'function') updatePendingBadge(); },
    'students': () => { loadStudentsTable(); },
    'feedback': () => { if (typeof window._loadFeedback === 'function') window._loadFeedback(); },
    'leaderboard': () => { loadLeaderboard(); },
    'reports': () => { if(typeof loadAnalyticsCharts === 'function') loadAnalyticsCharts(); },
    'controls': () => { if(typeof refreshLabsOverview === 'function') refreshLabsOverview(); }
};

function switchPage(pageId, updateHistory) {
    document.querySelectorAll('.dash-page').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.sb-link').forEach(l => l.classList.remove('active'));
    
    const target = document.getElementById('page-' + pageId);
    if (target) target.classList.add('active');
    
    document.querySelectorAll(`.sb-link[data-page="${pageId}"]`).forEach(l => l.classList.add('active'));

    // Persist the current page in the URL hash so refresh stays on the same page
    if (updateHistory !== false) {
        history.replaceState(null, '', '#' + pageId);
    }

    if (adminRefreshRegistry[pageId]) {
        adminRefreshRegistry[pageId]();
    }
    
    if(typeof closeSidebar === 'function') closeSidebar();
}

function adminHeartbeat() {
    if(typeof updatePendingBadge === 'function') updatePendingBadge();

    const activePage = document.querySelector('.dash-page.active')?.id.replace('page-', '');
    if (activePage && adminRefreshRegistry[activePage]) {
        adminRefreshRegistry[activePage]();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.sb-link[data-page]').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            switchPage(link.dataset.page);
        });
    });

    // Restore the active page from the URL hash on load (handles refresh & direct navigation)
    const validPages = Array.from(document.querySelectorAll('.dash-page'))
        .map(p => p.id.replace('page-', ''));
    const hashPage = location.hash.replace('#', '');
    if (hashPage && validPages.includes(hashPage)) {
        switchPage(hashPage, false);
    }

    setInterval(adminHeartbeat, 5000);
});

/* ── SOFTWARE PDF UPLOAD ── */
(function() {
  const dropZone  = document.getElementById('pdfDropZone');
  const fileInput = document.getElementById('softwarePdfInput');
  const fileLabel = document.getElementById('pdfFileLabel');
  const fileName  = document.getElementById('pdfFileName');
  const uploadBtn = document.getElementById('uploadSoftwarePdfBtn');
  const statusEl  = document.getElementById('currentPdfStatus');

  // Store the selected file here — works for both click-select AND drag-and-drop
  let selectedFile = null;

  window.loadCurrentPdf = checkCurrentPdf;
  async function checkCurrentPdf() {
    try {
      const fd = new FormData(); fd.append('_action','get_software_pdf');
      const res = await fetch('admin.php',{method:'POST',body:fd});
      const text = await res.text();
      const data = JSON.parse(text);
      if (data.success) {
        statusEl.style.cssText = 'margin-bottom:1rem;padding:.65rem .9rem;border-radius:10px;font-size:.82rem;background:#f0fdf4;border:1.5px solid #bbf7d0;display:flex;align-items:center;gap:.6rem;color:#15803d';
        statusEl.innerHTML = `<i class="fa-solid fa-circle-check"></i> Current: <strong>${data.url.split('/').pop()}</strong> &nbsp;<a href="${data.url}" target="_blank" style="color:#15803d;text-decoration:underline">View PDF</a>`;
      } else {
        statusEl.style.cssText = 'margin-bottom:1rem;padding:.65rem .9rem;border-radius:10px;font-size:.82rem;background:#fefce8;border:1.5px solid #fde68a;display:flex;align-items:center;gap:.6rem;color:#92400e';
        statusEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> No software list uploaded yet.';
      }
    } catch(e) {
      statusEl.style.cssText = 'margin-bottom:1rem;padding:.65rem .9rem;border-radius:10px;font-size:.82rem;background:#fef2f2;border:1.5px solid #fecaca;display:flex;align-items:center;gap:.6rem;color:#ef4444';
      statusEl.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Could not check file status.';
    }
  }
  checkCurrentPdf();

  // Drop zone
  dropZone?.addEventListener('click', () => fileInput?.click());
  dropZone?.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor='var(--purple-mid)'; dropZone.style.background='#faf8ff'; });
  dropZone?.addEventListener('dragleave', () => { dropZone.style.borderColor='#ddd6fe'; dropZone.style.background=''; });
  dropZone?.addEventListener('drop', e => {
    e.preventDefault(); dropZone.style.borderColor='#ddd6fe'; dropZone.style.background='';
    if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]);
  });
  fileInput?.addEventListener('change', () => { if (fileInput.files[0]) handleFile(fileInput.files[0]); });

  function handleFile(f) {
    if (!f.name.toLowerCase().endsWith('.pdf')) { showToast('Only PDF files are allowed.', 'error'); return; }
    if (f.size > 10*1024*1024) { showToast('File too large (max 10MB).', 'error'); return; }
    selectedFile = f;  // save reference — works for both drag-drop and click-select
    fileName.textContent = f.name + ' (' + (f.size/1024/1024).toFixed(2) + ' MB)';
    fileLabel.style.cssText = 'display:flex;margin-top:.75rem;padding:.55rem .85rem;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:9px;font-size:.81rem;color:#15803d;align-items:center;gap:.5rem';
    uploadBtn.style.cssText = 'display:flex;width:100%;margin-top:1rem;justify-content:center';
  }

  uploadBtn?.addEventListener('click', async () => {
    if (!selectedFile) {
      showToast('Please select a PDF file first.', 'error');
      return;
    }
    uploadBtn.disabled = true;
    uploadBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading…';
    const fd = new FormData();
    fd.append('_action', 'upload_software_pdf');
    fd.append('software_pdf', selectedFile);
    try {
      const res  = await fetch('admin.php', {method:'POST', body:fd});
      const text = await res.text();
      let data;
      try { data = JSON.parse(text); }
      catch(pe) {
        console.error('Server raw response:', text);
        showToast('Server error — check PHP error log.', 'error');
        return;
      }
      if (data.success) {
        showToast(data.message || 'PDF uploaded successfully!', 'success');
        selectedFile = null;
        fileInput.value = '';
        fileLabel.style.display = 'none';
        uploadBtn.style.display = 'none';
        checkCurrentPdf();
      } else {
        showToast(data.message || 'Upload failed.', 'error');
      }
    } catch(e) {
      showToast('Network error: ' + e.message, 'error');
    } finally {
      uploadBtn.disabled = false;
      uploadBtn.innerHTML = '<i class="fa-solid fa-upload"></i> Upload PDF';
    }
  });
})();



/* ═══════════════════════════════════════════════════════
   SEAT MAP APPROVAL FLOW  (2-step: Lab Select → PC Assign)
   ═══════════════════════════════════════════════════════ */
(function() {
  let _rsvId = null, _originalLab = null, _labName = null, _rsvDate = null,
      _rsvTime = null, _studentPc = null, _selectedPc = null, _cachedSeats = [],
      _studentName = null, _purpose = null;

  /* ── Helpers ── */
  function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'}) : '—'; }
  function fmtTime(t) { return t ? new Date('2000-01-01T'+t).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}) : '—'; }
  function pcLabel(n) { return n ? 'PC-'+String(n).padStart(2,'0') : 'None'; }

  /* ── Step nav ── */
  function showStep(n) {
    document.querySelectorAll('.approval-step').forEach((el,i) => el.classList.toggle('active', i+1===n));
    // Update dots
    for (let i=1;i<=3;i++) {
      const dot = document.getElementById('stepDot'+i);
      const num = document.getElementById('stepNum'+i);
      dot.classList.remove('active','done');
      if (i < n) { dot.classList.add('done'); num.innerHTML='<i class="fa-solid fa-check" style="font-size:.6rem"></i>'; }
      else if (i === n) { dot.classList.add('active'); num.textContent=i; }
      else { num.textContent=i; }
    }
    for (let i=1;i<=2;i++) {
      document.getElementById('stepConn'+i)?.classList.toggle('done', i < n);
    }
    if (n===1) document.getElementById('seatmapModalTitle').textContent = 'Approve Reservation — Step 1: Select Lab';
    else document.getElementById('seatmapModalTitle').textContent = 'Approve Reservation — Step 2: Assign PC';
  }

  /* ── OPEN (entry point) ── */
  function openSeatmap(rsvId, studentName, labName, rsvDate, rsvTime, purpose, studentPc) {
    _rsvId      = rsvId;
    _originalLab = labName;
    _labName    = labName;
    _rsvDate    = rsvDate;
    _rsvTime    = rsvTime;
    _studentPc  = (studentPc && parseInt(studentPc) > 0) ? parseInt(studentPc) : null;
    _selectedPc = null;
    _studentName = studentName;
    _purpose    = purpose;

    // Populate step-1 info
    document.getElementById('seatmapStudentNameS1').textContent = studentName;
    document.getElementById('seatmapDateS1').textContent        = fmtDate(rsvDate);
    document.getElementById('seatmapTimeS1').textContent        = fmtTime(rsvTime);
    document.getElementById('seatmapPurposeS1').textContent     = purpose || '—';
    document.getElementById('studentRequestedLab').textContent  = labName || '—';

    // Reset step-1 reject
    document.getElementById('rejectReasonWrapS1').classList.remove('show');
    document.getElementById('rejectReasonInputS1').value = '';

    showStep(1);
    document.getElementById('seatmapModal').classList.add('open');
    loadLabsForPicker();
  }

  /* ── Load labs for step-1 picker ── */
  async function loadLabsForPicker() {
    const grid = document.getElementById('labPickerGrid');
    grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:1.5rem;color:var(--muted)"><i class="fa-solid fa-spinner fa-spin"></i> Loading labs…</div>';
    document.getElementById('labStepContinueBtn').disabled = true;

    try {
      const fd = new FormData(); fd.append('_action','get_labs');
      const res = await fetch('admin.php',{method:'POST',body:fd});
      const data = await res.json();
      if (!data.success || !data.labs?.length) { grid.innerHTML='<div style="grid-column:1/-1;text-align:center;color:#ef4444;padding:1rem">No labs available.</div>'; return; }

      grid.innerHTML = '';
      let selectedCard = null;

      data.labs.filter(l => l.is_active).forEach(lab => {
        const isRequested = lab.name === _originalLab;
        const card = document.createElement('div');
        card.className = 'lab-picker-card' + (isRequested ? ' is-requested' : '');
        card.dataset.labName = lab.name;
        card.innerHTML = `
          <div class="lpc-check"><i class="fa-solid fa-check" style="font-size:.55rem"></i></div>
          <div style="font-size:1.4rem;margin-bottom:.3rem">🏫</div>
          <div class="lpc-name">${lab.name}</div>
          <div class="lpc-tag">${isRequested ? '★ Student\'s Request' : lab.capacity+' seats'}</div>
        `;
        if (isRequested) {
          card.classList.add('selected');
          selectedCard = card;
          document.getElementById('labStepContinueBtn').disabled = false;
        }
        card.addEventListener('click', () => {
          document.querySelectorAll('.lab-picker-card').forEach(c => c.classList.remove('selected'));
          card.classList.add('selected');
          selectedCard = card;
          document.getElementById('labStepContinueBtn').disabled = false;
          _labName = lab.name;
        });
        grid.appendChild(card);
      });
      // Pre-select requested lab
      if (!selectedCard) _labName = data.labs[0]?.name || _originalLab;
    } catch(e) {
      grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#ef4444;padding:1rem">Error loading labs.</div>';
    }
  }

  /* ── Step-1 Continue → Step-2 ── */
  document.getElementById('labStepContinueBtn')?.addEventListener('click', () => {
    if (!_labName) return;
    goToStep2();
  });

  function goToStep2() {
    // Show lab-changed warning if admin picked a different lab
    const changed = _labName !== _originalLab;
    const notice = document.getElementById('labChangedNotice');
    if (changed) {
      document.getElementById('labChangedFrom').textContent = _originalLab;
      document.getElementById('labChangedTo').textContent   = _labName;
      notice.classList.add('show');
      // If lab changed, student PC preference in new lab is irrelevant
      _studentPc = null;
    } else {
      notice.classList.remove('show');
    }

    // Populate step-2 info bar
    document.getElementById('seatmapStudentName').textContent = _studentName;
    document.getElementById('seatmapLabName').textContent     = _labName;
    document.getElementById('seatmapDate').textContent        = fmtDate(_rsvDate);
    document.getElementById('seatmapTime').textContent        = fmtTime(_rsvTime);
    document.getElementById('seatmapPurpose').textContent     = _purpose || '—';
    document.getElementById('seatmapStudentPc').textContent   = !changed && _studentPc ? pcLabel(_studentPc) + ' (student request)' : (changed ? 'N/A — lab changed' : 'No preference');
    document.getElementById('selectedPcLabel').textContent    = 'No PC selected — will confirm student\'s choice';
    document.getElementById('seatmapLoading').style.display   = 'block';
    document.getElementById('seatmapGrid').style.display      = 'none';
    document.getElementById('rejectReasonWrap').classList.remove('show');
    document.getElementById('rejectReasonInput').value        = '';
    document.getElementById('seatmapRejectBtn').style.display = '';
    document.getElementById('seatmapApproveBtn').style.display = '';
    _selectedPc = null;

    showStep(2);
    loadSeatMap();
  }

  /* ── Back to lab step ── */
  document.getElementById('backToLabStepBtn')?.addEventListener('click', () => {
    showStep(1);
    loadLabsForPicker();
  });

  /* ── Load Seat Map ── */
  async function loadSeatMap() {
    const fd = new FormData();
    fd.append('_action','get_lab_seats');
    fd.append('lab_name', _labName);
    fd.append('date', _rsvDate || '');
    fd.append('time_in', _rsvTime || '');
    fd.append('exclude_reservation_id', _rsvId);
    try {
      const res = await fetch('admin.php',{method:'POST',body:fd});
      const data = await res.json();
      if (!data.success) {
        document.getElementById('seatmapLoading').innerHTML = '<span style="color:#ef4444">Failed to load seat map: '+data.message+'</span>';
        return;
      }
      renderSeatMap(data.seats);
    } catch(e) {
      document.getElementById('seatmapLoading').innerHTML = '<span style="color:#ef4444">Server error loading seat map.</span>';
    }
  }

  function renderSeatMap(seats) {
    const grid = document.getElementById('seatmapGrid');
    grid.innerHTML = '';
    _cachedSeats = seats; // store for re-renders

    seats.forEach(seat => {
      const pn = parseInt(seat.pc_number);
      const label = seat.label || ('PC-'+String(pn).padStart(2,'0'));
      const isStudentChoice = (_studentPc && pn === _studentPc);
      const isSelected = (pn === _selectedPc);
      const baseStatus = seat.status; // available | reserved | in_use | unavailable

      // Determine CSS class
      let cls = baseStatus;
      if (isSelected) cls = 'selected';
      else if (isStudentChoice && baseStatus === 'available') cls = 'student_choice';

      const iconMap = {available:'🖥️', student_choice:'⭐', reserved:'🔒', in_use:'⚠️', unavailable:'❌', selected:'✅'};
      const labelMap = {available:'Available', student_choice:'Student Pick', reserved:'Reserved', in_use:'In Use', unavailable:'Off', selected:'Selected'};

      // Admin can click any available or student_choice seat
      const clickable = baseStatus === 'available';

      const div = document.createElement('div');
      div.className = 'pc-seat ' + cls;
      div.dataset.pc = pn;
      div.dataset.status = baseStatus;

      // Blue outline ring on student's preferred PC regardless of its status
      if (isStudentChoice) div.style.outline = '3px solid #3b82f6';

      const displayIcon = isSelected ? '✅' : (isStudentChoice && baseStatus !== 'available' ? '⭐' : (iconMap[baseStatus] || '🖥️'));
      let displayStatus = isSelected ? 'Selected' : (labelMap[cls] || baseStatus);
      if (isStudentChoice && baseStatus !== 'available') displayStatus = 'Student Pick (' + (labelMap[baseStatus] || baseStatus) + ')';

      div.innerHTML = `<span class="pc-seat-icon">${displayIcon}</span><span class="pc-seat-label">${label}</span><span class="pc-seat-status">${displayStatus}</span>`;

      if (clickable) {
        div.style.cursor = 'pointer';
        div.addEventListener('click', () => selectPc(pn, label, seat));
      }
      grid.appendChild(div);
    });

    document.getElementById('seatmapLoading').style.display = 'none';
    document.getElementById('seatmapGrid').style.display = 'grid';

    // Auto-select the student's preferred PC if it's available
    if (_studentPc && !_selectedPc) {
      const preferred = seats.find(s => parseInt(s.pc_number) === _studentPc && s.status === 'available');
      if (preferred) {
        const prefLabel = preferred.label || ('PC-'+String(_studentPc).padStart(2,'0'));
        selectPc(_studentPc, prefLabel, preferred);
      }
    }

    updateSelectedPcLabel();
  }

  function selectPc(pn, label, seat) {
    // Only allow selecting available seats
    if (seat.status !== 'available') return;

    // Toggle off if clicking the already-selected seat
    _selectedPc = (_selectedPc === pn) ? null : pn;

    // Re-render all seats to reflect new selection cleanly
    document.querySelectorAll('.pc-seat').forEach(el => {
      const epn = parseInt(el.dataset.pc);
      const estatus = el.dataset.status;
      const isStudentChoice = (_studentPc && epn === _studentPc);
      const isNowSelected = (epn === _selectedPc);

      // Reset classes
      el.classList.remove('selected', 'available', 'student_choice', 'reserved', 'in_use', 'unavailable');

      let newCls = estatus;
      if (isNowSelected) newCls = 'selected';
      else if (isStudentChoice && estatus === 'available') newCls = 'student_choice';
      el.className = 'pc-seat ' + newCls;

      // Update icon and label text inside
      const iconMap = {available:'🖥️', student_choice:'⭐', reserved:'🔒', in_use:'⚠️', unavailable:'❌', selected:'✅'};
      const labelMap = {available:'Available', student_choice:'Student Pick', reserved:'Reserved', in_use:'In Use', unavailable:'Off', selected:'Selected'};

      const displayIcon = isNowSelected ? '✅' : (isStudentChoice && estatus !== 'available' ? '⭐' : (iconMap[estatus] || '🖥️'));
      let displayStatus = isNowSelected ? 'Selected' : (labelMap[newCls] || estatus);
      if (isStudentChoice && estatus !== 'available') displayStatus = 'Student Pick (' + (labelMap[estatus] || estatus) + ')';

      const iconEl = el.querySelector('.pc-seat-icon');
      const statusEl = el.querySelector('.pc-seat-status');
      if (iconEl) iconEl.textContent = displayIcon;
      if (statusEl) statusEl.textContent = displayStatus;
    });

    updateSelectedPcLabel();
  }

  function updateSelectedPcLabel() {
    const el = document.getElementById('selectedPcLabel');
    if (!el) return;
    if (_selectedPc) {
      el.textContent = '✓ PC-' + String(_selectedPc).padStart(2,'0') + ' selected — click again to deselect';
      el.style.color = '#16a34a';
      el.style.fontWeight = '700';
    } else {
      el.textContent = _studentPc ? 'No PC selected — student requested PC-'+String(_studentPc).padStart(2,'0') : 'No PC selected — will use student\'s choice or none';
      el.style.color = '';
      el.style.fontWeight = '';
    }
  }

  /* ── Close modal ── */
  document.getElementById('closeSeatmapModal')?.addEventListener('click', () => {
    document.getElementById('seatmapModal').classList.remove('open');
  });
  document.getElementById('seatmapModal')?.addEventListener('click', e => {
    if(e.target.id==='seatmapModal') document.getElementById('seatmapModal').classList.remove('open');
  });

  /* ── Step-1 Reject ── */
  document.getElementById('labStepRejectBtn')?.addEventListener('click', () => {
    document.getElementById('rejectReasonWrapS1').classList.add('show');
    document.getElementById('labStepRejectBtn').style.display = 'none';
    document.getElementById('labStepContinueBtn').style.display = 'none';
  });
  document.getElementById('cancelRejectBtnS1')?.addEventListener('click', () => {
    document.getElementById('rejectReasonWrapS1').classList.remove('show');
    document.getElementById('labStepRejectBtn').style.display = '';
    document.getElementById('labStepContinueBtn').style.display = '';
  });
  document.getElementById('confirmRejectBtnS1')?.addEventListener('click', async () => {
    await doReject(document.getElementById('rejectReasonInputS1').value.trim());
  });

  /* ── Step-2 Reject ── */
  document.getElementById('seatmapRejectBtn')?.addEventListener('click', () => {
    document.getElementById('rejectReasonWrap').classList.add('show');
    document.getElementById('seatmapRejectBtn').style.display = 'none';
    document.getElementById('seatmapApproveBtn').style.display = 'none';
  });
  document.getElementById('cancelRejectBtn')?.addEventListener('click', () => {
    document.getElementById('rejectReasonWrap').classList.remove('show');
    document.getElementById('seatmapRejectBtn').style.display = '';
    document.getElementById('seatmapApproveBtn').style.display = '';
  });
  document.getElementById('confirmRejectBtn')?.addEventListener('click', async () => {
    await doReject(document.getElementById('rejectReasonInput').value.trim());
  });

  async function doReject(reason) {
    const fd = new FormData();
    fd.append('_action','reject_reservation');
    fd.append('reservation_id', _rsvId);
    if (reason) fd.append('reason', reason);
    const confirmBtn = document.getElementById('confirmRejectBtn');
    const rejectBtn  = document.getElementById('seatmapRejectBtn');
    if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Denying…'; }
    try {
      const res = await fetch('admin.php',{method:'POST',body:fd});
      const raw = await res.text();
      let data;
      try { data = JSON.parse(raw); } catch(e) {
        console.error('Reject raw response:', raw);
        window.showAdminToast && window.showAdminToast('Server returned unexpected response — check console.','error');
        return;
      }
      if (data.success) {
        document.getElementById('seatmapModal').classList.remove('open');
        window.showAdminToast && window.showAdminToast('Reservation denied.','error');
        updateCardAfterReject(_rsvId);
        if (typeof updatePendingBadge === 'function') updatePendingBadge();
      } else {
        window.showAdminToast && window.showAdminToast(data.message || 'Could not deny reservation.','error');
      }
    } catch(err) {
      console.error('Reject fetch error:', err);
      window.showAdminToast && window.showAdminToast('Network error: '+err.message,'error');
    } finally {
      if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.innerHTML = 'Confirm Deny'; }
    }
  }

  document.getElementById('seatmapApproveBtn')?.addEventListener('click', async () => {
    const fd = new FormData();
    fd.append('_action','approve_reservation');
    fd.append('reservation_id', _rsvId);
    fd.append('admin_lab', _labName);
    if (_selectedPc) fd.append('admin_pc', _selectedPc);
    const btn = document.getElementById('seatmapApproveBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Approving…';
    try {
      const res  = await fetch('admin.php',{method:'POST',body:fd});
      const raw  = await res.text();
      let data;
      try { data = JSON.parse(raw); } catch(e) {
        console.error('Approve raw response:', raw);
        window.showAdminToast && window.showAdminToast('Server returned unexpected response — check console.','error');
        return;
      }
      if (data.success) {
        document.getElementById('seatmapModal').classList.remove('open');
        const pcLabel = data.assigned_pc ? ' (PC-'+String(data.assigned_pc).padStart(2,'0')+')' : '';
        window.showAdminToast && window.showAdminToast(data.message+pcLabel,'success');
        updateCardAfterApprove(_rsvId, data.assigned_pc, _labName);
        if (data.sitin_created && data.sitin_data) injectNewSitin(data);
        triggerSitinRefresh && triggerSitinRefresh();
        if (typeof updatePendingBadge === 'function') updatePendingBadge();
      } else {
        window.showAdminToast && window.showAdminToast(data.message || 'Could not approve reservation.','error');
      }
    } catch(err) {
      console.error('Approve fetch error:', err);
      window.showAdminToast && window.showAdminToast('Network error: '+err.message,'error');
    } finally {
      btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-check"></i> Approve';
    }
  });

  function updateCardAfterApprove(rsvId, assignedPc, assignedLab) {
    const card = document.querySelector(`.reservation-card[data-reservation-id="${rsvId}"]`);
    if (!card) return;
    const badge = card.querySelector('.status-badge-res'); if(badge){badge.style.background='#22c55e';badge.textContent='Approved';}
    card.dataset.status = 'approved';
    // Update lab display in the card header if lab changed
    if (assignedLab) {
      card.dataset.lab = assignedLab;
      const labEl = card.querySelector('div[style*="font-size:1.3rem"]');
      if (labEl) labEl.textContent = assignedLab;
    }
    const btnArea = card.querySelector('div[style*="border-top"]');
    const sid = card.dataset.studentId;
    if (btnArea) {
      let extras = '';
      if (assignedPc) extras += `<div style="font-size:.72rem;color:#16a34a;font-weight:700;text-align:center;width:100%;padding-top:.2rem"><i class="fa-solid fa-desktop"></i> PC-${String(assignedPc).padStart(2,'0')}</div>`;
      if (assignedLab) extras += `<div style="font-size:.72rem;color:#6c3fcf;font-weight:700;text-align:center;width:100%;padding-top:.1rem"><i class="fa-solid fa-building"></i> ${assignedLab}</div>`;
      btnArea.innerHTML = `<button class="view-student-btn" data-student-id="${sid}" style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.5rem;border-radius:8px;border:1.5px solid #e5e7eb;background:#fff;color:#4b5563;font-size:.75rem;font-weight:600;cursor:pointer"><i class="fa-solid fa-eye"></i> View</button>${extras}`;
      window.initReservationCardActions && window.initReservationCardActions();
    }
  }

  function updateCardAfterReject(rsvId) {
    const card = document.querySelector(`.reservation-card[data-reservation-id="${rsvId}"]`);
    if (!card) return;
    const badge = card.querySelector('.status-badge-res'); if(badge){badge.style.background='#ef4444';badge.textContent='Denied';}
    card.dataset.status = 'cancelled';
    const btnArea = card.querySelector('div[style*="border-top"]');
    const sid = card.dataset.studentId;
    if (btnArea) { btnArea.innerHTML = `<button class="view-student-btn" data-student-id="${sid}" style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.5rem;border-radius:8px;border:1.5px solid #e5e7eb;background:#fff;color:#4b5563;font-size:.75rem;font-weight:600;cursor:pointer"><i class="fa-solid fa-eye"></i> View</button>`; window.initReservationCardActions && window.initReservationCardActions(); }
  }

  function injectNewSitin(data) {
    const sd = data.sitin_data;
    if (!sd) return;
    const tbody = document.querySelector('#sitinTable tbody');
    if (!tbody) return;
    const empty = tbody.querySelector('td[colspan]')?.closest('tr'); if(empty) empty.remove();
    if (!tbody.querySelector(`tr[data-sitin-id="${sd.id}"]`)) {
      const tr = document.createElement('tr');
      tr.setAttribute('data-sitin-id', sd.id);
      const pcLabel = sd.pc_number ? ' PC-'+String(sd.pc_number).padStart(2,'0') : '';
      tr.innerHTML = `<td><code style="font-size:.82rem;background:#f3f0ff;color:var(--purple-mid);padding:.15rem .45rem;border-radius:5px">${sd.id}</code></td><td>${sd.student_id_number}</td><td><strong>${sd.first_name} ${sd.last_name}</strong></td><td>${sd.purpose}</td><td><span class="badge purple">${sd.lab}${pcLabel}</span></td><td>${sd.remaining_session}</td><td><span class="badge green"><i class="fa-solid fa-circle"></i> Active</span></td><td><button class="btn-end end-sitin-btn" data-id="${sd.id}"><i class="fa-solid fa-right-from-bracket"></i> End</button></td>`;
      tbody.prepend(tr);
      tr.querySelector('.end-sitin-btn')?.addEventListener('click', async () => {
        if(!confirm('End this sit-in?')) return;
        const fd=new FormData(); fd.append('_action','end_sitin'); fd.append('sitin_id',sd.id);
        const r=await fetch('admin.php',{method:'POST',body:fd}); const d=await r.json();
        if(d.success){window.showAdminToast&&window.showAdminToast('Sit-in ended.','success');tr.remove();triggerSitinRefresh&&triggerSitinRefresh();}
      });
    }
    const cs=document.getElementById('stat-currently'); if(cs) cs.textContent=parseInt(cs.textContent||0)+1;
    const ts=document.getElementById('stat-total-sitin'); if(ts) ts.textContent=parseInt(ts.textContent||0)+1;
  }

  window.openSeatmapApproval = openSeatmap;
})();
</script>


<script>
/* wire showAdminToast alias after main DOMContentLoaded toast is defined */
document.addEventListener('DOMContentLoaded', () => {
  window.showAdminToast = function(msg, type) {
    if (typeof showToast === 'function') { showToast(msg, type); return; }
  };
});
</script>

<script>
/* ═══════════════════════════════════════════
   FILE UPLOADS PAGE
   ═══════════════════════════════════════════ */
(function() {
  const dropZone    = document.getElementById('fileDropZone');
  const fileInput   = document.getElementById('fileUploadInput');
  const selectedLbl = document.getElementById('fileSelectedLabel');
  const selectedName= document.getElementById('fileSelectedName');
  const selectedSize= document.getElementById('fileSelectedSize');
  const uploadBtn   = document.getElementById('doUploadFileBtn');
  const descInput   = document.getElementById('fileDescription');
  const listEl      = document.getElementById('fileLibraryList');
  const countBadge  = document.getElementById('fileCountBadge');
  const progressWrap= document.getElementById('uploadProgress');
  const progressBar = document.getElementById('uploadProgressBar');

  const typeIconMap = {
    pdf: {icon:'fa-file-pdf',        cls:'ftb-pdf',  color:'#dc2626'},
    doc: {icon:'fa-file-word',       cls:'ftb-doc',  color:'#1d4ed8'},
    docx:{icon:'fa-file-word',       cls:'ftb-docx', color:'#1d4ed8'},
    xls: {icon:'fa-file-excel',      cls:'ftb-xls',  color:'#15803d'},
    xlsx:{icon:'fa-file-excel',      cls:'ftb-xlsx', color:'#15803d'},
    ppt: {icon:'fa-file-powerpoint', cls:'ftb-ppt',  color:'#c2410c'},
    pptx:{icon:'fa-file-powerpoint', cls:'ftb-pptx', color:'#c2410c'},
    jpg: {icon:'fa-file-image',      cls:'ftb-img',  color:'#86198f'},
    jpeg:{icon:'fa-file-image',      cls:'ftb-img',  color:'#86198f'},
    png: {icon:'fa-file-image',      cls:'ftb-img',  color:'#86198f'},
    gif: {icon:'fa-file-image',      cls:'ftb-img',  color:'#86198f'},
    webp:{icon:'fa-file-image',      cls:'ftb-img',  color:'#86198f'},
    zip: {icon:'fa-file-zipper',     cls:'ftb-zip',  color:'#92400e'},
    txt: {icon:'fa-file-lines',      cls:'ftb-txt',  color:'#374151'},
  };

  function fmtSize(bytes) { return bytes < 1048576 ? (bytes/1024).toFixed(1)+' KB' : (bytes/1048576).toFixed(2)+' MB'; }
  function getExt(name)   { return name.split('.').pop().toLowerCase(); }
  function typeInfo(name) { const ext=getExt(name); return typeIconMap[ext] || {icon:'fa-file',cls:'ftb-other',color:'#6c3fcf'}; }
  function escHtml(s) { return String(s||'').replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  function handleFile(f) {
    if (!f) return;
    if (f.size > 20*1024*1024) { showToast('File too large (max 20MB).','error'); return; }
    selectedName.textContent  = f.name;
    selectedSize.textContent  = fmtSize(f.size);
    selectedLbl.style.display = 'flex';
    uploadBtn.disabled = false;
  }

  dropZone?.addEventListener('click', () => fileInput?.click());
  dropZone?.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
  dropZone?.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
  dropZone?.addEventListener('drop', e => {
    e.preventDefault(); dropZone.classList.remove('drag-over');
    if (e.dataTransfer.files[0]) { fileInput.files = e.dataTransfer.files; handleFile(e.dataTransfer.files[0]); }
  });
  fileInput?.addEventListener('change', () => { if (fileInput.files[0]) handleFile(fileInput.files[0]); });

  uploadBtn?.addEventListener('click', async () => {
    if (!fileInput.files[0]) return;
    uploadBtn.disabled = true;
    uploadBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading…';
    progressWrap.style.display = 'block'; progressBar.style.width = '30%';
    const fd = new FormData();
    fd.append('_action','upload_file'); fd.append('upload_file', fileInput.files[0]);
    fd.append('file_category', 'general'); fd.append('file_description', descInput.value.trim());
    try {
      progressBar.style.width = '70%';
      const res  = await fetch('admin.php',{method:'POST',body:fd});
      const data = await res.json();
      progressBar.style.width = '100%';
      setTimeout(() => { progressWrap.style.display='none'; progressBar.style.width='0'; }, 500);
      if (data.success) {
        showToast(data.message,'success');
        fileInput.value=''; selectedLbl.style.display='none'; descInput.value='';
        uploadBtn.disabled = true;
        loadFiles();
      } else { showToast(data.message || 'Upload failed.','error'); }
    } catch { showToast('Server error during upload.','error'); progressWrap.style.display='none'; }
    finally { uploadBtn.disabled=false; uploadBtn.innerHTML='<i class="fa-solid fa-upload"></i> Upload File'; }
  });

  async function loadFiles() {
    listEl.innerHTML = '<div style="text-align:center;padding:1.5rem;color:var(--muted)"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    const fd = new FormData(); fd.append('_action','get_files'); fd.append('category', 'all');
    try {
      const res = await fetch('admin.php',{method:'POST',body:fd});
      const data = await res.json();
      if (!data.success) return;
      const files = data.files || [];
      if (countBadge) countBadge.textContent = files.length;
      if (files.length === 0) {
        listEl.innerHTML = '<div style="text-align:center;padding:2.5rem;color:var(--muted);font-size:.84rem"><i class="fa-solid fa-folder-open" style="font-size:2rem;color:#ddd6fe;display:block;margin-bottom:.6rem"></i>No files uploaded yet.</div>';
        return;
      }
      const catColors = {general:'#6c3fcf',announcement:'#ef4444',schedule:'#0369a1',report:'#15803d',resource:'#a21caf',other:'#9ca3af'};
      listEl.innerHTML = files.map(f => {
        const ti = typeInfo(f.original_name);
        const catColor = catColors[f.category] || '#9ca3af';
        const uploadDate = new Date(f.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
        return `<div class="file-card">
          <div class="file-card-icon" style="background:${ti.color}18">
            <i class="fa-solid ${ti.icon}" style="color:${ti.color}"></i>
          </div>
          <div class="file-card-meta">
            <div class="file-card-name" title="${escHtml(f.original_name)}">${escHtml(f.original_name)}</div>
            <div class="file-card-info">
              <span class="file-type-badge ${ti.cls}">${getExt(f.original_name)}</span>
              <span style="margin-left:.35rem">${f.size_human}</span>
              <span style="margin:0 .3rem;color:#d1d5db">·</span>
              <span style="color:${catColor};font-weight:600;font-size:.7rem;text-transform:capitalize">${f.category}</span>
              <span style="margin:0 .3rem;color:#d1d5db">·</span>${uploadDate}
              ${f.download_count > 0 ? `<span style="margin:0 .3rem;color:#d1d5db">·</span><i class="fa-solid fa-download" style="font-size:.6rem"></i> ${f.download_count}` : ''}
            </div>
            ${f.description ? `<div style="font-size:.73rem;color:var(--muted);margin-top:.15rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escHtml(f.description)}</div>` : ''}
          </div>
          <div style="display:flex;gap:.4rem;flex-shrink:0">
            <a href="admin.php?download_file=${f.id}" title="Download" style="width:32px;height:32px;border-radius:8px;background:#f3f0ff;color:var(--purple-mid);display:flex;align-items:center;justify-content:center;font-size:.8rem;text-decoration:none;border:none"><i class="fa-solid fa-download"></i></a>
            <button onclick="replaceFile(${f.id}, '${escHtml(f.original_name)}')" title="Replace" style="width:32px;height:32px;border-radius:8px;background:#fefce8;color:#d97706;border:none;cursor:pointer;font-size:.8rem"><i class="fa-solid fa-arrow-up-from-bracket"></i></button>
            <button onclick="deleteFile(${f.id})" title="Delete" style="width:32px;height:32px;border-radius:8px;background:#fef2f2;color:#ef4444;border:none;cursor:pointer;font-size:.8rem"><i class="fa-solid fa-trash"></i></button>
          </div>
        </div>`;
      }).join('');
    } catch { listEl.innerHTML = '<div style="color:#ef4444;text-align:center;padding:1rem;font-size:.82rem">Failed to load files.</div>'; }
  }

  window.deleteFile = async function(id) {
    if (!confirm('Delete this file? This cannot be undone.')) return;
    const fd = new FormData(); fd.append('_action','delete_file'); fd.append('file_id', id);
    try {
      const res  = await fetch('admin.php',{method:'POST',body:fd});
      const data = await res.json();
      if (data.success) { showToast('File deleted.','success'); loadFiles(); }
      else showToast(data.message || 'Could not delete.','error');
    } catch { showToast('Server error.','error'); }
  };

  // Hidden input for replace
  const replaceInput = document.createElement('input');
  replaceInput.type = 'file';
  replaceInput.accept = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.gif,.webp,.zip';
  replaceInput.style.display = 'none';
  document.body.appendChild(replaceInput);
  let _replaceTargetId = null;

  window.replaceFile = function(id, name) {
    _replaceTargetId = id;
    replaceInput.value = '';
    replaceInput.click();
  };

  replaceInput.addEventListener('change', async () => {
    const file = replaceInput.files[0];
    if (!file || !_replaceTargetId) return;
    const fd = new FormData();
    fd.append('_action', 'replace_file');
    fd.append('file_id', _replaceTargetId);
    fd.append('upload_file', file);
    fd.append('file_category', 'general');
    fd.append('file_description', '');
    try {
      showToast('Replacing file…', 'info');
      const res  = await fetch('admin.php', {method:'POST', body:fd});
      const data = await res.json();
      if (data.success) { showToast('File replaced successfully!', 'success'); loadFiles(); }
      else showToast(data.message || 'Replace failed.', 'error');
    } catch { showToast('Server error during replace.', 'error'); }
    _replaceTargetId = null;
  });

  loadFiles();
})();

/* ══ LEADERBOARD ══ */
let _leaderboardSnapshot = '';

async function loadLeaderboard(forceSpinner) {
  const tbody  = document.getElementById('leaderboardBody');
  const podium = document.getElementById('leaderboardPodium');
  if (!tbody) return;

  const colSpan = 8;

  // Only show the loading spinner on the very first load (or when manually refreshed)
  if (forceSpinner || tbody.querySelector('td[colspan]')?.textContent?.includes('No leaderboard')) {
    tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align:center;padding:3rem;color:var(--muted)"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;color:#ddd6fe;display:block;margin-bottom:.7rem"></i>Loading leaderboard...</td></tr>`;
    if (podium) podium.style.display = 'none';
  }

  try {
    const fd = new FormData(); fd.append('_action','get_leaderboard');
    const res  = await fetch('admin.php',{method:'POST',body:fd});
    const data = await res.json();

    // Skip re-render if data hasn't changed (prevents flicker during polling)
    const snapshot = JSON.stringify(data.leaderboard);
    if (snapshot === _leaderboardSnapshot) return;
    _leaderboardSnapshot = snapshot;

    if (!data.success || !data.leaderboard.length) {
      tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align:center;padding:3rem;color:var(--muted)"><i class="fa-solid fa-trophy" style="font-size:2.5rem;color:#ddd6fe;display:block;margin-bottom:.7rem"></i>No leaderboard data yet.<br><small>Students need at least 1 completed sit-in to appear.</small></td></tr>`;
      return;
    }

    const lb = data.leaderboard;

    /* ── Podium (top 3) ── */
    if (podium && lb.length >= 1) {
      const podiumData = [
        { idx: 1, height: '130px', medal: '🥈', bg: 'linear-gradient(135deg,#e5e7eb,#f3f4f6)', border: '#9ca3af', labelBg: '#6b7280' },
        { idx: 0, height: '170px', medal: '🥇', bg: 'linear-gradient(135deg,#fef9c3,#fef3c7)', border: '#f5c518', labelBg: '#d97706' },
        { idx: 2, height: '100px', medal: '🥉', bg: 'linear-gradient(135deg,#fde8d8,#fed7aa)', border: '#cd7f32', labelBg: '#b45309' },
      ].filter(p => lb[p.idx]);

      podium.style.display = 'grid';
      podium.style.cssText = 'display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;max-width:700px;margin-left:auto;margin-right:auto;align-items:end';

      podium.innerHTML = podiumData.map(p => {
        const s = lb[p.idx];
        const initials = (s.first_name?.[0]||'') + (s.last_name?.[0]||'');
        const stars = s.avg_rating ? '★'.repeat(Math.round(s.avg_rating)) + '☆'.repeat(5-Math.round(s.avg_rating)) : '';
        const avatarInner = s.profile_photo
          ? `<img src="${s.profile_photo}?v=${Date.now()}" style="width:100%;height:100%;object-fit:cover;border-radius:50%" alt="${initials}" onerror="this.parentElement.innerHTML='${initials.toUpperCase()}'">`
          : initials.toUpperCase();
        const avatarStyle = s.profile_photo ? `background:${p.labelBg};overflow:hidden` : `background:${p.labelBg}`;
        return `<div style="background:${p.bg};border:2px solid ${p.border};border-radius:16px;padding:1.2rem .8rem;text-align:center;display:flex;flex-direction:column;align-items:center;gap:.4rem;min-height:${p.height};justify-content:flex-end">
          <div style="font-size:1.8rem">${p.medal}</div>
          <div style="width:56px;height:56px;border-radius:50%;${avatarStyle};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1rem;font-family:var(--ff);border:3px solid ${p.border};box-shadow:0 2px 10px rgba(0,0,0,.15)">${avatarInner}</div>
          <div style="font-weight:800;font-size:.88rem;color:var(--text);line-height:1.2">${s.display_name}</div>
          <div style="font-size:.72rem;color:var(--muted)">${s.course} · ${s.year_label}</div>
          <div style="background:${p.labelBg};color:#fff;border-radius:20px;padding:.18rem .65rem;font-size:.75rem;font-weight:700">${Number(s.final_score).toFixed(2)} pts</div>
          ${stars ? `<div style="font-size:.7rem;color:#f5c518;letter-spacing:1px" title="Avg feedback rating: ${s.avg_rating}">${stars}</div>` : ''}
          <div style="font-size:.7rem;color:var(--muted)">${s.total_sitins} sessions</div>
        </div>`;
      }).join('');
    }

    /* ── Full table ── */
    const medalIcons = ['🥇','🥈','🥉'];
    tbody.innerHTML = lb.map((s, i) => {
      const rankCell = i < 3
        ? `<td style="text-align:center;font-size:1.15rem">${medalIcons[i]}</td>`
        : `<td style="text-align:center;color:var(--muted);font-weight:700;font-size:.88rem">${s.rank}</td>`;

      const rowHighlight = i === 0 ? 'background:#fffbeb' : i === 1 ? 'background:#fafafa' : i === 2 ? 'background:#fdf6ee' : '';

      const initials = (s.first_name?.[0]||'') + (s.last_name?.[0]||'');
      const avatarBg = ['#6c3fcf','#a259f7','#3b82f6','#22c55e','#f59e0b','#ef4444'][i % 6];
      const tableAvatarInner = s.profile_photo
        ? `<img src="${s.profile_photo}?v=${Date.now()}" style="width:100%;height:100%;object-fit:cover;border-radius:50%" alt="${initials}" onerror="this.style.display='none';this.parentElement.setAttribute('data-fb','${initials.toUpperCase()}')">`
        : initials.toUpperCase();
      const tableAvatarStyle = s.profile_photo ? `overflow:hidden;background:${avatarBg}` : `background:${avatarBg}`;

      const ratingStars = s.avg_rating
        ? `<span style="color:#f5c518;font-size:.75rem">${'★'.repeat(Math.round(s.avg_rating))}</span><span style="color:#d1d5db;font-size:.75rem">${'☆'.repeat(5-Math.round(s.avg_rating))}</span> <span style="font-size:.72rem;color:var(--muted)">(${s.avg_rating})</span>`
        : `<span style="font-size:.72rem;color:#d1d5db">No rating</span>`;

      const sessionBar = Math.min(100, Math.round((s.sessions_used / 30) * 100));
      const sessionBarColor = sessionBar >= 80 ? '#ef4444' : sessionBar >= 50 ? '#f59e0b' : '#6c3fcf';

      return `<tr style="${rowHighlight}">
        ${rankCell}
        <td>
          <div style="display:flex;align-items:center;gap:.6rem">
            <div style="width:34px;height:34px;border-radius:50%;${tableAvatarStyle};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.75rem;flex-shrink:0">${tableAvatarInner}</div>
            <div>
              <div style="font-weight:700;font-size:.88rem">${s.display_name}</div>
              <div style="font-size:.7rem;color:var(--muted)">${s.id_number}</div>
            </div>
          </div>
        </td>
        <td>
          <span class="badge purple" style="font-size:.7rem">${s.course}</span>
          <div style="font-size:.7rem;color:var(--muted);margin-top:.2rem">${s.year_label}</div>
        </td>
        <td style="text-align:center">
          <div style="font-weight:800;color:var(--purple-mid);font-size:.95rem">${s.total_sitins}</div>
          <div style="width:60px;height:5px;background:#ede9fe;border-radius:10px;margin:.3rem auto 0">
            <div style="width:${sessionBar}%;height:100%;background:${sessionBarColor};border-radius:10px"></div>
          </div>
          <div style="font-size:.67rem;color:var(--muted);margin-top:.15rem">${s.sessions_used}/30 used</div>
        </td>
        <td style="text-align:center">${ratingStars}<br><span style="font-size:.68rem;color:var(--muted)">${s.feedback_count} review${s.feedback_count!==1?'s':''}</span></td>
        <td style="font-size:.8rem;max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${s.fav_purpose}</td>
        <td style="text-align:center;font-size:.78rem;white-space:nowrap;color:var(--muted)">${s.last_seen}</td>
        <td style="text-align:center">
          <span style="background:#f3f0ff;color:var(--purple-mid);font-weight:800;padding:.25rem .7rem;border-radius:20px;font-size:.82rem;white-space:nowrap">${Number(s.final_score).toFixed(2)}</span>
        </td>
      </tr>`;
    }).join('');

  } catch(e) {
    tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align:center;color:#ef4444;padding:2rem;font-size:.85rem"><i class="fa-solid fa-circle-xmark"></i> Failed to load leaderboard. Please try again.</td></tr>`;
  }
}
document.getElementById('refreshLeaderboardBtn')?.addEventListener('click', () => {
  _leaderboardSnapshot = ''; // force full re-render with spinner
  loadLeaderboard(true);
});

/* ══ RESERVATION SYSTEM TOGGLE ══ */
(function() {
  const toggle   = document.getElementById('reservationToggle');
  if (!toggle) return;

  toggle.addEventListener('change', async function() {
    const enabled  = this.checked ? 1 : 0;
    const iconEl   = document.getElementById('rsvToggleIcon');
    const dotEl    = document.getElementById('rsvStatusDot');
    const textEl   = document.getElementById('rsvStatusText');
    const labelR   = document.getElementById('rsvLabelRight');
    const iconI    = iconEl?.querySelector('i');

    // Optimistic UI update
    if (enabled) {
      dotEl.style.background  = '#6c3fcf';
      dotEl.style.animation   = 'pulse 1.5s infinite';
      textEl.textContent      = 'Enabled — Students can make reservations';
      if (iconI) { iconI.className = 'fa-solid fa-calendar-check'; }
      if (labelR) labelR.textContent = 'Disable';
    } else {
      dotEl.style.background  = '#9ca3af';
      dotEl.style.animation   = 'none';
      textEl.textContent      = 'Disabled — Reservations are blocked';
      if (iconI) { iconI.className = 'fa-solid fa-calendar-xmark'; }
      if (labelR) labelR.textContent = 'Enable';
    }

    try {
      const fd = new FormData();
      fd.append('_action', 'toggle_reservation_system');
      fd.append('enabled', enabled);
      const res  = await fetch('admin.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.success) {
        showToast(data.message, 'success');
      } else {
        showToast(data.message || 'Could not update setting.', 'error');
        // Revert
        toggle.checked = !toggle.checked;
        toggle.dispatchEvent(new Event('change'));
      }
    } catch {
      showToast('Server error.', 'error');
      toggle.checked = !toggle.checked;
      toggle.dispatchEvent(new Event('change'));
    }
  });
})();
</script>
<!-- ═══════════════════════ EXPORT DROPDOWN JS ═══════════════════════ -->
<script>
(function() {
  // Generic dropdown toggle
  function initExportDropdown(btnId, menuId) {
    const btn  = document.getElementById(btnId);
    const menu = document.getElementById(menuId);
    if (!btn || !menu) return;
    btn.addEventListener('click', e => {
      e.stopPropagation();
      document.querySelectorAll('.export-dropdown-menu.open').forEach(m => { if(m !== menu) m.classList.remove('open'); });
      menu.classList.toggle('open');
    });
  }

  // Sync date inputs into each link href
  function syncDates(fromId, toId, links) {
    function update() {
      const f = document.getElementById(fromId)?.value || '';
      const t = document.getElementById(toId)?.value   || '';
      links.forEach(({id, base}) => {
        const el = document.getElementById(id);
        if (!el) return;
        let url = base;
        if (f) url += '&date_from=' + f;
        if (t) url += '&date_to='   + t;
        el.href = url;
      });
    }
    document.getElementById(fromId)?.addEventListener('change', update);
    document.getElementById(toId)?.addEventListener('change',   update);
  }

  initExportDropdown('studentsExportDropBtn',     'studentsExportMenu');
  initExportDropdown('recordsExportDropBtn',      'recordsExportMenu');
  initExportDropdown('analyticsExportDropBtn',    'analyticsExportMenu');
  initExportDropdown('feedbackExportDropBtn',     'feedbackExportMenu');

  syncDates('recordsExportFrom', 'recordsExportTo', [
    {id:'recordsExportCsv',  base:'admin.php?export=records&format=csv'},
    {id:'recordsExportXlsx', base:'admin.php?export=records&format=xlsx'},
    {id:'recordsExportPdf',  base:'admin.php?export=records&format=pdf'},
  ]);
  syncDates('analyticsExportFrom', 'analyticsExportTo', [
    {id:'analyticsExportCsv',  base:'admin.php?export=analytics&format=csv'},
    {id:'analyticsExportXlsx', base:'admin.php?export=analytics&format=xlsx'},
    {id:'analyticsExportPdf',  base:'admin.php?export=analytics&format=pdf'},
  ]);
  syncDates('feedbackExportFrom', 'feedbackExportTo', [
    {id:'feedbackExportCsv',  base:'admin.php?export=feedback&format=csv'},
    {id:'feedbackExportXlsx', base:'admin.php?export=feedback&format=xlsx'},
    {id:'feedbackExportPdf',  base:'admin.php?export=feedback&format=pdf'},
  ]);

  // Close all dropdowns when clicking outside
  document.addEventListener('click', () => {
    document.querySelectorAll('.export-dropdown-menu.open').forEach(m => m.classList.remove('open'));
  });
})();
</script>
<!-- RESET RECORDS MODAL -->
<div class="modal-overlay" id="resetRecordsModal" style="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:18px;padding:2rem;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative">
    <div style="text-align:center;margin-bottom:1.25rem">
      <div style="width:56px;height:56px;border-radius:14px;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;font-size:1.5rem;color:#ef4444"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <div style="font-family:var(--ff);font-size:1.1rem;font-weight:800;color:#dc2626">Reset All Sit-in Records</div>
      <div style="font-size:.82rem;color:var(--muted);margin-top:.4rem;line-height:1.5">This will <strong>permanently delete every sit-in log</strong> and reset all student sessions back to 30. This cannot be undone.</div>
    </div>
    <div style="margin-bottom:1rem">
      <label style="font-size:.78rem;font-weight:700;color:var(--text);display:block;margin-bottom:.4rem">Type <code style="background:#fef2f2;color:#ef4444;padding:.1rem .35rem;border-radius:4px;font-size:.82rem">RESET</code> to confirm</label>
      <input type="text" id="resetConfirmInput" placeholder="RESET" autocomplete="off" style="width:100%;padding:.6rem .85rem;border-radius:9px;border:2px solid #fca5a5;font-size:.88rem;outline:none;box-sizing:border-box" oninput="document.getElementById('resetRecordsError').textContent=''">
      <div id="resetRecordsError" style="color:#ef4444;font-size:.76rem;margin-top:.35rem;min-height:1rem"></div>
    </div>
    <div style="display:flex;gap:.6rem">
      <button onclick="closeModal('resetRecordsModal')" style="flex:1;padding:.6rem;border-radius:9px;border:1.5px solid #e5e7eb;background:#fff;color:#4b5563;font-size:.83rem;font-weight:600;cursor:pointer">Cancel</button>
      <button id="confirmResetRecordsBtn" style="flex:1;padding:.6rem;border-radius:9px;border:none;background:#ef4444;color:#fff;font-size:.83rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:.4rem"><i class="fa-solid fa-trash-can"></i> Yes, Reset Everything</button>
    </div>
  </div>
</div>
</body>
</html>