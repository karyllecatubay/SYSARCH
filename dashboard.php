<?php
ob_start();
session_start();
require 'db.php';

// Auto-cancel pending reservations past 10-minute grace period
try {
    $pdo->exec("
        UPDATE reservations 
        SET status = 'cancelled', 
            rejection_reason = 'Expired — student did not appear within 10 minutes'
        WHERE status = 'pending' 
        AND CONCAT(date, ' ', time_in) < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ");
} catch(Exception $e){}

// Ensure upload directory exists
$upload_dir = __DIR__ . '/uploads/profiles/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

/* ══════════════════════════════════════════════════
   HANDLE LOGIN
   ══════════════════════════════════════════════════ */
// Login is handled by login_process.php — dashboard.php no longer processes login POSTs.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'login') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Please use the login page.']);
    exit;
}

if (!isset($_SESSION['student_id']) && !isset($_SESSION['admin_logged_in'])) {
    ob_clean();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Not logged in.']); exit; }
    header('Location: login.php'); exit;
}
if (isset($_SESSION['admin_logged_in']) && $_SESSION['role'] === 'admin') { header('Location: admin.php'); exit; }

$is_admin   = false;
$student_id = $_SESSION['student_id'];

/* ══════════════════════════════════════════════════
   CREATE TABLES IF NEEDED
   ══════════════════════════════════════════════════ */


/* ══════════════════════════════════════════════════
   HANDLE PROFILE UPDATE
   ══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'update_profile') {
    header('Content-Type: application/json');
    $last_name   = trim($_POST['pf_last']    ?? '');
    $first_name  = trim($_POST['pf_first']   ?? '');
    $middle_name = trim($_POST['pf_middle']  ?? '');
    $course      = trim($_POST['pf_course']  ?? '');
    $year_level  = intval($_POST['pf_year']  ?? 0);
    $email       = trim($_POST['pf_email']   ?? '');
    $address     = trim($_POST['pf_address'] ?? '');
    $cur_pw      =      $_POST['pf_cur_pw']  ?? '';
    $new_pw      =      $_POST['pf_new_pw']  ?? '';
    $rep_pw      =      $_POST['pf_rep_pw']  ?? '';
    ob_clean();
    if (!$last_name || !$first_name) { echo json_encode(['success'=>false,'message'=>'First and last name are required.']); exit; }
    ob_clean();
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success'=>false,'message'=>'Invalid email address.']); exit; }
    ob_clean();
    if ($year_level < 1 || $year_level > 4) { echo json_encode(['success'=>false,'message'=>'Year level must be 1 to 4.']); exit; }
    $stmt = $pdo->prepare("SELECT id FROM students WHERE email = ? AND id != ?");
    $stmt->execute([$email, $student_id]);
    ob_clean();
    if ($stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'That email is already used by another account.']); exit; }
    $password_sql = ''; $password_params = [];
    if ($cur_pw !== '' || $new_pw !== '' || $rep_pw !== '') {
        ob_clean();
        if (!$cur_pw) { echo json_encode(['success'=>false,'message'=>'Enter your current password to change it.']); exit; }
        ob_clean();
        if (strlen($new_pw) < 8) { echo json_encode(['success'=>false,'message'=>'New password must be at least 8 characters.']); exit; }
        ob_clean();
        if ($new_pw !== $rep_pw) { echo json_encode(['success'=>false,'message'=>'New passwords do not match.']); exit; }
        $stmt = $pdo->prepare("SELECT password FROM students WHERE id = ?");
        $stmt->execute([$student_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        ob_clean();
        if (!$row || !password_verify($cur_pw, $row['password'])) { echo json_encode(['success'=>false,'message'=>'Current password is incorrect.']); exit; }
        $password_sql = ', password = ?'; $password_params = [password_hash($new_pw, PASSWORD_DEFAULT)];
    }
    $photo_sql = ''; $photo_params = []; $photo_path = '';
    $remove_photo = ($_POST['remove_photo'] ?? '') === '1';
    if ($remove_photo) {
        $stmt_old = $pdo->prepare("SELECT profile_photo FROM students WHERE id = ?");
        $stmt_old->execute([$student_id]);
        $old_row = $stmt_old->fetch(PDO::FETCH_ASSOC);
        if (!empty($old_row['profile_photo'])) { $old_file = __DIR__.'/'.$old_row['profile_photo']; if (file_exists($old_file)) @unlink($old_file); }
        $photo_sql = ", profile_photo = ''"; $_SESSION['profile_photo'] = '';
    } elseif (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_photo'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
        ob_clean();
        if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'])) { echo json_encode(['success'=>false,'message'=>'Invalid photo type.']); exit; }
        ob_clean();
        if ($file['size'] > 2*1024*1024) { echo json_encode(['success'=>false,'message'=>'Photo must be under 2 MB.']); exit; }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $dest_dir = __DIR__.'/uploads/profiles/';
        if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);
        $stmt_old = $pdo->prepare("SELECT profile_photo FROM students WHERE id = ?");
        $stmt_old->execute([$student_id]);
        $old_row = $stmt_old->fetch(PDO::FETCH_ASSOC);
        if (!empty($old_row['profile_photo'])) { $old_file = __DIR__.'/'.$old_row['profile_photo']; if (file_exists($old_file)) @unlink($old_file); }
        $filename = 'profile_'.$student_id.'.'.$ext;
        if (move_uploaded_file($file['tmp_name'], $dest_dir.$filename)) {
            $photo_path = 'uploads/profiles/'.$filename; $photo_sql = ', profile_photo = ?'; $photo_params = [$photo_path];
        ob_clean();
        } else { echo json_encode(['success'=>false,'message'=>'Photo upload failed.']); exit; }
    }
    $params = array_merge([$last_name,$first_name,$middle_name,$course,$year_level,$email,$address], $photo_params, $password_params, [$student_id]);
    $stmt = $pdo->prepare("UPDATE students SET last_name=?,first_name=?,middle_name=?,course=?,year_level=?,email=?,address=?{$photo_sql}{$password_sql} WHERE id=?");
    $stmt->execute($params);
    $_SESSION['student_name'] = $first_name.' '.$last_name; $_SESSION['course'] = $course;
    if (!empty($photo_path)) $_SESSION['profile_photo'] = $photo_path;
    ob_clean();
    echo json_encode(['success'=>true,'message'=>'Profile updated successfully!','student_name'=>$_SESSION['student_name'],'course'=>$course,'year_level'=>$year_level,'email'=>$email,'address'=>$address,'middle_name'=>$middle_name,'photo'=>$photo_path?:null,'photo_removed'=>$remove_photo]);
    exit;
}

/* ── HANDLE RESERVATION SUBMIT ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'make_reservation') {
    header('Content-Type: application/json');
    
    // Check if reservations are enabled
    try {
        $rv = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='reservation_enabled'")->fetchColumn();
        if ($rv !== false && intval($rv) !== 1) {
            ob_clean();
            echo json_encode(['success'=>false,'message'=>'Reservations are currently disabled by the administrator. Please try again later.']);
            exit;
        }
    } catch(Exception $e) {} // If table doesn't exist yet, allow reservations
    
    $student_id = $_SESSION['student_id'];
    $lab = trim($_POST['res_lab'] ?? '');
    $date = trim($_POST['res_date'] ?? '');
    $timein = trim($_POST['res_timein'] ?? '');
    $purpose = trim($_POST['res_purpose'] ?? '');
    $pc_number = intval($_POST['res_pc'] ?? 0) ?: null;
    $software_needed = trim($_POST['res_software'] ?? '');
    
    $stmt = $pdo->prepare("SELECT first_name, last_name, course FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    $student_name = $student['first_name'] . ' ' . $student['last_name'];
    $course = $student['course'];
    
    if (!$lab || !$date || !$timein || !$purpose) {
        ob_clean();
        echo json_encode(['success'=>false, 'message'=>'All fields are required.']);
        exit;
    }

    // Block if selected date+time has already passed
    $selectedTs = strtotime($date . ' ' . $timein);
    if ($selectedTs && $selectedTs <= time()) {
        ob_clean();
        echo json_encode(['success'=>false,'message'=>'The selected time has already passed. Please choose a future time.']);
        exit;
    }
    
    // Block if student currently has an active sit-in (manually logged by admin)
    $sitinChk = $pdo->prepare("SELECT id FROM sitins WHERE student_id=? AND status='active'");
    $sitinChk->execute([$student_id]);
    if ($sitinChk->fetch()) {
        ob_clean();
        echo json_encode(['success'=>false,'message'=>'You currently have an active sit-in session. Please finish your current session before making a reservation.']);
        exit;
    }

    $dupChk = $pdo->prepare("SELECT id FROM reservations WHERE student_id=? AND status IN ('pending','approved')");
    $dupChk->execute([$student_id]);
    if ($dupChk->fetch()) {
        ob_clean();
        echo json_encode(['success'=>false,'message'=>'You already have a pending or approved reservation. Please wait for it to complete before making a new one.']);
        exit;
    }
    
    if ($pc_number) {
        $pcChk = $pdo->prepare("SELECT id FROM reservations WHERE lab=? AND date=? AND (pc_number=? OR admin_pc=?) AND status IN ('pending','approved')");
        $pcChk->execute([$lab, $date, $pc_number, $pc_number]);
        if ($pcChk->fetch()) {
            ob_clean();
            echo json_encode(['success'=>false,'message'=>'The selected PC is already reserved for that date. Please choose a different PC.']);
            exit;
        }
    }
    
    try {
        $ins = $pdo->prepare("INSERT INTO reservations (student_id, student_name, course, lab, date, time_in, purpose, pc_number, software_needed, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $ins->execute([$student_id, $student_name, $course, $lab, $date, $timein, $purpose, $pc_number, $software_needed]);
        $pcLabel = $pc_number ? ' (PC-'.str_pad($pc_number,2,'0',STR_PAD_LEFT).')' : '';
        ob_clean();
        echo json_encode(['success'=>true, 'message'=>'Reservation submitted'.$pcLabel.'! Awaiting admin approval.']);
    } catch (Exception $e) {
        ob_clean();
        echo json_encode(['success'=>false, 'message'=>'Could not save reservation: ' . $e->getMessage()]);
    }
    exit;
}

/* ── AJAX: get_student_seat_map ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'get_student_seat_map') {
    header('Content-Type: application/json');
    $sid = $_SESSION['student_id'] ?? 0;
    ob_clean();
    if (!$sid) { echo json_encode(['success'=>false,'message'=>'Not logged in.']); exit; }
    $lab_name = trim($_POST['lab_name'] ?? '');
    $date     = trim($_POST['date']     ?? '');
    ob_clean();
    if (!$lab_name) { echo json_encode(['success'=>false,'message'=>'Lab required.']); exit; }
    try {
        $lab = $pdo->prepare("SELECT * FROM labs WHERE name=? AND is_active=1"); $lab->execute([$lab_name]);
        $labRow = $lab->fetch(PDO::FETCH_ASSOC);
        if (!$labRow) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Lab not available.']); exit; }
        $capacity = (int)($labRow['capacity'] ?? 40);
        $lab_id   = (int)$labRow['id'];
        $ec = $pdo->query("SELECT COUNT(*) FROM pc_seats WHERE lab_id=$lab_id")->fetchColumn();
        if ($ec < $capacity) {
            $ins = $pdo->prepare("INSERT IGNORE INTO pc_seats (lab_id, pc_number, label) VALUES (?,?,?)");
            for ($i = 1; $i <= $capacity; $i++) $ins->execute([$lab_id, $i, 'PC-'.str_pad($i,2,'0',STR_PAD_LEFT)]);
        }
        $seats = $pdo->prepare("SELECT * FROM pc_seats WHERE lab_id=? ORDER BY pc_number ASC");
        $seats->execute([$lab_id]);
        $seatsData = $seats->fetchAll(PDO::FETCH_ASSOC);
        $reservedPcs = [];
        if ($date) {
            $rsvStmt = $pdo->prepare("SELECT pc_number, admin_pc FROM reservations WHERE lab=? AND date=? AND status IN ('pending','approved')");
            $rsvStmt->execute([$lab_name, $date]);
            foreach ($rsvStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pc = $r['admin_pc'] ?: $r['pc_number'];
                if ($pc) $reservedPcs[] = (int)$pc;
            }
        }
        $inUsePcs = [];
        $sit = $pdo->prepare("SELECT pc_number FROM sitins WHERE lab=? AND status='active'");
        $sit->execute([$lab_name]);
        foreach ($sit->fetchAll(PDO::FETCH_ASSOC) as $s) { if($s['pc_number']) $inUsePcs[]=(int)$s['pc_number']; }
        foreach ($seatsData as &$s) {
            $pn=(int)$s['pc_number'];
            if (!$s['is_functional']) $s['status']='unavailable';
            elseif (in_array($pn,$inUsePcs)) $s['status']='in_use';
            elseif (in_array($pn,$reservedPcs)) $s['status']='reserved';
            else $s['status']='available';
        }
        unset($s);
        ob_clean(); echo json_encode(['success'=>true,'seats'=>$seatsData,'capacity'=>$capacity]);
    } catch(Exception $e) { ob_clean(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

/* ══════════════════════════════════════════════════
   HANDLE RESERVATION CANCEL
   ══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'cancel_reservation') {
    header('Content-Type: application/json');
    $rid = intval($_POST['reservation_id'] ?? 0);
    ob_clean();
    if (!$rid) { echo json_encode(['success'=>false,'message'=>'Invalid reservation.']); exit; }
    try {
        $pdo->prepare("UPDATE reservations SET status='cancelled' WHERE id=? AND student_id=?")->execute([$rid,$student_id]);
        ob_clean();
        echo json_encode(['success'=>true,'message'=>'Reservation cancelled.']);
    ob_clean();
    } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>'Could not cancel.']); }
    exit;
}

/* ══════════════════════════════════════════════════
   HANDLE FEEDBACK SUBMIT
   ══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'submit_feedback') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $sitin_id = intval($_POST['sitin_id'] ?? 0);
    $rating   = intval($_POST['rating']   ?? 0);
    $comment  = trim($_POST['comment']    ?? '');
    if (!$sitin_id || $rating < 1 || $rating > 5) {
        echo json_encode(['success'=>false,'message'=>'Invalid feedback data.']); exit;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sitin_id INT NOT NULL,
            student_id INT NOT NULL,
            rating INT NOT NULL,
            comment TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $exists = $pdo->prepare("SELECT id FROM feedback WHERE sitin_id=? AND student_id=?");
        $exists->execute([$sitin_id, $student_id]);
        if ($exists->fetch()) { echo json_encode(['success'=>false,'message'=>'You already submitted feedback for this session.']); exit; }
        $pdo->prepare("INSERT INTO feedback (sitin_id, student_id, rating, comment) VALUES (?,?,?,?)")->execute([$sitin_id,$student_id,$rating,$comment]);
        echo json_encode(['success'=>true,'message'=>'Feedback submitted! Thank you.']);
    } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>'Could not save feedback: '.$e->getMessage()]); }
    exit;
}

/* ══════════════════════════════════════════════════
   HANDLE MARK ANNOUNCEMENTS READ
   ══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'mark_announcement_read') {
    header('Content-Type: application/json');
    $ann_id = intval($_POST['announcement_id'] ?? 0);
    if ($ann_id) {
        try {
            $pdo->prepare("INSERT IGNORE INTO read_announcements (student_id, announcement_id) VALUES (?,?)")->execute([$student_id, $ann_id]);
        } catch (Exception $_e) {}
    }
    ob_clean();
    echo json_encode(['success'=>true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'mark_all_announcements_read') {
    header('Content-Type: application/json');
    try {
        $all_st = $pdo->query("SELECT id FROM announcements");
        $ann_ids = array_column($all_st->fetchAll(PDO::FETCH_ASSOC), 'id');
        $ins = $pdo->prepare("INSERT IGNORE INTO read_announcements (student_id, announcement_id) VALUES (?,?)");
        foreach ($ann_ids as $aid) { $ins->execute([$student_id, $aid]); }
    } catch (Exception $_e) {}
    ob_clean();
    echo json_encode(['success'=>true]);
    exit;
}

/* ── AJAX: fetch_history (live poll + paginated) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'fetch_history') {
    header('Content-Type: application/json');
    $sid = $_SESSION['student_id'] ?? 0;
    ob_clean();
    if (!$sid) { echo json_encode(['success'=>false]); exit; }
    try {
        $per_page   = 10;
        $page       = max(1, intval($_POST['page'] ?? 1));
        $search     = trim($_POST['search'] ?? '');
        $offset     = ($page - 1) * $per_page;

        $where_parts = ["student_id = ?", "status = 'done'"];
        $bind = [$sid];
        if ($search !== '') {
            $where_parts[] = "(lab LIKE ? OR purpose LIKE ?)";
            $like = '%' . $search . '%';
            $bind[] = $like; $bind[] = $like;
        }
        $wsql = 'WHERE ' . implode(' AND ', $where_parts);

        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM sitins $wsql");
        $cntStmt->execute($bind);
        $total = (int)$cntStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $per_page));
        if ($page > $pages) { $page = $pages; $offset = ($page-1)*$per_page; }

        $rowStmt = $pdo->prepare("SELECT id, lab, purpose, time_in, time_out FROM sitins $wsql ORDER BY time_in DESC LIMIT $per_page OFFSET $offset");
        $rowStmt->execute($bind);
        $sitins = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

        $fbStmt = $pdo->prepare("SELECT sitin_id FROM feedback WHERE student_id = ?");
        $fbStmt->execute([$sid]);
        $fbIds = array_column($fbStmt->fetchAll(PDO::FETCH_ASSOC), 'sitin_id');

        $stStmt = $pdo->prepare("SELECT remaining_session FROM students WHERE id = ?");
        $stStmt->execute([$sid]);
        $stRow = $stStmt->fetch(PDO::FETCH_ASSOC);

        $usedStmt = $pdo->prepare("SELECT COUNT(*) FROM sitins WHERE student_id = ? AND status = 'done'");
        $usedStmt->execute([$sid]);
        $sessions_used = (int)$usedStmt->fetchColumn();

        // Compute live score
        $ptsStmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(TIMESTAMPDIFF(MINUTE,time_in,time_out)),0) AS m FROM sitins WHERE student_id=? AND status='done' AND time_out IS NOT NULL");
        $ptsStmt->execute([$sid]);
        $ptsRow = $ptsStmt->fetch(PDO::FETCH_ASSOC);
        $live_score = round((floor($ptsRow['c']/3)*0.5)+(round($ptsRow['m']/60,2)*0.3),2);

        ob_clean();
        echo json_encode([
            'success'           => true,
            'sitins'            => $sitins,
            'feedbacked_ids'    => array_map('intval', $fbIds),
            'remaining_session' => (int)($stRow['remaining_session'] ?? 0),
            'sessions_used'     => $sessions_used,
            'score'             => $live_score,
            'total'             => $total,
            'page'              => $page,
            'pages'             => $pages,
            'offset'            => $offset,
            'search'            => $search,
        ]);
    } catch (Exception $e) {
        ob_clean();
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ── AJAX: fetch_sessions (Sessions Table) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'fetch_sessions') {
    header('Content-Type: application/json');
    $sid = $_SESSION['student_id'] ?? 0;
    ob_clean();
    if (!$sid) { echo json_encode(['success'=>false]); exit; }
    try {
        $per_page    = 10;
        $page        = max(1, intval($_POST['page'] ?? 1));
        $filter_date = trim($_POST['filter_date'] ?? '');
        $where_parts = ["si.student_id = ?", "si.status = 'done'", "si.time_out IS NOT NULL"];
        $bind        = [$sid];

        if ($filter_date !== '') {
            $where_parts[] = "DATE(si.time_in) = ?";
            $bind[] = $filter_date;
        }

        $wsql = 'WHERE ' . implode(' AND ', $where_parts);

        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM sitins si $wsql");
        $cntStmt->execute($bind);
        $total = (int)$cntStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $per_page));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $per_page;

        $stmt = $pdo->prepare("
            SELECT
                si.id,
                si.lab        AS lab_name,
                si.pc_number,
                DATE(si.time_in) AS date,
                si.time_in,
                si.time_out,
                si.status,
                TIMESTAMPDIFF(SECOND, si.time_in, si.time_out) AS duration_seconds
            FROM sitins si
            $wsql
            ORDER BY si.time_in DESC
            LIMIT $per_page OFFSET $offset
        ");
        $stmt->execute($bind);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_clean();
        echo json_encode([
            'success'  => true,
            'sessions' => $sessions,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $per_page,
            'offset'   => $offset,
        ]);
    } catch (Exception $e) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ── AJAX: fetch_reservations (live poll - recent to old) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'fetch_reservations') {
    header('Content-Type: application/json');
    $sid = $_SESSION['student_id'] ?? 0;
    ob_clean();
    if (!$sid) { echo json_encode(['success'=>false]); exit; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS reservation_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            reservation_id INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rsv_notif (student_id, reservation_id, status)
        )");

        $changed = $pdo->prepare("
            SELECT r.id, r.lab, r.status FROM reservations r
            LEFT JOIN reservation_notifications rn
                ON rn.reservation_id = r.id AND rn.student_id = r.student_id AND rn.status = r.status
            WHERE r.student_id = ? AND r.status IN ('approved','cancelled') AND rn.id IS NULL
        ");
        $changed->execute([$sid]);
        $new_notifs = $changed->fetchAll(PDO::FETCH_ASSOC);
        $ins_notif = $pdo->prepare("INSERT IGNORE INTO reservation_notifications (student_id, reservation_id, status) VALUES (?,?,?)");
        foreach ($new_notifs as $n) { $ins_notif->execute([$sid, $n['id'], $n['status']]); }

        $rst = $pdo->prepare("SELECT * FROM reservations WHERE student_id=? AND status IN ('pending','approved') ORDER BY date DESC, time_in ASC");
        $rst->execute([$sid]);
        $reservations = $rst->fetchAll(PDO::FETCH_ASSOC);

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE student_id=? AND status='pending'");
        $cnt->execute([$sid]);
        $pending_count = (int)$cnt->fetchColumn();

        $nst = $pdo->prepare("
            SELECT rn.id, rn.reservation_id, rn.status, rn.created_at, r.lab, r.date, r.purpose
            FROM reservation_notifications rn
            JOIN reservations r ON r.id = rn.reservation_id
            WHERE rn.student_id = ? AND rn.is_read = 0
            ORDER BY rn.created_at DESC LIMIT 20
        ");
        $nst->execute([$sid]);
        $rsv_notifications = $nst->fetchAll(PDO::FETCH_ASSOC);

        $rsv_enabled = true;
        try {
            $rv = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='reservation_enabled'")->fetchColumn();
            $rsv_enabled = ($rv === false) ? true : (intval($rv) === 1);
        } catch(Exception $_e) {}

        ob_clean();
        echo json_encode([
            'success'              => true,
            'reservations'         => $reservations,
            'pending_count'        => $pending_count,
            'rsv_notifications'    => $rsv_notifications,
            'unread_rsv_count'     => count($rsv_notifications),
            'reservation_enabled'  => $rsv_enabled,
        ]);
    } catch (Exception $e) {
        ob_clean();
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ── AJAX: mark_rsv_notif_read ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'mark_rsv_notif_read') {
    header('Content-Type: application/json');
    $sid = $_SESSION['student_id'] ?? 0;
    $nid = intval($_POST['notif_id'] ?? 0);
    $all = ($_POST['all'] ?? '') === '1';
    try {
        if ($all) {
            $pdo->prepare("UPDATE reservation_notifications SET is_read=1 WHERE student_id=?")->execute([$sid]);
        } elseif ($nid) {
            $pdo->prepare("UPDATE reservation_notifications SET is_read=1 WHERE id=? AND student_id=?")->execute([$nid, $sid]);
        }
        ob_clean();
        echo json_encode(['success'=>true]);
    ob_clean();
    } catch (Exception $e) { echo json_encode(['success'=>false]); }
    exit;
}

/* ── AJAX: get_software_pdf — fetch software availability PDF for students ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'get_software_pdf') {
    header('Content-Type: application/json');
    try {
        $pdf = $pdo->query("SELECT software_pdf FROM labs WHERE software_pdf IS NOT NULL AND software_pdf != '' LIMIT 1")->fetchColumn();
        if ($pdf && file_exists(__DIR__ . '/uploads/software/' . $pdf)) {
            ob_clean();
            echo json_encode(['success'=>true,'url'=>'uploads/software/' . $pdf, 'filename'=>$pdf]);
        } else {
            ob_clean();
            echo json_encode(['success'=>false,'message'=>'No software list uploaded yet.']);
        }
    } catch(Exception $e) {
        ob_clean();
        echo json_encode(['success'=>false,'message'=>'Error fetching software list.']);
    }
    exit;
}

/* ── AJAX: get_public_files — return uploaded PDFs/files for students ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'get_public_files') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    try {
        $category = trim($_POST['category'] ?? 'all');
        $sql  = "SELECT id, original_name, file_type, file_size, category, description, download_count, created_at, stored_name FROM uploaded_files";
        $bind = [];
        if ($category !== 'all') { $sql .= " WHERE category = ?"; $bind[] = $category; }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($bind);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($files as &$f) {
            $f['size_human']   = $f['file_size'] < 1048576 ? round($f['file_size']/1024,1).' KB' : round($f['file_size']/1048576,2).' MB';
            $f['is_pdf']       = strtolower($f['file_type']) === 'application/pdf';
            $f['view_url']     = 'dashboard.php?view_file='     . $f['id'];
            $f['download_url'] = 'dashboard.php?download_file=' . $f['id'];
            $f['url']          = 'uploads/files/' . $f['stored_name'];
        }
        echo json_encode(['success'=>true,'files'=>$files]);
    } catch(Exception $e) { echo json_encode(['success'=>false,'files'=>[]]); }
    exit;
}

/* ── View File Inline for PDF Preview (students) ── */
if (isset($_GET['view_file'])) {
    $id = intval($_GET['view_file']);
    try {
        $row = $pdo->prepare("SELECT * FROM uploaded_files WHERE id=?");
        $row->execute([$id]);
        $f = $row->fetch(PDO::FETCH_ASSOC);
        if (!$f) { http_response_code(404); echo 'File not found.'; exit; }
        $path = __DIR__ . '/uploads/files/' . $f['stored_name'];
        if (!file_exists($path)) { http_response_code(404); echo 'File not found on server.'; exit; }
        $mimeMap = ['pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','ppt'=>'application/vnd.ms-powerpoint','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation','txt'=>'text/plain','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','zip'=>'application/zip'];
        $ext  = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
        $mime = (strpos($f['file_type'], '/') !== false) ? $f['file_type'] : ($mimeMap[$ext] ?? 'application/octet-stream');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . addslashes($f['original_name']) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=3600');
        header('X-Frame-Options: SAMEORIGIN');
        readfile($path);
    } catch(Exception $e) { http_response_code(500); echo 'Server error.'; }
    exit;
}

/* ── Download File (student-accessible) ── */
if (isset($_GET['download_file'])) {
    $id = intval($_GET['download_file']);
    try {
        $row = $pdo->prepare("SELECT * FROM uploaded_files WHERE id=?");
        $row->execute([$id]);
        $f = $row->fetch(PDO::FETCH_ASSOC);
        if (!$f) { http_response_code(404); echo 'File not found.'; exit; }
        $path = __DIR__ . '/uploads/files/' . $f['stored_name'];
        if (!file_exists($path)) { http_response_code(404); echo 'File not found on server.'; exit; }
        // Increment download count
        $pdo->prepare("UPDATE uploaded_files SET download_count = download_count + 1 WHERE id=?")->execute([$id]);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes($f['original_name']) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-cache');
        readfile($path);
    } catch(Exception $e) { http_response_code(500); echo 'Server error.'; }
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $ex_search = trim($_GET['hsearch'] ?? '');
    $ex_where  = ["student_id = ?", "status = 'done'"];
    $ex_bind   = [$student_id];
    if ($ex_search !== '') { $ex_where[] = "(lab LIKE ? OR purpose LIKE ?)"; $like = '%'.$ex_search.'%'; $ex_bind[] = $like; $ex_bind[] = $like; }
    $ex_sql = 'WHERE '.implode(' AND ', $ex_where);
    try {
        $ex_st = $pdo->prepare("SELECT lab, purpose, time_in, time_out FROM sitins $ex_sql ORDER BY time_in DESC");
        $ex_st->execute($ex_bind);
        $rows = $ex_st->fetchAll(PDO::FETCH_ASSOC);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="sitin_history_'.date('Ymd').'.csv"');
        $out = fopen('php://output','w');
        fputcsv($out, ['#','Date','Laboratory','Login','Logout','Purpose']);
        $i = 1;
        foreach ($rows as $r) {
            $ti = $r['time_in']  ? strtotime($r['time_in'])  : null;
            $to = $r['time_out'] ? strtotime($r['time_out']) : null;
            fputcsv($out, [$i++, $ti?date('Y-m-d',$ti):'', $r['lab'], $ti?date('h:i:s A',$ti):'', $to?date('h:i:s A',$to):'', $r['purpose']??'']);
        }
        fclose($out);
    } catch (Exception $e) { echo 'Export error: '.$e->getMessage(); }
    exit;
}

// ── Fetch full student record ──
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$student_name  = isset($student['first_name']) ? $student['first_name'].' '.$student['last_name'] : ($_SESSION['student_name'] ?? 'Student');
$id_number     = $student['id_number']    ?? ($_SESSION['id_number']  ?? '--------');
$course        = $student['course']       ?? ($_SESSION['course']     ?? 'BSIT');
$first_name    = $student['first_name']   ?? explode(' ', $student_name)[0];
$last_name     = $student['last_name']    ?? '';
$middle_name   = $student['middle_name']  ?? '';
$year_level    = (int)($student['year_level'] ?? 1);
$email         = $student['email']        ?? '';
$address       = $student['address']      ?? '';
$profile_photo = $student['profile_photo'] ?? ($_SESSION['profile_photo'] ?? '');

// Sessions
$sessions_used = 0; $sessions_left = 30;
try {
    $su = $pdo->prepare("SELECT COUNT(*) FROM sitins WHERE student_id=? AND status='done'");
    $su->execute([$student_id]); $sessions_used = (int)$su->fetchColumn();
    $sl = $pdo->prepare("SELECT remaining_session FROM students WHERE id=?");
    $sl->execute([$student_id]); $slrow = $sl->fetch(PDO::FETCH_ASSOC);
    $sessions_left = isset($slrow['remaining_session']) ? (int)$slrow['remaining_session'] : max(0, 30 - $sessions_used);
} catch (Exception $_e) {}

// ── Student Points / Score + Sit-in Summary Stats ──
function fmt_duration(int $mins): string {
    if ($mins <= 0) return '0h 0m';
    return floor($mins/60).'h '.($mins%60).'m';
}
$student_score  = 0;
$student_hours  = 0;
$sitin_pts      = 0;
$sum_sessions   = 0;
$avg_duration   = '0h 0m';
$longest_session= '0h 0m';
try {
    $pts_stmt = $pdo->prepare("
        SELECT
            COUNT(*)                                                      AS total_sitins,
            COALESCE(SUM(TIMESTAMPDIFF(MINUTE, time_in, time_out)), 0)   AS total_minutes,
            COALESCE(AVG(TIMESTAMPDIFF(MINUTE, time_in, time_out)), 0)   AS avg_minutes,
            COALESCE(MAX(TIMESTAMPDIFF(MINUTE, time_in, time_out)), 0)   AS max_minutes
        FROM sitins
        WHERE student_id = ? AND status = 'done' AND time_out IS NOT NULL
    ");
    $pts_stmt->execute([$student_id]);
    $pts_row        = $pts_stmt->fetch(PDO::FETCH_ASSOC);
    $sum_sessions   = (int)($pts_row['total_sitins']  ?? 0);
    $total_mins_all = (int)($pts_row['total_minutes'] ?? 0);
    $avg_mins       = (int)round($pts_row['avg_minutes'] ?? 0);
    $max_mins       = (int)($pts_row['max_minutes']   ?? 0);
    $sitin_pts      = floor($sum_sessions / 3);
    $student_hours  = round($total_mins_all / 60, 2);
    $student_score  = round(($sitin_pts * 0.5) + ($student_hours * 0.3), 2);
    $avg_duration   = fmt_duration($avg_mins);
    $longest_session= fmt_duration($max_mins);
} catch (Exception $_e) {}

// ── Sit-in History ──
$history_page     = max(1, (int)($_GET['hpage'] ?? 1));
$history_per_page = 10;
$history_search   = trim($_GET['hsearch'] ?? '');
$history_offset   = ($history_page - 1) * $history_per_page;
$sitin_history = []; $history_total = 0; $history_pages = 1;

try {
    $where_parts = ["s.student_id = ?", "s.status = 'done'"]; $bind_base = [$student_id];
    if ($history_search !== '') { $where_parts[] = "(s.lab LIKE ? OR s.purpose LIKE ?)"; $like = '%'.$history_search.'%'; $bind_base[] = $like; $bind_base[] = $like; }
    $wsql = 'WHERE '.implode(' AND ', $where_parts);
    $cst = $pdo->prepare("SELECT COUNT(*) FROM sitins s $wsql"); $cst->execute($bind_base);
    $history_total = (int)$cst->fetchColumn();
    $history_pages = max(1, (int)ceil($history_total / $history_per_page));
    if ($history_page > $history_pages) { $history_page = $history_pages; $history_offset = ($history_page-1)*$history_per_page; }
    $hst = $pdo->prepare("SELECT s.id, s.lab, s.purpose, s.time_in, s.time_out FROM sitins s $wsql ORDER BY s.time_in DESC LIMIT ? OFFSET ?");
    $hst->bindValue(count($bind_base) + 1, (int)$history_per_page, PDO::PARAM_INT);
    $hst->bindValue(count($bind_base) + 2, (int)$history_offset,   PDO::PARAM_INT);
    foreach ($bind_base as $i => $val) { $hst->bindValue($i + 1, $val); }
    $hst->execute();
    $sitin_history = $hst->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $_e) { $sitin_history = []; echo '<!-- HISTORY ERROR: '.$_e->getMessage().' -->'; }

// Check which history entries already have feedback
$feedbacked_sitins = [];
try {
    $fb = $pdo->prepare("SELECT sitin_id FROM feedback WHERE student_id=?");
    $fb->execute([$student_id]);
    $feedbacked_sitins = array_column($fb->fetchAll(PDO::FETCH_ASSOC), 'sitin_id');
} catch (Exception $_e) {}

// ── Reservations (initial data - recent to old) ──
$reservations = [];
try {
    $rst = $pdo->prepare("SELECT * FROM reservations WHERE student_id=? AND status IN ('pending','approved') ORDER BY 
  CASE WHEN status IN ('pending','approved') THEN 0 ELSE 1 END ASC,
  CASE WHEN CONCAT(date, ' ', time_in) >= NOW() THEN 0 ELSE 1 END ASC,
  date ASC, time_in ASC");
    $rst->execute([$student_id]);
    $reservations = $rst->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $_e) {}

$photo_exists = !empty($profile_photo);

// ── Labs for software display ──
$all_labs = [];
try {
    $all_labs = $pdo->query("SELECT id, name, capacity, is_active FROM labs ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $_e) {}

// ── Reservation system enabled? ──
$reservation_enabled = true;
try {
    $rv = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='reservation_enabled'")->fetchColumn();
    $reservation_enabled = ($rv === false) ? true : (intval($rv) === 1);
} catch (Exception $_e) {}

// ── Notifications (unread announcements) ──
$unread_ann_ids = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS read_announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        announcement_id INT NOT NULL,
        read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_student_ann (student_id, announcement_id)
    )");
    $ra = $pdo->prepare("SELECT announcement_id FROM read_announcements WHERE student_id=?");
    $ra->execute([$student_id]);
    $read_ids = array_column($ra->fetchAll(PDO::FETCH_ASSOC), 'announcement_id');

    $all_ann_ids_st = $pdo->query("SELECT id FROM announcements ORDER BY created_at DESC LIMIT 20");
    $all_ann_ids = array_column($all_ann_ids_st->fetchAll(PDO::FETCH_ASSOC), 'id');
    $unread_ann_ids = array_diff($all_ann_ids, $read_ids);
} catch (Exception $_e) {}

$unread_count = count($unread_ann_ids);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Dashboard</title>
  <link rel="stylesheet" href="style.css"/>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
  <style>
/* ── LAYOUT ── */
.dash-body{display:flex;min-height:100vh;background:#f4f2fb;overflow-x:hidden}
.sidebar{width:240px;min-width:240px;background:#fff;border-right:1px solid #ece9f8;display:flex;flex-direction:column;height:100vh;position:sticky;top:0;z-index:300;box-shadow:2px 0 20px rgba(108,63,207,.06);transition:transform .28s cubic-bezier(.4,0,.2,1)}
.sb-brand{display:flex;align-items:center;gap:.7rem;padding:1.4rem 1.2rem 1rem;border-bottom:1px solid #ece9f8}
.sb-logo{width:38px;height:38px;object-fit:contain;flex-shrink:0}
.sb-brand-text{display:flex;flex-direction:column;gap:.05rem}
.sb-title{font-family:var(--ff);font-size:.9rem;font-weight:800;color:var(--text);line-height:1.2}
.sb-sub{font-size:.68rem;color:var(--muted);font-weight:500}
.sb-nav{padding:.8rem .7rem 0;flex:1}
.sb-nav ul{list-style:none;display:flex;flex-direction:column;gap:.15rem}
.sb-link{display:flex;align-items:center;gap:.8rem;padding:.62rem .9rem;border-radius:10px;text-decoration:none;color:#4b5563;font-size:.875rem;font-weight:500;transition:all .2s;position:relative}
.sb-link:hover{background:#f3f0ff;color:var(--purple-mid)}
.sb-link.active{background:linear-gradient(135deg,#ede9fe,#f5f3ff);color:var(--purple-mid);font-weight:700;box-shadow:0 2px 8px rgba(108,63,207,.1)}
.sb-link.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;background:var(--purple-mid);border-radius:0 3px 3px 0}
.sb-icon{width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:7px;font-size:.85rem;flex-shrink:0;background:transparent;transition:background .2s}
.sb-link.active .sb-icon{background:rgba(108,63,207,.12)}
.sb-link:hover .sb-icon{background:rgba(108,63,207,.08)}
.sb-spacer{flex:1}
.sb-user-section{padding:.7rem .7rem 1rem;border-top:1px solid #ece9f8;position:relative}
.sb-user-btn{display:flex;align-items:center;gap:.7rem;padding:.55rem .65rem;border-radius:10px;cursor:pointer;transition:background .2s}
.sb-user-btn:hover{background:#f3f0ff}
.sb-avatar{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--purple-mid),var(--purple-light));color:#fff;font-family:var(--ff);font-size:.95rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 8px rgba(108,63,207,.35)}
.sb-avatar.sm{width:30px;height:30px;font-size:.78rem;border-radius:8px}
.sb-user-info{flex:1;min-width:0;display:flex;flex-direction:column}
.sb-user-name{font-size:.82rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user-id{font-size:.7rem;color:var(--muted)}
.sb-chevron{font-size:.65rem;color:var(--muted);transition:transform .2s;flex-shrink:0}
.sb-chevron.open{transform:rotate(180deg)}
.sb-user-menu{position:absolute;bottom:calc(100% + .3rem);left:.7rem;right:.7rem;background:#fff;border:1px solid #ece9f8;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.12);overflow:hidden;display:none;z-index:400}
.sb-user-menu.open{display:block}
.sb-menu-item{display:flex;align-items:center;gap:.6rem;padding:.7rem 1rem;font-size:.85rem;font-weight:500;color:#374151;text-decoration:none;transition:background .15s}
.sb-menu-item i{font-size:.85rem;width:16px;text-align:center;color:var(--purple-mid)}
.sb-menu-item:hover{background:#f9fafb}
.sb-menu-item.danger{color:#ef4444}
.sb-menu-item.danger i{color:#ef4444}
.sb-menu-item.danger:hover{background:#fef2f2}
.sb-menu-divider{height:1px;background:#f0ecff}
.dash-topbar{display:none;align-items:center;justify-content:space-between;height:56px;padding:0 1rem;background:#fff;border-bottom:1px solid #ece9f8;position:fixed;top:0;left:0;right:0;z-index:250;box-shadow:0 2px 10px rgba(0,0,0,.06)}
.dash-topbar-brand{display:flex;align-items:center;gap:.5rem;font-family:var(--ff);font-size:.9rem;font-weight:800;color:var(--text)}
.sb-toggle{background:none;border:none;font-size:1.1rem;color:var(--text);cursor:pointer;padding:.4rem;border-radius:8px;transition:background .15s}
.sb-toggle:hover{background:#f3f0ff}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:290}
.dash-main{flex:1;min-width:0;padding:1.8rem 2rem;overflow-y:auto}
.dash-page{display:none}
.dash-page.active{display:block;animation:fadeUp .3s ease both}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-top:1rem;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem}
.page-title{font-family:var(--ff);font-size:1.55rem;font-weight:800;color:var(--text);letter-spacing:-.025em;line-height:1.2}
.page-sub{color:var(--muted);font-size:.875rem;margin-top:.2rem}
.page-actions{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}

/* Export Dropdown */
.export-drop-wrap{position:relative;display:inline-block}
.export-drop-menu{display:none;position:absolute;top:calc(100% + 6px);right:0;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 10px 32px rgba(0,0,0,.14);min-width:240px;z-index:500;overflow:hidden;animation:fadeUp .18s ease both}
.export-drop-menu.open{display:block}
.export-drop-header{padding:.6rem 1rem .45rem;font-size:.7rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;border-bottom:1px solid #f0ecff}
.export-drop-item{display:flex;align-items:center;gap:.75rem;padding:.65rem 1rem;text-decoration:none;color:var(--text);transition:background .14s;cursor:pointer}
.export-drop-item:hover{background:#faf9ff}
.export-drop-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.export-drop-icon.csv{background:#e0fde4;color:#16a34a}
.export-drop-icon.xlsx{background:#e0f2fe;color:#0369a1}
.export-drop-icon.pdf{background:#fee2e2;color:#dc2626}
.export-drop-info{display:flex;flex-direction:column;gap:.08rem}
.export-drop-info strong{font-size:.83rem;font-weight:700;color:var(--text)}
.export-drop-info span{font-size:.72rem;color:var(--muted)}

/* Welcome */
.welcome-banner{background:linear-gradient(135deg,var(--purple-dark) 0%,var(--purple-mid) 60%,var(--purple-light) 100%);border-radius:18px;padding:1.8rem 2rem;display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;position:relative;overflow:hidden;box-shadow:0 8px 32px rgba(108,63,207,.35)}
.welcome-banner::after{content:'';position:absolute;right:-40px;top:-40px;width:200px;height:200px;background:rgba(255,255,255,.05);border-radius:50%;pointer-events:none}
.welcome-eyebrow{font-size:.82rem;color:rgba(255,255,255,.75);font-weight:600;margin-bottom:.3rem}
.welcome-title{font-family:var(--ff);font-size:1.6rem;font-weight:800;color:#fff;letter-spacing:-.025em;line-height:1.2}
.welcome-title span{color:#f5c518}
.welcome-sub{font-size:.86rem;color:rgba(255,255,255,.7);margin-top:.4rem}
.welcome-badge{display:flex;flex-direction:column;align-items:flex-end;gap:.35rem;z-index:1}
.badge-course{background:#f5c518;color:#1a1a00;font-family:var(--ff);font-size:.82rem;font-weight:800;padding:.3rem .8rem;border-radius:8px;letter-spacing:.05em}
.badge-id{font-size:.8rem;color:rgba(255,255,255,.7);font-family:monospace;letter-spacing:.1em}

/* Stat cards */
.stat-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem}
.stat-card{border-radius:14px;padding:1.2rem 1.3rem;display:flex;align-items:center;gap:1rem;position:relative;overflow:hidden;transition:transform .2s}
.stat-card:hover{transform:translateY(-2px)}
.stat-card.purple{background:linear-gradient(135deg,#f3f0ff,#ede9fe);border:1px solid #ddd6fe}
.stat-card.yellow{background:linear-gradient(135deg,#fffbeb,#fef9c3);border:1px solid #fde68a}
.stat-card.green{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #bbf7d0}
.stat-card.pink{background:linear-gradient(135deg,#fdf2f8,#fce7f3);border:1px solid #f9a8d4}
.sc-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.stat-card.purple .sc-icon{background:rgba(108,63,207,.15);color:var(--purple-mid)}
.stat-card.yellow .sc-icon{background:rgba(245,197,24,.2);color:#d97706}
.stat-card.green .sc-icon{background:rgba(34,197,94,.15);color:#16a34a}
.stat-card.pink .sc-icon{background:rgba(217,70,239,.15);color:#c026d3}
.sc-value{font-family:var(--ff);font-size:1.7rem;font-weight:800;color:var(--text);line-height:1}
.sc-label{font-size:.74rem;color:var(--muted);font-weight:600;margin-top:.2rem}
.sc-bg-icon{position:absolute;right:-5px;top:50%;transform:translateY(-50%);font-size:3.5rem;opacity:.05;pointer-events:none;color:var(--text)}
.home-grid{display:grid;grid-template-columns:1.1fr 0.9fr;gap:1.25rem}

/* Cards */
.dash-card{background:#fff;border-radius:16px;padding:1.3rem 1.5rem;box-shadow:0 2px 12px rgba(0,0,0,.05);border:1px solid #ece9f8}
.dash-card.no-pad{padding:0}
.dash-card.no-pad .card-header{padding:1.1rem 1.5rem}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem}
.card-header h2{font-family:var(--ff);font-size:.95rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:.5rem}
.card-header h2 i{color:var(--purple-mid);font-size:.88rem}
.card-badge{background:#f5c518;color:#1a1a00;font-size:.68rem;font-weight:800;padding:.18rem .55rem;border-radius:6px;letter-spacing:.03em}

/* Software display */
.software-list{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.5rem}
.software-chip{background:#ede9fe;color:var(--purple-mid);font-size:.7rem;font-weight:600;padding:.2rem .6rem;border-radius:12px;letter-spacing:.02em}
.software-chip i{font-size:.65rem;margin-right:.2rem}
.software-section{margin-top:.75rem;padding-top:.5rem;border-top:1px solid #f0ecff}
.software-section-title{font-size:.72rem;font-weight:700;color:#374151;margin-bottom:.35rem;display:flex;align-items:center;gap:.4rem}

/* Points Chip */
.points-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.28rem .65rem .28rem .28rem;border-radius:20px;background:transparent;border:1.5px solid rgba(108,63,207,.18);cursor:default;transition:background .15s}
.points-chip:hover{background:rgba(108,63,207,.06)}
.points-chip-icon{width:20px;height:20px;border-radius:50%;background:rgba(108,63,207,.12);display:flex;align-items:center;justify-content:center;font-size:.58rem;color:var(--purple-mid);flex-shrink:0}
.points-chip-val{font-family:var(--ff);font-size:.8rem;font-weight:800;color:var(--purple-mid);line-height:1}
.points-chip-lbl{font-size:.65rem;font-weight:600;color:var(--purple-mid);opacity:.7;line-height:1}

/* Notification Bell */
.notif-bell-wrap{position:relative;display:inline-flex;align-items:center}
.notif-bell-btn{background:none;border:none;cursor:pointer;font-size:1.15rem;color:var(--muted);padding:.3rem .45rem;border-radius:9px;transition:background .15s,color .15s;position:relative}
.notif-bell-btn:hover{background:#f3f0ff;color:var(--purple-mid)}
.notif-badge{position:absolute;top:-2px;right:-2px;background:#ef4444;color:#fff;font-size:.6rem;font-weight:800;min-width:16px;height:16px;border-radius:999px;display:flex;align-items:center;justify-content:center;padding:0 3px;border:2px solid #fff;line-height:1;animation:notifPop .3s cubic-bezier(.34,1.56,.64,1) both}
@keyframes notifPop{from{transform:scale(0);opacity:0}to{transform:scale(1);opacity:1}}
.notif-panel{position:fixed;width:340px;background:#fff;border:1px solid #ece9f8;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.14);z-index:700;display:none;animation:fadeUp .2s ease both}
.notif-panel.open{display:block}
.notif-panel-header{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.1rem .6rem;border-bottom:1px solid #f4f2fb}
.notif-panel-title{font-family:var(--ff);font-size:.88rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:.45rem}
.notif-panel-title i{color:var(--purple-mid)}
.notif-mark-all{font-size:.75rem;font-weight:600;color:var(--purple-mid);background:none;border:none;cursor:pointer;padding:.2rem .4rem;border-radius:6px;transition:background .15s}
.notif-mark-all:hover{background:#f3f0ff}
.notif-list{max-height:340px;overflow-y:auto;padding:.35rem 0}
.notif-item{display:flex;gap:.7rem;padding:.7rem 1.1rem;cursor:pointer;transition:background .15s;border-left:3px solid transparent}
.notif-item:hover{background:#faf9ff}
.notif-item.unread{background:#f9f7ff;border-left-color:var(--purple-mid)}
.notif-item-icon{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#ede9fe,#f3f0ff);color:var(--purple-mid);display:flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0;margin-top:.1rem}
.notif-item.unread .notif-item-icon{background:linear-gradient(135deg,var(--purple-mid),var(--purple-light));color:#fff}
.notif-item-body{flex:1;min-width:0}
.notif-item-title{font-size:.82rem;font-weight:700;color:var(--text);line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.notif-item.unread .notif-item-title{color:var(--purple-mid)}
.notif-item-desc{font-size:.75rem;color:var(--muted);line-height:1.45;margin-top:.1rem;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.notif-item-time{font-size:.68rem;color:#9ca3af;display:block;margin-top:.2rem}
.notif-empty{text-align:center;padding:2rem 1rem;color:var(--muted);font-size:.84rem}
.notif-empty i{font-size:1.8rem;color:#d1d5db;display:block;margin-bottom:.5rem}
/* Announcement full view modal */
.ann-modal-card{max-width:560px}
.ann-modal-content{padding:.4rem 0 .2rem;font-size:.9rem;color:var(--text);line-height:1.7}
.ann-modal-meta{display:flex;align-items:center;gap:.6rem;font-size:.78rem;color:var(--muted);margin-bottom:.75rem;flex-wrap:wrap}
.ann-modal-meta i{color:var(--purple-mid)}

/* Announcements */
.announcement-list{list-style:none;display:flex;flex-direction:column;gap:.3rem;max-height:340px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#ddd6fe transparent}
.announcement-list::-webkit-scrollbar{width:4px}
.announcement-list::-webkit-scrollbar-track{background:transparent}
.announcement-list::-webkit-scrollbar-thumb{background:#ddd6fe;border-radius:99px}
.ann-item{display:flex;gap:.75rem;padding:.7rem .8rem;border-radius:10px;transition:background .15s;cursor:pointer}
.ann-item:hover{background:#fafafa}
.ann-item.unread{background:#f9f7ff}
.ann-dot{width:8px;height:8px;border-radius:50%;background:var(--purple-mid);flex-shrink:0;margin-top:.35rem;box-shadow:0 0 0 2px rgba(108,63,207,.2)}
.ann-dot.read{background:#d1d5db;box-shadow:none}
.ann-title{font-size:.85rem;font-weight:700;color:var(--text);line-height:1.3}
.ann-desc{font-size:.78rem;color:var(--muted);line-height:1.5;margin-top:.15rem;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.ann-time{font-size:.7rem;color:#9ca3af;display:block;margin-top:.25rem}

/* Rules */
.rules-list{display:flex;flex-direction:column;gap:.55rem;max-height:300px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#ddd6fe transparent}
.rule-item{display:flex;gap:.5rem;font-size:.82rem;color:var(--text);line-height:1.5}
.rule-number{font-weight:700;color:var(--purple-mid);flex-shrink:0;min-width:18px}
.centered-header{text-align:center;font-size:.78rem;color:var(--muted);margin-bottom:.85rem;line-height:1.7}

/* History */
.history-summary{display:flex;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap}
.hs-item{flex:1;min-width:150px;background:#fff;border-radius:14px;padding:.9rem 1.1rem;display:flex;align-items:center;gap:.85rem;border:1px solid #ece9f8;box-shadow:0 1px 6px rgba(0,0,0,.04)}
.hs-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.hs-val{font-family:var(--ff);font-size:1.15rem;font-weight:800;color:var(--text);display:block;line-height:1.1}
.hs-lbl{font-size:.68rem;color:var(--muted);font-weight:600;display:block;margin-top:.15rem}
.search-wrap{position:relative;display:flex;align-items:center}
.search-wrap i{position:absolute;left:.75rem;color:#9ca3af;font-size:.82rem;pointer-events:none}
.search-input{padding:.5rem .9rem .5rem 2.2rem;border:1.5px solid var(--border);border-radius:9px;font-family:var(--fb);font-size:.84rem;color:var(--text);background:#fafafa;outline:none;width:200px;transition:border-color .2s,box-shadow .2s}
.search-input:focus{border-color:var(--purple-mid);box-shadow:0 0 0 3px rgba(108,63,207,.1);background:#fff;width:240px}
.table-wrap{overflow-x:auto}
.history-table{width:100%;border-collapse:collapse;font-size:.84rem}
.history-table thead tr{background:#fafafa;border-bottom:2px solid #ece9f8}
.history-table th{padding:.75rem 1rem;text-align:left;font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}
.history-table td{padding:.75rem 1rem;border-bottom:1px solid #f4f2fb;color:var(--text);white-space:nowrap}
.history-table tbody tr:hover{background:#faf9ff}
.history-table tbody tr:last-child td{border-bottom:none}
.td-num{color:var(--muted);font-size:.8rem}
.lab-tag{background:#ede9fe;color:var(--purple-mid);font-size:.74rem;font-weight:700;padding:.2rem .55rem;border-radius:6px}
.purpose-tag{background:#fef9c3;color:#92400e;font-size:.74rem;font-weight:600;padding:.2rem .55rem;border-radius:6px}
.status-badge{font-size:.72rem;font-weight:700;padding:.22rem .6rem;border-radius:6px;letter-spacing:.03em}
.status-badge.completed{background:#dcfce7;color:#15803d}
.status-badge.cancelled{background:#fee2e2;color:#b91c1c}
.status-badge.pending{background:#fef9c3;color:#92400e}
.table-footer{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.5rem;border-top:1px solid #f4f2fb}
.tf-count{font-size:.8rem;color:var(--muted)}
.pagination{display:flex;gap:.3rem}
.pg-btn{width:32px;height:32px;border-radius:8px;border:1.5px solid var(--border);background:#fff;font-size:.8rem;font-weight:600;color:var(--muted);cursor:pointer;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:all .15s}
.pg-btn:hover{border-color:var(--purple-mid);color:var(--purple-mid);background:#f3f0ff}
.pg-btn.active{background:var(--purple-mid);color:#fff;border-color:var(--purple-mid)}

/* Feedback btn */
.btn-feedback{display:inline-flex;align-items:center;gap:.35rem;padding:.28rem .75rem;border-radius:7px;border:none;background:#22c55e;color:#fff;font-size:.76rem;font-weight:700;cursor:pointer;font-family:var(--fb);transition:all .15s}
.btn-feedback:hover{background:#16a34a;transform:translateY(-1px)}
.btn-feedback.done{background:#d1d5db;color:#6b7280;cursor:not-allowed}

/* Reservation */
.reservation-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.1rem;margin-bottom:0}
.reserve-card{background:#fff;border-radius:16px;border:1.5px solid #ece9f8;padding:1.2rem;box-shadow:0 2px 10px rgba(0,0,0,.04);display:flex;flex-direction:column;gap:.8rem}
.active-reserve{border-color:#c4b5fd}
.pending-reserve{border-color:#fde68a}
.done-reserve{border-color:#d1d5db;opacity:.82}
.rc-header{display:flex;align-items:center;justify-content:space-between}
.rc-status{display:flex;align-items:center;gap:.4rem;font-size:.74rem;font-weight:700;padding:.2rem .6rem;border-radius:20px}
.rc-status.active{background:#dcfce7;color:#15803d}
.rc-status.pending{background:#fef9c3;color:#92400e}
.rc-status.done{background:#ede9fe;color:#6c3fcf}
.rc-status.cancelled{background:#f3f4f6;color:#6b7280}
.rc-id{font-size:.74rem;color:var(--muted);font-family:monospace}
.rc-lab{display:flex;align-items:center;gap:.5rem;font-family:var(--ff);font-size:.92rem;font-weight:800;color:var(--text)}
.rc-lab i{color:var(--purple-mid)}
.rc-details{display:flex;flex-direction:column;gap:.4rem}
.rc-detail{display:flex;align-items:center;gap:.55rem;font-size:.82rem;color:#4b5563}
.rc-detail i{color:var(--purple-light);width:14px;text-align:center}
.rc-footer{display:flex;gap:.6rem;margin-top:auto}
.new-reserve-cta{align-items:center;justify-content:center;text-align:center;border:2px dashed #ddd6fe;background:#faf8ff;cursor:pointer;transition:all .2s;padding:2rem 1.2rem;gap:.6rem}
.new-reserve-cta:hover{border-color:var(--purple-mid);background:#f3f0ff}
.new-reserve-cta i{font-size:2rem;color:#c4b5fd}
.new-reserve-cta p{font-family:var(--ff);font-size:.95rem;font-weight:800;color:var(--purple-mid)}
.new-reserve-cta span{font-size:.78rem;color:var(--muted)}

/* Profile */
.profile-layout{display:grid;grid-template-columns:280px 1fr;gap:1.25rem;align-items:flex-start}
.profile-avatar-card{background:#fff;border-radius:20px;border:1px solid #ece9f8;padding:1.8rem 1.4rem 1.4rem;display:flex;flex-direction:column;align-items:center;text-align:center;gap:.55rem;box-shadow:0 2px 16px rgba(108,63,207,.07);position:sticky;top:1.8rem}
.pav-photo-wrap{position:relative;width:96px;height:96px;margin-bottom:.2rem}
.pav-photo-img{width:96px;height:96px;border-radius:22px;object-fit:cover;border:3px solid #ede9fe;box-shadow:0 4px 18px rgba(108,63,207,.22);display:none}
.pav-photo-img.visible{display:block}
.pav-photo-initials{width:96px;height:96px;border-radius:22px;background:linear-gradient(135deg,var(--purple-dark),var(--purple-mid));color:#fff;font-family:var(--ff);font-size:2.2rem;font-weight:800;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 24px rgba(108,63,207,.38)}
.pav-photo-initials.hidden{display:none}
.pav-camera-btn{position:absolute;bottom:-6px;right:-6px;width:30px;height:30px;border-radius:9px;background:var(--purple-mid);color:#fff;border:2.5px solid #fff;font-size:.72rem;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(108,63,207,.4);transition:background .15s,transform .15s}
.pav-camera-btn:hover{background:var(--purple-dark);transform:scale(1.1)}
.pav-upload-zone{width:100%;border:1.5px dashed #c4b5fd;border-radius:12px;padding:.6rem .5rem;display:flex;flex-direction:column;align-items:center;gap:.25rem;cursor:pointer;transition:all .18s;background:transparent;margin-top:.2rem}
.pav-upload-zone:hover{background:#f5f3ff;border-color:var(--purple-mid)}
.pav-upload-label{font-size:.75rem;font-weight:700;color:var(--purple-mid);display:flex;align-items:center;gap:.35rem;cursor:pointer}
.pav-upload-hint{font-size:.68rem;color:#9ca3af}
.pav-remove-btn{font-size:.72rem;font-weight:600;color:var(--red);background:none;border:none;cursor:pointer;display:none;align-items:center;gap:.3rem;padding:.2rem .5rem;border-radius:6px;transition:background .15s;margin-top:.15rem}
.pav-remove-btn.visible{display:inline-flex}
.pav-remove-btn:hover{background:#fee2e2}
#profilePhotoInput{display:none}
.pav-name{font-family:var(--ff);font-size:1.02rem;font-weight:800;color:var(--text);line-height:1.2;margin-top:.15rem}
.pav-course{font-size:.78rem;color:var(--muted);font-weight:600}
.pav-tags{display:flex;flex-direction:column;gap:.3rem;width:100%;margin-top:.1rem}
.ptag{background:#f3f0ff;color:var(--purple-mid);font-size:.75rem;font-weight:700;padding:.32rem .7rem;border-radius:8px;display:flex;align-items:center;gap:.4rem;justify-content:center}
.pav-sessions{display:flex;align-items:center;gap:1rem;background:linear-gradient(135deg,#ede9fe,#f5f3ff);border-radius:13px;padding:.8rem 1rem;margin-top:.2rem;width:100%}
.psi-item{flex:1;text-align:center}
.psi-val{font-family:var(--ff);font-size:1.3rem;font-weight:800;color:var(--purple-mid);display:block}
.psi-lbl{font-size:.68rem;color:var(--muted);font-weight:600;display:block;margin-top:.1rem}
.psi-divider{width:1px;height:32px;background:#ddd6fe}
.pav-info-list{width:100%;display:flex;flex-direction:column;gap:.3rem;margin-top:.1rem}
.pav-info-item{display:flex;align-items:flex-start;gap:.5rem;font-size:.77rem;color:#4b5563;text-align:left;line-height:1.4}
.pav-info-item i{color:var(--purple-mid);font-size:.72rem;flex-shrink:0;margin-top:.15rem;width:14px;text-align:center}
.profile-save-status{font-size:.8rem;font-weight:600;display:none;align-items:center;gap:.35rem}
.profile-save-status.success{color:#16a34a;display:flex}
.profile-save-status.error{color:#ef4444;display:flex}
.profile-form-card{background:#fff;border-radius:20px;border:1px solid #ece9f8;padding:1.5rem 1.7rem;box-shadow:0 2px 16px rgba(0,0,0,.05)}
.profile-section-title{display:flex;align-items:center;gap:.5rem;font-family:var(--ff);font-size:.84rem;font-weight:800;color:var(--text);margin-top:.3rem;padding-top:1rem;border-top:1px solid #ece9f8}
.profile-section-title i{color:var(--purple-mid)}
.section-opt{font-weight:400;color:var(--muted);font-size:.78rem;font-family:var(--fb)}
.input-disabled{background:#f9fafb!important;color:var(--muted)!important;cursor:not-allowed}

/* Buttons */
.btn-outline-sm{display:inline-flex;align-items:center;gap:.4rem;padding:.42rem .9rem;border-radius:8px;border:1.5px solid var(--border);background:#fff;font-size:.8rem;font-weight:600;color:#4b5563;cursor:pointer;text-decoration:none;transition:all .15s;font-family:var(--fb)}
.btn-outline-sm:hover{border-color:var(--purple-mid);color:var(--purple-mid);background:#f3f0ff}
.btn-outline-sm.danger:hover{border-color:var(--red);color:var(--red);background:#fef2f2}
.btn-primary-sm{display:inline-flex;align-items:center;gap:.45rem;padding:.5rem 1.1rem;border-radius:9px;border:none;background:linear-gradient(135deg,var(--purple-mid),var(--purple-light));color:#fff;font-size:.84rem;font-weight:700;cursor:pointer;transition:all .18s;font-family:var(--fb);box-shadow:0 3px 12px rgba(108,63,207,.3)}
.btn-primary-sm:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(108,63,207,.4)}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:500;display:none;align-items:center;justify-content:center;padding:1rem;backdrop-filter:blur(2px)}
.modal-overlay.open{display:flex}
.modal-card{background:#fff;border-radius:20px;padding:1.8rem;width:100%;max-width:520px;box-shadow:0 24px 64px rgba(0,0,0,.2);animation:fadeUp .25s ease both}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.3rem}
.modal-header h3{font-family:var(--ff);font-size:1.1rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:.5rem}
.modal-header h3 i{color:var(--purple-mid)}
.modal-close{width:32px;height:32px;border-radius:8px;border:none;background:#f3f4f6;color:var(--muted);font-size:.9rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
.modal-close:hover{background:#fee2e2;color:var(--red)}

/* Star rating */
.star-rating{display:flex;gap:.3rem;margin:.4rem 0}
.star-rating i{font-size:1.4rem;color:#d1d5db;cursor:pointer;transition:color .15s}
.star-rating i.active,.star-rating i:hover,.star-rating i.hover{color:#f5c518}
.feedback-textarea{width:100%;padding:.65rem .9rem;border:1.5px solid var(--border);border-radius:10px;font-family:var(--fb);font-size:.875rem;color:var(--text);resize:vertical;min-height:80px;outline:none;transition:border-color .2s}
.feedback-textarea:focus{border-color:var(--purple-mid);box-shadow:0 0 0 3px rgba(108,63,207,.1)}

/* Content Topbar */
.dash-content-topbar{display:flex;align-items:center;justify-content:flex-end;gap:1.25rem;margin:-1.8rem -2rem 2.5rem;padding:.45rem 2.5rem .45rem 2rem;background:#f4f2fb;border-bottom:none;position:sticky;top:0;z-index:200;box-shadow:none}
.dct-right{display:flex;align-items:center;gap:.5rem}
.dct-profile-chip{display:flex;align-items:center;gap:.55rem;padding:.3rem .5rem .3rem .3rem;border-radius:10px;cursor:pointer;transition:background .18s;border:none;background:transparent;position:relative}
.dct-profile-chip:hover{background:rgba(108,63,207,.07)}
.dct-chip-avatar{width:32px;height:32px;border-radius:8px;object-fit:cover;flex-shrink:0;display:block}
.dct-chip-initials{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--purple-mid),var(--purple-light));color:#fff;font-family:var(--ff);font-size:.82rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dct-chip-name{font-size:.82rem;font-weight:600;color:var(--text);white-space:nowrap;max-width:140px;overflow:hidden;text-overflow:ellipsis}
.dct-chip-chevron{font-size:.6rem;color:var(--muted);transition:transform .2s;flex-shrink:0}
.dct-chip-chevron.open{transform:rotate(180deg)}
.dct-profile-dropdown{display:none;position:absolute;top:calc(100% + 6px);right:0;background:#fff;border:1px solid #ece9f8;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.12);overflow:hidden;min-width:170px;z-index:500}
.dct-profile-dropdown.open{display:block;animation:fadeUp .15s ease both}
.dct-dd-item{display:flex;align-items:center;gap:.6rem;padding:.7rem 1rem;font-size:.85rem;font-weight:500;color:#374151;text-decoration:none;cursor:pointer;transition:background .15s;border:none;background:none;width:100%}
.dct-dd-item i{font-size:.82rem;width:16px;text-align:center;color:var(--purple-mid)}
.dct-dd-item:hover{background:#f9fafb}
.dct-dd-item.danger{color:#ef4444}
.dct-dd-item.danger i{color:#ef4444}
.dct-dd-item.danger:hover{background:#fef2f2}
.dct-dd-divider{height:1px;background:#f0ecff}

/* Sessions Table */
.sessions-status-badge{display:inline-flex;align-items:center;gap:.3rem;font-size:.7rem;font-weight:700;padding:.2rem .55rem;border-radius:6px}
.sess-status-done{background:#dcfce7;color:#15803d}
.sess-status-active{background:#fee2e2;color:#b91c1c}
.dur-badge{display:inline-flex;align-items:center;gap:.3rem;background:#f3f0ff;color:var(--purple-mid);font-size:.74rem;font-weight:700;padding:.22rem .6rem;border-radius:7px;white-space:nowrap}
.dur-badge.long{background:#dcfce7;color:#15803d}
.dur-badge.short{background:#fef9c3;color:#92400e}

/* Responsive */
@media(max-width:1100px){.stat-cards{grid-template-columns:repeat(2,1fr)};.reservation-grid{grid-template-columns:repeat(2,1fr)};.profile-layout{grid-template-columns:1fr}}
@media(max-width:900px){.home-grid{grid-template-columns:1fr}}
@media(max-width:768px){
  .dash-topbar{display:flex}
  .dash-content-topbar{display:none}
  .sidebar{position:fixed;left:0;top:0;bottom:0;transform:translateX(-100%);z-index:310}
  .sidebar.open{transform:translateX(0)}
  .sidebar-overlay{display:block;opacity:0;pointer-events:none;transition:opacity .25s}
  .sidebar-overlay.open{opacity:1;pointer-events:auto}
  .dash-main{padding:4.5rem 1rem 2rem}
  .reservation-grid{grid-template-columns:1fr}
  .stat-cards{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:500px){.stat-cards{grid-template-columns:1fr 1fr};.history-summary{flex-direction:column};.welcome-title{font-size:1.25rem}}
  </style>
</head>
<body class="dash-body">

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
  <div class="sb-brand">
    <img src="images/ccslogo.png" alt="CCS Logo" class="sb-logo"/>
    <div class="sb-brand-text">
      <span class="sb-title">CCS Sit-in System</span>
      <span class="sb-sub">College of Computer Studies</span>
    </div>
  </div>
  <nav class="sb-nav">
    <ul>
      <li><a href="#" class="sb-link" data-page="home"><span class="sb-icon"><i class="fa-solid fa-house"></i></span><span class="sb-label">Home</span></a></li>
      <li><a href="#" class="sb-link" data-page="history"><span class="sb-icon"><i class="fa-solid fa-clock-rotate-left"></i></span><span class="sb-label">History</span></a></li>
      <li><a href="#" class="sb-link" data-page="reservation"><span class="sb-icon"><i class="fa-solid fa-calendar-plus"></i></span><span class="sb-label">Reservation</span></a></li>
      <li><a href="#" class="sb-link" data-page="sessions"><span class="sb-icon"><i class="fa-solid fa-table-list"></i></span><span class="sb-label">Sessions</span></a></li>
      <li><a href="#" class="sb-link" data-page="resources"><span class="sb-icon"><i class="fa-solid fa-file-pdf"></i></span><span class="sb-label">Resources</span></a></li>
    </ul>
  </nav>
  <div class="sb-spacer"></div>
</aside>

<!-- ── MOBILE TOPBAR ── -->
<header class="dash-topbar" id="dashTopbar">
  <button class="sb-toggle" id="sbToggle"><i class="fa-solid fa-bars"></i></button>
  <div class="dash-topbar-brand">
    <img src="images/ccslogo.png" alt="Logo" style="width:30px;height:30px;object-fit:contain"/>
    <span>CCS Portal</span>
  </div>
  <div style="display:flex;align-items:center;gap:.85rem">
    <div class="notif-bell-wrap" id="mobileNotifWrap">
      <button class="notif-bell-btn" id="mobileNotifBtn" title="Notifications">
        <i class="fa-solid fa-bell"></i>
        <?php if($unread_count>0):?><span class="notif-badge" id="mobileNotifBadge"><?= $unread_count ?></span><?php else:?><span class="notif-badge" id="mobileNotifBadge" style="display:none">0</span><?php endif;?>
      </button>
    </div>
    <div class="points-chip" style="padding:.2rem .5rem .2rem .25rem" title="My Points">
      <div class="points-chip-icon" style="width:18px;height:18px;font-size:.55rem"><i class="fa-solid fa-star"></i></div>
      <span class="points-chip-val" style="font-size:.72rem" id="pointsChipValMobile"><?= $student_score ?></span>
    </div>
    <div class="sb-avatar sm"><?= strtoupper(substr($first_name,0,1)) ?></div>
  </div>
</header>

<!-- ── MAIN ── -->
<main class="dash-main" id="dashMain">

<!-- ── CONTENT TOPBAR ── -->
<div class="dash-content-topbar">
  <div class="dct-right" style="margin-left:auto">
    <!-- Notification Bell -->
    <div class="notif-bell-wrap" id="desktopNotifWrap">
      <button class="notif-bell-btn" id="desktopNotifBtn" title="Notifications">
        <i class="fa-solid fa-bell"></i>
        <?php if($unread_count>0):?>
          <span class="notif-badge" id="desktopNotifBadge"><?= $unread_count ?></span>
        <?php else:?>
          <span class="notif-badge" id="desktopNotifBadge" style="display:none">0</span>
        <?php endif;?>
      </button>
    </div>
    <!-- Points Chip -->
    <div class="points-chip" id="pointsChip" title="My Points">
      <div class="points-chip-icon"><i class="fa-solid fa-star"></i></div>
      <div style="display:flex;flex-direction:column;gap:.05rem">
        <span class="points-chip-val" id="pointsChipVal"><?= $student_score ?></span>
        <span class="points-chip-lbl">pts</span>
      </div>
    </div>
    <!-- Profile Chip -->
    <div class="dct-profile-chip" id="dctProfileChip">
      <?php if($photo_exists): ?>
        <img src="<?= htmlspecialchars($profile_photo) ?>" class="dct-chip-avatar" id="dctChipAvatar" alt="avatar"/>
      <?php else: ?>
        <div class="dct-chip-initials" id="dctChipAvatar"><?= strtoupper(substr($first_name,0,1)) ?></div>
      <?php endif; ?>
      <span class="dct-chip-name" id="dctChipName"><?= htmlspecialchars($first_name) ?></span>
      <i class="fa-solid fa-chevron-down dct-chip-chevron" id="dctChipChevron"></i>
      <div class="dct-profile-dropdown" id="dctProfileDropdown">
        <button class="dct-dd-item" id="dctGoProfile"><i class="fa-solid fa-user-pen"></i> Edit Profile</button>
        <div class="dct-dd-divider"></div>
        <a href="index.php" class="dct-dd-item danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
      </div>
    </div>
  </div>
</div>

<!-- Notification Panel (body-level for correct stacking) -->
<div class="notif-panel" id="notifPanel">
  <div class="notif-panel-header">
    <span class="notif-panel-title"><i class="fa-solid fa-bell"></i> Notifications</span>
    <button class="notif-mark-all" id="markAllReadBtn">Mark all read</button>
  </div>
  <div class="notif-list" id="notifList">
    <!-- Reservation notifications injected by JS above announcements -->
    <div id="rsvNotifSection"></div>
    <?php
    $notif_anns = [];
    try {
      $notif_anns = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $_e){}
    if(empty($notif_anns)):?>
    <div class="notif-empty" id="annNotifEmpty"><i class="fa-regular fa-bell-slash"></i>No notifications yet.</div>
    <?php else: foreach($notif_anns as $na): $isUnread = in_array($na['id'], $unread_ann_ids); ?>
    <div class="notif-item <?= $isUnread?'unread':'' ?>" data-ann-id="<?= $na['id'] ?>"
         onclick="openAnnouncementModal(<?= $na['id'] ?>, <?= htmlspecialchars(json_encode($na['admin_name']??'CCS Admin')) ?>, <?= htmlspecialchars(json_encode($na['content'])) ?>, '<?= date('M d, Y \a\t g:i A', strtotime($na['created_at'])) ?>')">
      <div class="notif-item-icon"><i class="fa-solid fa-bullhorn"></i></div>
      <div class="notif-item-body">
        <div class="notif-item-title"><?= htmlspecialchars($na['admin_name']??'CCS Admin') ?></div>
        <div class="notif-item-desc"><?= htmlspecialchars($na['content']) ?></div>
        <span class="notif-item-time"><?= date('M d, Y · g:i A', strtotime($na['created_at'])) ?></span>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

  <!-- ══ HOME ══ -->
  <div class="dash-page" id="page-home">
    <div class="welcome-banner">
      <div class="welcome-text">
        <p class="welcome-eyebrow">Good day!</p>
        <h1 class="welcome-title">Welcome back, <span><?= htmlspecialchars($first_name) ?>!</span></h1>
        <p class="welcome-sub">Here's an overview of your sit-in activity.</p>
      </div>
      <div class="welcome-badge">
        <div class="badge-course"><?= htmlspecialchars($course) ?></div>
        <div class="badge-id"><?= htmlspecialchars($id_number) ?></div>
    </div>
    </div>

    <div class="stat-cards">
      <div class="stat-card purple">
        <div class="sc-icon"><i class="fa-solid fa-clock"></i></div>
        <div class="sc-body"><div class="sc-value"><?= $sessions_left ?></div><div class="sc-label">Sessions Remaining</div></div>
        <div class="sc-bg-icon"><i class="fa-solid fa-clock"></i></div>
      </div>
      <div class="stat-card green">
        <div class="sc-icon"><i class="fa-solid fa-circle-check"></i></div>
        <div class="sc-body"><div class="sc-value"><?= $sessions_used ?></div><div class="sc-label">Sessions Used</div></div>
        <div class="sc-bg-icon"><i class="fa-solid fa-circle-check"></i></div>
      </div>
      <div class="stat-card yellow">
        <div class="sc-icon"><i class="fa-solid fa-desktop"></i></div>
        <div class="sc-body"><div class="sc-value"><?= $total_sessions ?? 0 ?></div><div class="sc-label">Total Sit-ins</div></div>
        <div class="sc-bg-icon"><i class="fa-solid fa-desktop"></i></div>
      </div>
      <div class="stat-card pink">
        <div class="sc-icon"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="sc-body"><div class="sc-value" id="activeReservationCount"><?= count($reservations) ?></div><div class="sc-label">Active Reservation</div></div>
        <div class="sc-bg-icon"><i class="fa-solid fa-calendar-check"></i></div>
      </div>
    </div>

    <div class="home-grid">
      <!-- Announcements -->
    <div class="dash-card">
      <div class="card-header">
        <h2><i class="fa-solid fa-bell"></i> Announcements</h2>
      </div>
        <ul class="announcement-list" id="announcementList">
          <?php
          $announcements = [];
          try {
            $announcements = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
          } catch (Exception $_e) {}
          if (empty($announcements)):
          ?>
          <li style="text-align:center;padding:1.5rem;color:var(--muted);font-size:.84rem">No announcements yet.</li>
          <?php else: foreach ($announcements as $ann): $isUnread = in_array($ann['id'], $unread_ann_ids); ?>
          <li class="ann-item <?= $isUnread?'unread':'' ?>" data-ann-id="<?= $ann['id'] ?>" onclick="openAnnouncementModal(<?= $ann['id'] ?>, <?= htmlspecialchars(json_encode($ann['admin_name']??'CCS Admin')) ?>, <?= htmlspecialchars(json_encode($ann['content'])) ?>, '<?= date('M d, Y \a\t g:i A', strtotime($ann['created_at'])) ?>')">
            <div class="ann-dot <?= $isUnread?'':'read' ?>"></div>
            <div class="ann-body">
              <p class="ann-title"><?= htmlspecialchars($ann['admin_name'] ?? 'CCS Admin') ?></p>
              <p class="ann-desc"><?= htmlspecialchars($ann['content']) ?></p>
              <span class="ann-time"><?= date('M d, Y', strtotime($ann['created_at'])) ?></span>
            </div>
          </li>
          <?php endforeach; endif; ?>
        </ul>
      </div>

      <!-- Rules -->
      <div class="dash-card">
        <div class="card-header"><h2><i class="fa-solid fa-clipboard-list"></i> Rules and Regulations</h2></div>
        <div class="centered-header">
          <p>University of Cebu</p>
          <p>College of Information &amp; Computer Studies</p>
        </div>
        <div class="rules-list">
  <div class="rule-item"><span class="rule-text">To maintain a professional, safe, and productive environment for all users of the CCS laboratories, all students are required to strictly observe the following rules and regulations. Failure to comply may result in the suspension of laboratory privileges and further disciplinary action.</span></div>

  <div class="rule-item"><span class="rule-number">1.</span><span class="rule-text">Food, drinks, and any form of snacks are strictly prohibited inside the laboratory at all times. This policy exists to protect the equipment from damage and to uphold cleanliness and sanitation standards within the facility.</span></div>

  <div class="rule-item"><span class="rule-number">2.</span><span class="rule-text">Students must log in and log out properly through the official laboratory management system for every sit-in session. Unauthorized use of any computer without a recorded session is strictly not permitted and may be considered a violation.</span></div>

  <div class="rule-item"><span class="rule-number">3.</span><span class="rule-text">Do not install, download, copy, or run any unauthorized software, games, or applications on the laboratory computers. Modifying system settings, desktop configurations, or any installed programs without explicit permission from the laboratory staff is strictly forbidden.</span></div>

  <div class="rule-item"><span class="rule-number">4.</span><span class="rule-text">Report any hardware malfunction, software error, or physical damage to the laboratory-in-charge immediately upon discovery. Students must never attempt to repair, disassemble, or troubleshoot any equipment on their own without proper authorization.</span></div>

  <div class="rule-item"><span class="rule-number">5.</span><span class="rule-text">Use of the internet must be limited strictly to academic and course-related activities only. Accessing social media platforms, entertainment sites, online gaming, gambling, or any inappropriate and illegal content during laboratory hours is strictly prohibited.</span></div>

  <div class="rule-item"><span class="rule-number">6.</span><span class="rule-text">Keep your workstation clean, organized, and in its original condition before leaving. Ensure that your chair is pushed in, your area is free of clutter, and no personal belongings are left behind after your session.</span></div>

  <div class="rule-item"><span class="rule-number">7.</span><span class="rule-text">Maintain a quiet, respectful, and focused atmosphere inside the laboratory at all times. Loud conversations, disruptive behavior, unnecessary noise, and horseplay are strictly not tolerated as the lab is a shared academic space.</span></div>

  <div class="rule-item"><span class="rule-number">8.</span><span class="rule-text">Students who bring personal USB drives or external storage devices do so entirely at their own risk. The college is not responsible for any data loss or corruption, and all external devices must be scanned for malware before use.</span></div>

  <div class="rule-item"><span class="rule-number">9.</span><span class="rule-text">Tampering with network cables, power cords, computer peripherals, or any lab equipment connections is strictly prohibited. All devices must remain connected to their designated workstations and must not be relocated without permission from the laboratory staff.</span></div>

  <div class="rule-item"><span class="rule-number">10.</span><span class="rule-text">Students are only permitted to use the laboratory during their approved sit-in hours and within their allotted session limit for the semester. Overstaying beyond the approved session time or occupying a workstation without authorization is not allowed.</span></div>

  <div class="rule-item"><span class="rule-number">11.</span><span class="rule-text">Bags and other large personal belongings must be stored in the designated areas or left outside the laboratory. Cluttering the aisles or workstations with personal items is not allowed, and the college is not liable for any loss or theft of unattended belongings.</span></div>

  <div class="rule-item"><span class="rule-number">12.</span><span class="rule-text">Accessing, copying, modifying, or deleting files that belong to another student or to the college is a serious violation of privacy and academic integrity. Students are responsible for managing and deleting their own files before logging out at the end of each session.</span></div>

  <div class="rule-item"><span class="rule-number">13.</span><span class="rule-text">Students must adhere to the University of Cebu dress code when entering and using the laboratory. Wearing slippers, sleeveless shirts, or any attire deemed inappropriate under university policy is not permitted inside the lab premises.</span></div>

  <div class="rule-item"><span class="rule-number">14.</span><span class="rule-text">Any deliberate damage, misuse, or abuse of laboratory equipment including monitors, keyboards, CPUs, and peripherals will be subject to disciplinary action per the University Student Handbook. The student found responsible may also be required to shoulder the full cost of repair or replacement.</span></div>

  <div class="rule-item"><span class="rule-number">15.</span><span class="rule-text">All directives issued by the laboratory-in-charge, faculty members, or any authorized laboratory personnel must be followed promptly and respectfully. Failure or refusal to comply with instructions may result in the immediate termination of the session and additional disciplinary measures as deemed appropriate.</span></div>
</div>
      </div>
    </div>
  </div><!-- /page-home -->


  <!-- ══ HISTORY ══ -->
  <div class="dash-page" id="page-history">
    <div class="page-header">
      <div>
        <h1 class="page-title">History</h1>
        <p class="page-sub">A complete log of your sit-in sessions.</p>
      </div>
      <div class="page-actions">
        <form method="GET" action="dashboard.php" id="historySearchForm" style="display:contents">
          <input type="hidden" name="hpage" value="1"/>
          <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="historySearch" name="hsearch" placeholder="Search lab or purpose…" class="search-input" value="<?= htmlspecialchars($history_search) ?>" autocomplete="off"/>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Sit-in Summary Cards ── -->
    <div class="history-summary">
      <div class="hs-item">
        <div class="hs-icon" style="background:#ede9fe;color:#6c3fcf"><i class="fa-solid fa-clock"></i></div>
        <div>
          <span class="hs-val"><?= $student_hours ?>h</span>
          <span class="hs-lbl">Total Sit-in Hours</span>
        </div>
      </div>
      <div class="hs-item">
        <div class="hs-icon" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-calendar-check"></i></div>
        <div>
          <span class="hs-val"><?= $sum_sessions ?></span>
          <span class="hs-lbl">Number of Sessions</span>
        </div>
      </div>
      <div class="hs-item">
        <div class="hs-icon" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-chart-bar"></i></div>
        <div>
          <span class="hs-val"><?= $avg_duration ?></span>
          <span class="hs-lbl">Average Duration</span>
        </div>
      </div>
      <div class="hs-item">
        <div class="hs-icon" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-fire-flame-curved"></i></div>
        <div>
          <span class="hs-val"><?= $longest_session ?></span>
          <span class="hs-lbl">Longest Session</span>
        </div>
      </div>
    </div>

    <div class="dash-card no-pad">
      <?php if ($history_search): ?>
      <div style="padding:.6rem 1.5rem;background:#f9f7ff;border-bottom:1px solid #ece9f8;font-size:.82rem;color:var(--muted);display:flex;align-items:center;gap:.5rem">
        <i class="fa-solid fa-filter" style="color:var(--purple-mid)"></i>
        Filtering: <strong style="color:var(--text)">"<?= htmlspecialchars($history_search) ?>"</strong>
        <a href="dashboard.php" style="margin-left:auto;color:#ef4444;text-decoration:none;font-weight:700;font-size:.78rem"><i class="fa-solid fa-xmark"></i> Clear</a>
      </div>
      <?php endif; ?>

      <div class="table-wrap">
        <table class="history-table">
          <thead>
            <tr>
              <th>#</th>
              <th>ID Number</th>
              <th>Name</th>
              <th>Sit Purpose</th>
              <th>Laboratory</th>
              <th>Login</th>
              <th>Logout</th>
              <th>Date</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($sitin_history)): ?>
            <tr><td colspan="9" style="text-align:center;padding:2.5rem 1rem;color:var(--muted)">
              <i class="fa-solid fa-inbox" style="font-size:2rem;color:#ddd6fe;display:block;margin-bottom:.6rem"></i>
              <?= $history_search ? 'No sessions match your search.' : 'No completed sit-in sessions yet.' ?>
            </td></tr>
            <?php else: $rn = $history_offset + 1; foreach ($sitin_history as $s):
              $ti_ts = $s['time_in']  ? strtotime($s['time_in'])  : null;
              $to_ts = $s['time_out'] ? strtotime($s['time_out']) : null;
              $already_feedbacked = in_array($s['id'], $feedbacked_sitins);
            ?>
            <tr>
              <td class="td-num"><?= $rn++ ?></td>
              <td><?= htmlspecialchars($id_number) ?></td>
              <td><?= htmlspecialchars($student_name) ?></td>
              <td><span class="purpose-tag"><?= htmlspecialchars($s['purpose'] ?? '—') ?></span></td>
              <td><span class="lab-tag"><?= htmlspecialchars($s['lab']) ?></span></td>
              <td><?= $ti_ts ? date('h:i:sa', $ti_ts) : '—' ?></td>
              <td><?= $to_ts ? date('h:i:sa', $to_ts) : '—' ?></td>
              <td><?= $ti_ts ? date('Y-m-d', $ti_ts) : '—' ?></td>
              <td>
                <?php if ($already_feedbacked): ?>
                  <button class="btn-feedback done" disabled><i class="fa-solid fa-check"></i> Done</button>
                <?php else: ?>
                  <button class="btn-feedback" data-sitin-id="<?= $s['id'] ?>"><i class="fa-solid fa-comment"></i> Feedback</button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="table-footer">
        <span class="tf-count">Showing <strong><?= min($history_offset+$history_per_page,$history_total)-$history_offset ?></strong> of <strong><?= $history_total ?></strong> session<?= $history_total!==1?'s':'' ?></span>
        <?php if ($history_pages > 1): ?>
        <div class="pagination">
          <?php if ($history_page > 1): ?><a class="pg-btn" href="dashboard.php?hpage=<?= $history_page-1 ?><?= $history_search?'&hsearch='.urlencode($history_search):'' ?>"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
          <?php for ($p=1;$p<=$history_pages;$p++): ?><a class="pg-btn <?= $p===$history_page?'active':'' ?>" href="dashboard.php?hpage=<?= $p ?><?= $history_search?'&hsearch='.urlencode($history_search):'' ?>"><?= $p ?></a><?php endfor; ?>
          <?php if ($history_page < $history_pages): ?><a class="pg-btn" href="dashboard.php?hpage=<?= $history_page+1 ?><?= $history_search?'&hsearch='.urlencode($history_search):'' ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div><!-- /page-history -->


  <!-- ══ RESERVATION (Dynamic Container) ══ -->
  <div class="dash-page" id="page-reservation">
    <div class="page-header">
      <div>
        <h1 class="page-title">Reservation</h1>
        <p class="page-sub">Book a computer lab slot in advance.</p>
      </div>
      <?php if ($reservation_enabled): ?>
      <button class="btn-primary-sm" id="openReserveModal"><i class="fa-solid fa-plus"></i> New Reservation</button>
      <?php else: ?>
      <button class="btn-primary-sm" disabled style="background:#e5e7eb;border-color:#e5e7eb;color:#9ca3af;cursor:not-allowed;box-shadow:none;opacity:1"><i class="fa-solid fa-plus"></i> New Reservation</button>
      <?php endif; ?>
    </div>

    <?php if (!$reservation_enabled): ?>
    <div style="display:flex;align-items:center;gap:1.2rem;padding:1.25rem 1.6rem;background:#f9fafb;border:1.5px solid #e5e7eb;border-radius:16px;margin-bottom:1.25rem;box-shadow:0 2px 8px rgba(0,0,0,.04)" id="reservationDisabledBanner">
      <div style="width:46px;height:46px;border-radius:13px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-size:1.15rem;color:#9ca3af;flex-shrink:0">
        <i class="fa-solid fa-calendar-xmark"></i>
      </div>
      <div>
        <div style="font-family:var(--ff);font-size:.95rem;font-weight:800;color:#374151">Reservations Temporarily Unavailable</div>
        <div style="font-size:.81rem;color:#9ca3af;margin-top:.2rem;line-height:1.5">The lab reservation system has been paused by the administrator. Your existing reservations remain active — check back later or contact your lab admin.</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Active Reservations -->
    <div class="reservation-grid" id="reservationsContainer">
      <div style="text-align:center;padding:2rem;color:var(--muted);grid-column:1/-1">
        <i class="fa-solid fa-spinner fa-spin"></i> Loading reservations...
      </div>
    </div>


  </div><!-- /page-reservation -->


  <!-- ══ SESSIONS TABLE ══ -->
  <div class="dash-page" id="page-sessions">
    <div class="page-header">
      <div>
        <h1 class="page-title">Sessions History</h1>
        <p class="page-sub">A detailed record of all your completed sit-in sessions.</p>
      </div>
      <div class="page-actions">
        <div style="display:flex;align-items:center;gap:.5rem">
          <label for="sessionDateFilter" style="font-size:.82rem;font-weight:600;color:var(--muted);white-space:nowrap"><i class="fa-regular fa-calendar" style="color:var(--purple-mid)"></i> Filter by date:</label>
          <input type="date" id="sessionDateFilter" style="padding:.42rem .75rem;border:1.5px solid var(--border);border-radius:9px;font-size:.83rem;font-family:var(--fb);outline:none;color:var(--text);background:#fafafa;cursor:pointer;transition:border-color .2s" />
          <button id="clearSessionFilter" class="btn-outline-sm" style="display:none"><i class="fa-solid fa-xmark"></i> Clear</button>
        </div>
      </div>
    </div>

    <div class="dash-card no-pad">
      <div style="padding:.85rem 1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem;border-bottom:1px solid #f4f2fb">
        <h2 style="font-family:var(--ff);font-size:.92rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:.45rem">
          <i class="fa-solid fa-table-list" style="color:var(--purple-mid)"></i> Sessions Log
          <span id="sessionsFilterTag" style="display:none;background:#ede9fe;color:var(--purple-mid);font-size:.7rem;font-weight:700;padding:.15rem .5rem;border-radius:6px;margin-left:.3rem"></span>
        </h2>
        <div id="sessionsLoadingSpinner" style="font-size:.8rem;color:var(--muted);display:flex;align-items:center;gap:.4rem">
          <i class="fa-solid fa-spinner fa-spin"></i> Loading…
        </div>
      </div>

      <div class="table-wrap">
        <table class="history-table" id="sessionsTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Date</th>
              <th>Time In</th>
              <th>Time Out</th>
              <th>Duration</th>
              <th>Lab</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody id="sessionsTableBody">
            <tr><td colspan="7" style="text-align:center;padding:2.5rem;color:var(--muted)">
              <i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>Loading sessions…
            </td></tr>
          </tbody>
        </table>
      </div>

      <div style="padding:.75rem 1.5rem;border-top:1px solid #f4f2fb;font-size:.8rem;color:var(--muted);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem" id="sessionsTableFooter">
        <span id="sessionsCount"></span>
        <div style="display:flex;align-items:center;gap:.5rem">
          <div class="pagination" id="sessionsPagination"></div>
          <span style="font-size:.72rem;color:#9ca3af"><i class="fa-solid fa-sort-down" style="color:var(--purple-mid)"></i> Latest first</span>
        </div>
      </div>
    </div>
  </div><!-- /page-sessions -->

  <!-- ══ RESOURCES ══ -->
  <div class="dash-page" id="page-resources">
    <div class="page-header">
      <div>
        <h1 class="page-title">Resources</h1>
        <p class="page-sub">Files and documents shared by the lab administrator.</p>
      </div>
      <div class="page-actions">
        </select>
      </div>
    </div>

    <!-- Software Availability PDF Banner -->
    <div id="softwarePdfBanner" style="margin-bottom:1.25rem;display:none">
      <div style="display:flex;align-items:center;gap:1rem;padding:1.1rem 1.4rem;background:linear-gradient(135deg,#f5f3ff,#ede9fe);border:1.5px solid #ddd6fe;border-radius:14px">
        <div style="width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg,var(--purple-mid),#a259f7);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fff;flex-shrink:0">
          <i class="fa-solid fa-file-pdf"></i>
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-family:var(--ff);font-size:.95rem;font-weight:800;color:var(--text)">Software Availability List</div>
          <div style="font-size:.78rem;color:var(--muted);margin-top:.1rem">Official list of software available across all computer labs.</div>
        </div>
        <div style="display:flex;gap:.5rem;flex-shrink:0">
          <button id="softwarePdfPreviewBtn" style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;background:linear-gradient(135deg,var(--purple-mid),#a259f7);color:#fff;border:none;border-radius:9px;font-size:.8rem;font-weight:700;cursor:pointer;transition:opacity .18s" onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
            <i class="fa-solid fa-eye"></i> View PDF
          </button>
          <a id="softwarePdfDownloadBtn" href="#" download style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;background:#fff;color:var(--purple-mid);border:1.5px solid #ddd6fe;border-radius:9px;font-size:.8rem;font-weight:700;text-decoration:none;transition:background .18s" onmouseover="this.style.background='#f3f0ff'" onmouseout="this.style.background='#fff'">
            <i class="fa-solid fa-download"></i> Download
          </a>
        </div>
      </div>
    </div>
    <div id="softwarePdfBannerLoading" style="margin-bottom:1.25rem;padding:.75rem 1.2rem;background:#faf8ff;border:1.5px solid #ede9fe;border-radius:12px;font-size:.82rem;color:var(--muted);display:flex;align-items:center;gap:.5rem">
      <i class="fa-solid fa-spinner fa-spin"></i> Checking software availability list…
    </div>

    <div id="resourcesLoading" style="text-align:center;padding:3rem;color:var(--muted)">
      <i class="fa-solid fa-spinner fa-spin" style="font-size:2rem;display:block;margin-bottom:.75rem;color:#c4b5fd"></i>
      <div style="font-size:.88rem;font-weight:500">Loading resources...</div>
    </div>

    <div id="resourcesEmpty" style="display:none;text-align:center;padding:3.5rem 1rem">
      <div style="width:64px;height:64px;border-radius:18px;background:linear-gradient(135deg,#f3f0ff,#ede9fe);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.7rem;color:#c4b5fd">
        <i class="fa-solid fa-folder-open"></i>
      </div>
      <div style="font-family:var(--ff);font-size:1rem;font-weight:700;color:var(--text);margin-bottom:.35rem">No Files Yet</div>
      <div style="font-size:.83rem;color:var(--muted)">The administrator has not shared any files yet. Check back later.</div>
    </div>

    <div id="resourcesGrid" style="display:none;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1rem"></div>
  </div><!-- /page-resources -->

  <!-- ══ PDF PREVIEW MODAL ══ -->
  <div class="modal-overlay" id="pdfPreviewModal" style="z-index:999">
    <div class="modal-card" style="max-width:860px;width:95vw;max-height:92vh;display:flex;flex-direction:column">
      <div class="modal-header">
        <h3 style="display:flex;align-items:center;gap:.5rem">
          <i class="fa-solid fa-file-pdf" style="color:#ef4444"></i>
          <span id="pdfPreviewTitle" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:520px">Document</span>
        </h3>
        <div style="display:flex;align-items:center;gap:.5rem">
          <a id="pdfPreviewDownload" href="#" download class="btn-primary-sm" style="font-size:.75rem;padding:.35rem .7rem;text-decoration:none"><i class="fa-solid fa-download"></i> Download</a>
          <button class="modal-close" id="closePdfPreview"><i class="fa-solid fa-xmark"></i></button>
        </div>
      </div>
      <div style="flex:1;overflow:hidden;background:#525659;border-radius:0 0 14px 14px;position:relative;min-height:500px">
        <iframe id="pdfPreviewFrame" src="" style="width:100%;height:100%;min-height:500px;border:none;display:block"></iframe>
        <div id="pdfPreviewFallback" style="display:none;flex-direction:column;align-items:center;justify-content:center;height:100%;min-height:500px;background:#f4f2fb;gap:1rem;padding:2rem;text-align:center;position:absolute;top:0;left:0;right:0;bottom:0">
          <i class="fa-solid fa-file-pdf" style="font-size:3rem;color:#ef4444"></i>
          <div style="font-size:.92rem;font-weight:600;color:var(--text)">Preview not available in this browser.</div>
          <a id="pdfFallbackDownload" href="#" class="btn btn-primary" style="text-decoration:none"><i class="fa-solid fa-download"></i> Download to View</a>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ PROFILE ══ -->
  <div class="dash-page" id="page-profile">
    <div class="page-header">
      <div><h1 class="page-title">My Profile</h1><p class="page-sub">View and update your account information.</p></div>
    </div>
    <div class="profile-layout">
      <div class="profile-avatar-card">
        <div class="pav-photo-wrap">
          <?php if ($photo_exists): ?>
          <img src="<?= htmlspecialchars($profile_photo) ?>" alt="Profile Photo" class="pav-photo-img visible" id="pavPhotoImg"/>
          <div class="pav-photo-initials hidden" id="pavPhotoInitials"><?= strtoupper(substr($first_name,0,1)) ?></div>
          <?php else: ?>
          <img src="" alt="Profile Photo" class="pav-photo-img" id="pavPhotoImg"/>
          <div class="pav-photo-initials" id="pavPhotoInitials"><?= strtoupper(substr($first_name,0,1)) ?></div>
          <?php endif; ?>
          <button type="button" class="pav-camera-btn" id="pavCameraBtn" title="Change photo"><i class="fa-solid fa-camera"></i></button>
        </div>
        <label class="pav-upload-zone" for="profilePhotoInput">
          <span class="pav-upload-label"><i class="fa-solid fa-arrow-up-from-bracket"></i> Upload Photo</span>
          <span class="pav-upload-hint">JPG, PNG, GIF, WEBP · Max 2 MB</span>
        </label>
        <input type="file" id="profilePhotoInput" accept="image/jpeg,image/png,image/gif,image/webp"/>
        <button type="button" class="pav-remove-btn <?= $photo_exists ? 'visible' : '' ?>" id="pavRemoveBtn"><i class="fa-solid fa-trash"></i> Remove Photo</button>
        <h3 class="pav-name" id="pavName"><?= htmlspecialchars($student_name) ?></h3>
        <p class="pav-course" id="pavCourse"><?= htmlspecialchars($course) ?></p>
        <div class="pav-tags">
          <span class="ptag"><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($id_number) ?></span>
          <span class="ptag" id="pavYearTag"><i class="fa-solid fa-graduation-cap"></i> <?php $yr_labels=[1=>'1st Year',2=>'2nd Year',3=>'3rd Year',4=>'4th Year']; echo $yr_labels[$year_level] ?? $year_level.' Year'; ?></span>
        </div>
        <div class="pav-info-list">
          <div class="pav-info-item"><i class="fa-solid fa-envelope"></i><span id="pavEmail"><?= $email ? htmlspecialchars($email) : '—' ?></span></div>
          <div class="pav-info-item"><i class="fa-solid fa-location-dot"></i><span id="pavAddress"><?= $address ? htmlspecialchars($address) : '—' ?></span></div>
        </div>
        <div class="pav-sessions">
          <div class="psi-item"><span class="psi-val" id="pavSessionsUsed"><?= $sessions_used ?></span><span class="psi-lbl">Sessions Used</span></div>
          <div class="psi-divider"></div>
          <div class="psi-item"><span class="psi-val" id="pavSessionsLeft"><?= $sessions_left ?></span><span class="psi-lbl">Sessions Left</span></div>
        </div>
      </div>

      <div class="profile-form-card">
        <div class="card-header"><h2><i class="fa-solid fa-user-pen"></i> Edit Profile</h2></div>
        <form id="profileForm" class="auth-form" novalidate enctype="multipart/form-data">
          <div class="form-group">
            <label>ID Number</label>
            <input type="text" value="<?= htmlspecialchars($id_number) ?>" disabled class="input-disabled"/>
            <span style="font-size:.72rem;color:var(--muted)">ID cannot be changed.</span>
          </div>
          <div class="form-row three-col">
            <div class="form-group"><label for="pf_last">Last Name</label><input type="text" id="pf_last" name="pf_last" value="<?= htmlspecialchars($last_name) ?>" required/><span class="form-error" id="pf_lastError"></span></div>
            <div class="form-group"><label for="pf_first">First Name</label><input type="text" id="pf_first" name="pf_first" value="<?= htmlspecialchars($first_name) ?>" required/><span class="form-error" id="pf_firstError"></span></div>
            <div class="form-group"><label for="pf_middle">Middle Name</label><input type="text" id="pf_middle" name="pf_middle" value="<?= htmlspecialchars($middle_name) ?>"/></div>
          </div>
          <div class="form-row two-col">
            <div class="form-group">
              <label for="pf_course">Course</label>
              <select id="pf_course" name="pf_course">
                <?php $courses=['BSIT','BSCS','BSCE','BSME','BSEE','BSECE','BSIE','BEEd','BSEd','BSCrim','BSA','BSBA','BSHRM','BSCA','BSOA','BSSW','AB Political Science']; foreach($courses as $c): ?><option value="<?= $c ?>" <?= $course===$c?'selected':'' ?>><?= $c ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="pf_year">Year Level</label>
              <select id="pf_year" name="pf_year">
                <?php for($y=1;$y<=4;$y++): ?><option value="<?= $y ?>" <?= $year_level==$y?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
              </select>
            </div>
          </div>
          <div class="form-group"><label for="pf_email">Email Address</label><input type="email" id="pf_email" name="pf_email" value="<?= htmlspecialchars($email) ?>" required/><span class="form-error" id="pf_emailError"></span></div>
          <div class="form-group"><label for="pf_address">Address</label><input type="text" id="pf_address" name="pf_address" value="<?= htmlspecialchars($address) ?>"/></div>

          <div class="profile-section-title"><i class="fa-solid fa-lock"></i> Change Password <span class="section-opt">(leave blank to keep current)</span></div>
          <div class="form-row three-col">
            <div class="form-group"><label>Current Password</label><div class="input-icon-wrap"><input type="password" id="pf_cur_pw" name="pf_cur_pw"/><button type="button" class="toggle-pw" data-target="pf_cur_pw" tabindex="-1"><i class="fa-regular fa-eye"></i></button></div><span class="form-error" id="pf_cur_pwError"></span></div>
            <div class="form-group"><label>New Password</label><div class="input-icon-wrap"><input type="password" id="pf_new_pw" name="pf_new_pw" placeholder="Min. 8 characters"/><button type="button" class="toggle-pw" data-target="pf_new_pw" tabindex="-1"><i class="fa-regular fa-eye"></i></button></div><span class="form-error" id="pf_new_pwError"></span></div>
            <div class="form-group"><label>Repeat New Password</label><div class="input-icon-wrap"><input type="password" id="pf_rep_pw" name="pf_rep_pw"/><button type="button" class="toggle-pw" data-target="pf_rep_pw" tabindex="-1"><i class="fa-regular fa-eye"></i></button></div><span class="form-error" id="pf_rep_pwError"></span></div>
          </div>

          <div style="display:flex;gap:.75rem;margin-top:.2rem;flex-wrap:wrap;align-items:center">
            <button type="submit" class="btn btn-primary" id="saveProfileBtn"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            <button type="button" class="btn-outline-sm" id="cancelProfileBtn"><i class="fa-solid fa-rotate-left"></i> Discard</button>
            <span class="profile-save-status" id="profileSaveStatus"></span>
          </div>
        </form>
      </div>
    </div>
  </div><!-- /page-profile -->

</main>

<!-- ══ RESERVATION MODAL (Multi-step with PC Seat Map) ══ -->
<div class="modal-overlay" id="reserveModal">
  <div class="modal-card" style="max-width:700px">
    <div class="modal-header">
      <h3><i class="fa-solid fa-calendar-plus"></i> New Reservation <span id="reserveStepLabel" style="font-size:.75rem;font-weight:500;color:var(--muted);margin-left:.5rem">(Step 1 of 2)</span></h3>
      <button class="modal-close" id="closeModal"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <!-- STEP 1: Reservation Details -->
    <div id="reserveStep1">
      <form id="reserveForm" class="auth-form" style="gap:.8rem;padding:0 1.8rem 1.8rem">
        <div class="form-group">
          <label>ID Number</label>
          <input type="text" value="<?= htmlspecialchars($id_number) ?>" readonly style="background:#f9fafb;cursor:not-allowed"/>
        </div>
        <div class="form-group">
          <label>Student Name</label>
          <input type="text" value="<?= htmlspecialchars($student_name) ?>" readonly style="background:#f9fafb;cursor:not-allowed"/>
        </div>
        <div class="form-group">
          <label for="res_purpose">Purpose</label>
          <select id="res_purpose" name="res_purpose">
            <option value="">Select Purpose</option>
            <option value="C Programming">C Programming</option>
            <option value="Java">Java</option>
            <option value="C#">C#</option>
            <option value="ASP.Net">ASP.Net</option>
            <option value="PHP">PHP</option>
            <option value="Research">Research</option>
            <option value="Project Work">Project Work</option>
            <option value="Online Exam">Online Exam</option>
          </select>
        </div>
        <div class="form-group">
          <label for="res_lab">Laboratory</label>
          <select id="res_lab" name="res_lab">
            <option value="">Select Lab</option>
            <?php foreach($all_labs as $lab): ?>
              <option value="<?= htmlspecialchars($lab['name']) ?>"><?= htmlspecialchars($lab['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <!-- Inline software preview for selected lab -->
          <div id="resLabSoftwarePreview" style="display:none;margin-top:.5rem;padding:.5rem .75rem;background:#f0fdf4;border-radius:9px;border:1px solid #bbf7d0;font-size:.78rem">
            <span style="font-weight:700;color:#16a34a"><i class="fa-solid fa-cubes"></i> Available Software:</span>
            <div id="resLabSoftwareChips" style="display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.3rem"></div>
          </div>
          <div id="resLabNoSoftware" style="display:none;margin-top:.45rem;font-size:.76rem;color:var(--muted)"><i class="fa-solid fa-circle-info"></i> No software listed for this lab.</div>
        </div>
        <div class="form-row two-col">
          <div class="form-group">
            <label for="res_timein">Time In</label>
            <input type="time" id="res_timein" name="res_timein"/>
          </div>
          <div class="form-group">
            <label for="res_date">Date</label>
            <input type="date" id="res_date" name="res_date" min="<?= date('Y-m-d') ?>"/>
          </div>
        </div>
        <div class="form-group">
          <label>Remaining Session</label>
          <input type="text" id="resFormSessionsLeft" value="<?= $sessions_left ?>" readonly style="background:#f9fafb;cursor:not-allowed"/>
        </div>
        <input type="hidden" id="res_pc_hidden" name="res_pc" value="">
        <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:.3rem">
          <button type="button" class="btn-outline-sm" id="cancelModal">Cancel</button>
          <button type="button" class="btn btn-primary" id="goToSeatmapBtn"><i class="fa-solid fa-desktop"></i> Select PC &rarr;</button>
        </div>
      </form>
    </div>

    <!-- STEP 2: PC Seat Map -->
    <div id="reserveStep2" style="display:none;padding:0 1.4rem 1.4rem">
      <!-- Lab/Date/Time info bar -->
      <div style="display:flex;align-items:center;gap:.6rem;padding:.6rem .85rem;background:linear-gradient(135deg,#faf8ff,#f3f0ff);border-radius:10px;border:1px solid #ede9fe;font-size:.82rem;margin-bottom:.65rem;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:.35rem"><i class="fa-solid fa-building" style="color:var(--purple-mid)"></i> <strong id="smLabDisplay">—</strong></div>
        <span style="color:#ddd6fe">|</span>
        <div style="display:flex;align-items:center;gap:.35rem"><i class="fa-regular fa-calendar" style="color:var(--purple-mid)"></i> <span id="smDateDisplay">—</span></div>
        <span style="color:#ddd6fe">|</span>
        <div style="display:flex;align-items:center;gap:.35rem"><i class="fa-regular fa-clock" style="color:var(--purple-mid)"></i> <span id="smTimeDisplay">—</span></div>
      </div>

      <!-- Software availability display -->
      <div class="software-section" id="softwareSection" style="display:none;margin-bottom:.6rem">
        <div class="software-section-title"><i class="fa-solid fa-cube"></i> Available Software in this Lab:</div>
        <div class="software-list" id="softwareList"></div>
      </div>

      <!-- Status bar -->
      <div style="display:flex;align-items:center;gap:1rem;padding:.45rem .75rem;background:#f9fafb;border-radius:9px;border:1px solid #f0ecff;margin-bottom:.6rem;flex-wrap:wrap" id="smStatBar">
        <!-- injected by JS -->
      </div>

      <!-- Legend -->
      <div style="display:flex;gap:.7rem;flex-wrap:wrap;margin-bottom:.6rem;font-size:.75rem;font-weight:600;color:#4b5563">
        <span style="display:flex;align-items:center;gap:.3rem"><span style="width:10px;height:10px;background:#22c55e;border-radius:2px;display:inline-block"></span>Available</span>
        <span style="display:flex;align-items:center;gap:.3rem"><span style="width:10px;height:10px;background:#f59e0b;border-radius:2px;display:inline-block"></span>Reserved</span>
        <span style="display:flex;align-items:center;gap:.3rem"><span style="width:10px;height:10px;background:#ef4444;border-radius:2px;display:inline-block"></span>In Use</span>
        <span style="display:flex;align-items:center;gap:.3rem"><span style="width:10px;height:10px;background:#9ca3af;border-radius:2px;display:inline-block"></span>Unavailable</span>
        <span style="display:flex;align-items:center;gap:.3rem"><span style="width:10px;height:10px;background:#6c3fcf;border-radius:2px;display:inline-block"></span>Your Choice</span>
      </div>

      <div id="smLoading" style="text-align:center;padding:1.5rem;color:var(--muted)">
        <i class="fa-solid fa-spinner fa-spin" style="font-size:1.4rem;display:block;margin-bottom:.5rem"></i>Loading seat map…
      </div>
      <div id="smGrid" style="display:none;grid-template-columns:repeat(auto-fill,minmax(76px,1fr));gap:.5rem;max-height:360px;overflow-y:auto;padding:.25rem;scrollbar-width:thin"></div>

      <div style="display:flex;align-items:center;justify-content:space-between;margin-top:.85rem;padding-top:.75rem;border-top:1px solid #f0ecff;flex-wrap:wrap;gap:.5rem">
        <div id="smSelectedLabel" style="font-size:.82rem;font-weight:600;color:var(--muted);display:flex;align-items:center;gap:.4rem">
          <i class="fa-solid fa-computer"></i> No PC selected — admin will assign one
        </div>
        <div style="display:flex;gap:.5rem">
          <button type="button" class="btn-outline-sm" id="backToStep1Btn"><i class="fa-solid fa-arrow-left"></i> Back</button>
          <button type="button" class="btn btn-primary" id="submitReservationBtn"><i class="fa-solid fa-calendar-check"></i> Submit Reservation</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══ FEEDBACK MODAL ══ -->
<div class="modal-overlay" id="feedbackModal">
  <div class="modal-card" style="max-width:420px">
    <div class="modal-header">
      <h3><i class="fa-solid fa-comment-dots"></i> Submit Feedback</h3>
      <button class="modal-close" id="closeFeedbackModal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div style="padding:0 1.8rem 1.8rem">
      <input type="hidden" id="fb_sitin_id"/>
      <div class="form-group">
        <label>Rating</label>
        <div class="star-rating" id="starRating">
          <i class="fa-solid fa-star" data-val="1"></i>
          <i class="fa-solid fa-star" data-val="2"></i>
          <i class="fa-solid fa-star" data-val="3"></i>
          <i class="fa-solid fa-star" data-val="4"></i>
          <i class="fa-solid fa-star" data-val="5"></i>
        </div>
        <input type="hidden" id="fb_rating" value="0"/>
      </div>
      <div class="form-group">
        <label for="fb_comment">Comment <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
        <textarea class="feedback-textarea" id="fb_comment" placeholder="Share your experience..."></textarea>
      </div>
      <div style="display:flex;gap:.75rem;justify-content:flex-end">
        <button type="button" class="btn-outline-sm" id="cancelFeedback">Cancel</button>
        <button type="button" class="btn-primary-sm" id="submitFeedbackBtn"><i class="fa-solid fa-paper-plane"></i> Submit</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ ANNOUNCEMENT VIEW MODAL ══ -->
<div class="modal-overlay" id="annModal">
  <div class="modal-card ann-modal-card">
    <div class="modal-header">
      <h3><i class="fa-solid fa-bullhorn"></i> <span id="annModalSender">Announcement</span></h3>
      <button class="modal-close" id="closeAnnModal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="ann-modal-meta">
      <span><i class="fa-solid fa-user-shield"></i> <span id="annModalAdmin"></span></span>
      <span><i class="fa-regular fa-clock"></i> <span id="annModalTime"></span></span>
    </div>
    <div class="ann-modal-content" id="annModalContent"></div>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="script.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {

  /* ── PAGE SWITCHING ── */
  const sbLinks   = document.querySelectorAll('.sb-link[data-page]');
  const menuItems = document.querySelectorAll('.sb-menu-item[data-page]');
  const pages     = document.querySelectorAll('.dash-page');

  const VALID_PAGES = ['home','history','reservation','sessions','resources','profile'];

  function switchPage(pageId) {
    if (!VALID_PAGES.includes(pageId)) pageId = 'home';
    pages.forEach(p => p.classList.remove('active'));
    sbLinks.forEach(l => l.classList.remove('active'));
    const target = document.getElementById('page-' + pageId);
    if (target) target.classList.add('active');
    sbLinks.forEach(l => { if (l.dataset.page === pageId) l.classList.add('active'); });
    history.replaceState(null, '', '#' + pageId);
    closeSidebar();
  }

  // Expose globally so second script block can call it
  window._dashSwitchPage = switchPage;

  sbLinks.forEach(l => l.addEventListener('click', e => { e.preventDefault(); switchPage(l.dataset.page); }));
  menuItems.forEach(item => { item.addEventListener('click', e => { e.preventDefault(); if (item.dataset.page) { switchPage(item.dataset.page); closeUserMenu(); } }); });

  /* ── TOPBAR PROFILE CHIP ── */
  const dctProfileChip     = document.getElementById('dctProfileChip');
  const dctProfileDropdown = document.getElementById('dctProfileDropdown');
  const dctChipChevron     = document.getElementById('dctChipChevron');

  function closeDctDropdown() {
    dctProfileDropdown?.classList.remove('open');
    dctChipChevron?.classList.remove('open');
  }
  dctProfileChip?.addEventListener('click', e => {
    e.stopPropagation();
    const isOpen = dctProfileDropdown.classList.toggle('open');
    dctChipChevron?.classList.toggle('open', isOpen);
  });
  document.getElementById('dctGoProfile')?.addEventListener('click', () => {
    closeDctDropdown();
    switchPage('profile');
  });
  document.addEventListener('click', e => {
    if (!dctProfileChip?.contains(e.target)) closeDctDropdown();
  });

  /* ── MOBILE SIDEBAR ── */
  const sidebar = document.getElementById('sidebar');
  const sbToggle = document.getElementById('sbToggle');
  const sidebarOverlay = document.getElementById('sidebarOverlay');
  function closeSidebar() { sidebar?.classList.remove('open'); sidebarOverlay?.classList.remove('open'); }
  sbToggle?.addEventListener('click', () => { const isOpen = sidebar.classList.toggle('open'); sidebarOverlay.classList.toggle('open', isOpen); });
  sidebarOverlay?.addEventListener('click', closeSidebar);

  /* ── RESERVATION MULTI-STEP FLOW ── */
  const reserveModal = document.getElementById('reserveModal');
  let _smSelectedPc = null;
  let _reservationEnabled = <?= $reservation_enabled ? 'true' : 'false' ?>;

  function openReserveModal() {
    if (!_reservationEnabled) {
      showToast('Reservations are currently disabled by the administrator.', 'error');
      return;
    }
    _smSelectedPc = null;
    document.getElementById('res_pc_hidden').value = '';
    document.getElementById('reserveStep1').style.display = 'block';
    document.getElementById('reserveStep2').style.display = 'none';
    document.getElementById('reserveStepLabel').textContent = '(Step 1 of 2)';
    document.getElementById('smSelectedLabel').textContent = 'No PC selected (admin will assign one)';
    document.getElementById('smGrid').innerHTML = '';
    document.getElementById('smGrid').style.display = 'none';
    document.getElementById('smLoading').style.display = 'block';
    reserveModal?.classList.add('open');
  }
  window.openReserveModal = openReserveModal;

  document.getElementById('openReserveModal')?.addEventListener('click', openReserveModal);
  document.getElementById('closeModal')?.addEventListener('click', () => reserveModal?.classList.remove('open'));
  document.getElementById('cancelModal')?.addEventListener('click', () => reserveModal?.classList.remove('open'));
  reserveModal?.addEventListener('click', e => { if (e.target === reserveModal) reserveModal.classList.remove('open'); });
  document.getElementById('backToStep1Btn')?.addEventListener('click', () => {
    document.getElementById('reserveStep1').style.display = 'block';
    document.getElementById('reserveStep2').style.display = 'none';
    document.getElementById('reserveStepLabel').textContent = '(Step 1 of 2)';
  });

  document.getElementById('goToSeatmapBtn')?.addEventListener('click', async () => {
    const lab     = document.getElementById('res_lab')?.value;
    const date    = document.getElementById('res_date')?.value;
    const timein  = document.getElementById('res_timein')?.value;
    const purpose = document.getElementById('res_purpose')?.value;
    if (!lab || !date || !timein || !purpose) { showToast('Please fill all fields before selecting a PC.', 'error'); return; }
    
    document.getElementById('smLabDisplay').textContent  = lab;
    document.getElementById('smDateDisplay').textContent = new Date(date).toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'});
    document.getElementById('smTimeDisplay').textContent = new Date('2000-01-01T'+timein).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
    document.getElementById('reserveStep1').style.display = 'none';
    document.getElementById('reserveStep2').style.display = 'block';
    document.getElementById('reserveStepLabel').textContent = '(Step 2 of 2)';
    document.getElementById('smGrid').innerHTML = '';
    document.getElementById('smGrid').style.display = 'none';
    document.getElementById('smLoading').style.display = 'block';
    _smSelectedPc = null;
    document.getElementById('res_pc_hidden').value = '';
    
    // Load seat map
    const fd = new FormData(); fd.append('_action','get_student_seat_map'); fd.append('lab_name',lab); fd.append('date',date);
    try {
      const res = await fetch('dashboard.php',{method:'POST',body:fd}); const data = await res.json();
      if (!data.success) { document.getElementById('smLoading').innerHTML = '<span style="color:#ef4444">'+data.message+'</span>'; return; }
      renderStudentSeatMap(data.seats);
    } catch(e) { document.getElementById('smLoading').innerHTML = '<span style="color:#ef4444">Server error loading seat map.</span>'; }
  });

  function renderStudentSeatMap(seats) {
    const grid = document.getElementById('smGrid');
    grid.innerHTML = '';

    // Count stats
    const counts = { available: 0, reserved: 0, in_use: 0, unavailable: 0 };
    seats.forEach(s => { if (counts[s.status] !== undefined) counts[s.status]++; });

    // Update stat bar
    const statBar = document.getElementById('smStatBar');
    if (statBar) {
      statBar.innerHTML = `
        <span style="display:flex;align-items:center;gap:.3rem;font-size:.72rem;font-weight:700;color:#16a34a"><span style="width:8px;height:8px;background:#22c55e;border-radius:50%;display:inline-block"></span>${counts.available} Available</span>
        <span style="display:flex;align-items:center;gap:.3rem;font-size:.72rem;font-weight:700;color:#d97706"><span style="width:8px;height:8px;background:#f59e0b;border-radius:50%;display:inline-block"></span>${counts.reserved} Reserved</span>
        <span style="display:flex;align-items:center;gap:.3rem;font-size:.72rem;font-weight:700;color:#dc2626"><span style="width:8px;height:8px;background:#ef4444;border-radius:50%;display:inline-block"></span>${counts.in_use} In Use</span>
        <span style="display:flex;align-items:center;gap:.3rem;font-size:.72rem;font-weight:700;color:#6b7280"><span style="width:8px;height:8px;background:#9ca3af;border-radius:50%;display:inline-block"></span>${counts.unavailable} Unavail.</span>
      `;
    }

    // PC configs
    const cfg = {
      available:   { bg: '#f0fdf4', border: '#22c55e', iconBg: '#dcfce7', iconColor: '#16a34a', label: 'Free',     icon: 'fa-desktop',     cursor: 'pointer' },
      reserved:    { bg: '#fffbeb', border: '#f59e0b', iconBg: '#fef3c7', iconColor: '#d97706', label: 'Reserved', icon: 'fa-lock',        cursor: 'not-allowed' },
      in_use:      { bg: '#fff1f2', border: '#ef4444', iconBg: '#fee2e2', iconColor: '#dc2626', label: 'In Use',   icon: 'fa-circle-xmark',cursor: 'not-allowed' },
      unavailable: { bg: '#f9fafb', border: '#d1d5db', iconBg: '#f3f4f6', iconColor: '#9ca3af', label: 'Offline',  icon: 'fa-power-off',   cursor: 'not-allowed' },
      selected:    { bg: '#ede9fe', border: '#6c3fcf', iconBg: '#ddd6fe', iconColor: '#6c3fcf', label: 'Selected', icon: 'fa-check',       cursor: 'pointer' },
    };

    seats.forEach(seat => {
      const pn = parseInt(seat.pc_number);
      const label = 'PC-' + String(pn).padStart(2, '0');
      const st = seat.status;
      const isAvail = st === 'available';
      const isSelected = pn === _smSelectedPc;
      const c = isSelected ? cfg.selected : (cfg[st] || cfg.unavailable);

      const div = document.createElement('div');
      div.style.cssText = [
        `display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.3rem`,
        `padding:.65rem .3rem .5rem`,
        `border-radius:12px`,
        `border:2px solid ${c.border}`,
        `background:${c.bg}`,
        `cursor:${c.cursor}`,
        `min-height:72px`,
        `transition:transform .15s,box-shadow .15s`,
        `user-select:none`,
        `position:relative`,
      ].join(';');

      div.innerHTML = `
        <div style="width:32px;height:32px;border-radius:8px;background:${c.iconBg};display:flex;align-items:center;justify-content:center;color:${c.iconColor};font-size:.8rem;flex-shrink:0">
          <i class="fa-solid ${c.icon}"></i>
        </div>
        <span style="font-size:.62rem;font-weight:800;letter-spacing:.03em;color:${isSelected?'#6c3fcf':'#1f2937'};margin-top:.05rem">${label}</span>
        <span style="font-size:.55rem;font-weight:600;color:${c.iconColor};opacity:.85">${isSelected ? '✓ Chosen' : c.label}</span>
      `;

      if (isAvail || isSelected) {
        div.addEventListener('click', () => {
          _smSelectedPc = (_smSelectedPc === pn) ? null : pn;
          // Mirror to hidden input so submit always reads the latest value
          document.getElementById('res_pc_hidden').value = _smSelectedPc || '';
          renderStudentSeatMap(seats);
          const selLabel = document.getElementById('smSelectedLabel');
          if (selLabel) selLabel.innerHTML = _smSelectedPc
            ? `<i class="fa-solid fa-computer" style="color:var(--purple-mid)"></i> <strong>PC-${String(_smSelectedPc).padStart(2,'0')}</strong> selected as your preference`
            : `<i class="fa-solid fa-computer"></i> No PC selected — admin will assign one`;
        });
        div.addEventListener('mouseenter', () => {
          if (pn !== _smSelectedPc) {
            div.style.transform = 'translateY(-3px)';
            div.style.boxShadow = '0 6px 16px rgba(34,197,94,.25)';
          }
        });
        div.addEventListener('mouseleave', () => { div.style.transform = ''; div.style.boxShadow = ''; });
      }
      grid.appendChild(div);
    });

    document.getElementById('smLoading').style.display = 'none';
    grid.style.display = 'grid';
  }

  document.getElementById('submitReservationBtn')?.addEventListener('click', async () => {
    const lab     = document.getElementById('res_lab')?.value;
    const date    = document.getElementById('res_date')?.value;
    const timein  = document.getElementById('res_timein')?.value;
    const purpose = document.getElementById('res_purpose')?.value;
    const software = document.getElementById('res_software')?.value;
    if (!lab || !date || !timein || !purpose) { showToast('Please fill all fields.', 'error'); return; }

    // Block if selected date+time has already passed
    const selectedDateTime = new Date(`${date}T${timein}`);
    const now = new Date();
    if (selectedDateTime <= now) {
      showToast('The selected time has already passed. Please choose a future time.', 'error');
      return;
    }
    const fd = new FormData();
    fd.append('_action','make_reservation');
    fd.append('res_lab', lab); fd.append('res_date', date);
    fd.append('res_timein', timein); fd.append('res_purpose', purpose);
    if (software) fd.append('res_software', software);
    // Read from hidden input (authoritative) — falls back to in-memory variable
    const pcVal = document.getElementById('res_pc_hidden')?.value || (_smSelectedPc ? String(_smSelectedPc) : '');
    if (pcVal) fd.append('res_pc', pcVal);
    const btn = document.getElementById('submitReservationBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting…';
    try {
      const res  = await fetch('dashboard.php', { method:'POST', body:fd });
      const data = await res.json();
      if (data.success) {
        reserveModal.classList.remove('open');
        showToast(data.message, 'success');
        document.getElementById('res_purpose').value=''; document.getElementById('res_lab').value='';
        document.getElementById('res_timein').value=''; document.getElementById('res_date').value='';
        _smSelectedPc = null;
        document.getElementById('res_pc_hidden').value = '';
        setTimeout(() => fetchReservations(), 500);
      } else { showToast(data.message || 'Could not submit reservation.', 'error'); }
    } catch { showToast('Server error.', 'error'); }
    finally { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-calendar-check"></i> Submit Reservation'; }
  });

  /* ── HISTORY SEARCH — handled by AJAX pollHistory in second <script> block ── */

  /* ── FEEDBACK MODAL ── */
  const feedbackModal = document.getElementById('feedbackModal');
  let currentRating = 0;

  document.querySelectorAll('.btn-feedback:not(.done)').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('fb_sitin_id').value = btn.dataset.sitinId;
      document.getElementById('fb_rating').value = 0;
      document.getElementById('fb_comment').value = '';
      currentRating = 0;
      updateStars(0);
      feedbackModal?.classList.add('open');
    });
  });

  document.getElementById('closeFeedbackModal')?.addEventListener('click', () => feedbackModal?.classList.remove('open'));
  document.getElementById('cancelFeedback')?.addEventListener('click', () => feedbackModal?.classList.remove('open'));
  feedbackModal?.addEventListener('click', e => { if (e.target === feedbackModal) feedbackModal.classList.remove('open'); });

  const stars = document.querySelectorAll('#starRating i');
  stars.forEach(star => {
    star.addEventListener('click', () => {
      currentRating = parseInt(star.dataset.val);
      document.getElementById('fb_rating').value = currentRating;
      updateStars(currentRating);
    });
    star.addEventListener('mouseenter', () => updateStars(parseInt(star.dataset.val), true));
    star.addEventListener('mouseleave', () => updateStars(currentRating));
  });
  function updateStars(val, isHover = false) {
    stars.forEach(s => {
      const v = parseInt(s.dataset.val);
      s.classList.toggle('active', v <= val && !isHover);
      s.classList.toggle('hover', v <= val && isHover);
    });
  }

  document.getElementById('submitFeedbackBtn')?.addEventListener('click', async () => {
    const sitin_id = document.getElementById('fb_sitin_id').value;
    const rating   = parseInt(document.getElementById('fb_rating').value || '0');
    const comment  = document.getElementById('fb_comment').value.trim();
    if (!rating) { showToast('Please select a rating.', 'error'); return; }
    const fd = new FormData();
    fd.append('_action','submit_feedback'); fd.append('sitin_id',sitin_id); fd.append('rating',rating); fd.append('comment',comment);
    try {
      const res  = await fetch('dashboard.php', { method:'POST', body:fd });
      const data = await res.json();
      if (data.success) {
        feedbackModal.classList.remove('open');
        showToast(data.message, 'success');
        const btn = document.querySelector(`.btn-feedback[data-sitin-id="${sitin_id}"]`);
        if (btn) { btn.classList.add('done'); btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-check"></i> Done'; }
      } else { showToast(data.message, 'error'); }
    } catch { showToast('Server error.', 'error'); }
  });

  /* ── PHOTO UPLOAD ── */
  const profilePhotoInput = document.getElementById('profilePhotoInput');
  const pavPhotoImg       = document.getElementById('pavPhotoImg');
  const pavPhotoInitials  = document.getElementById('pavPhotoInitials');
  const pavCameraBtn      = document.getElementById('pavCameraBtn');
  const pavRemoveBtn      = document.getElementById('pavRemoveBtn');
  const sbAvatarThumb     = document.getElementById('sbAvatarThumb');
  let pendingRemovePhoto  = false;

  pavCameraBtn?.addEventListener('click', () => profilePhotoInput?.click());
  profilePhotoInput?.addEventListener('change', () => {
    const file = profilePhotoInput.files[0];
    if (!file) return;
    if (file.size > 2*1024*1024) { showToast('Photo must be under 2 MB.', 'error'); profilePhotoInput.value=''; return; }
    pendingRemovePhoto = false;
    const reader = new FileReader();
    reader.onload = e => {
      const src = e.target.result;
      pavPhotoImg.src = src; pavPhotoImg.classList.add('visible'); pavPhotoInitials?.classList.add('hidden'); pavRemoveBtn?.classList.add('visible');
      if (sbAvatarThumb) { sbAvatarThumb.style.cssText='background:none;padding:0;overflow:hidden;border-radius:10px'; sbAvatarThumb.innerHTML=`<img src="${src}" style="width:100%;height:100%;object-fit:cover" alt="avatar"/>`; }
      const dctPrev=document.getElementById('dctChipAvatar');
      if(dctPrev){ if(dctPrev.tagName==='IMG'){dctPrev.src=src;}else{dctPrev.outerHTML=`<img src="${src}" class="dct-chip-avatar" id="dctChipAvatar" alt="avatar"/>`;} }
      showToast('Photo selected — save to confirm.', 'success');
    };
    reader.readAsDataURL(file);
  });
  pavRemoveBtn?.addEventListener('click', () => {
    pavPhotoImg.src=''; pavPhotoImg.classList.remove('visible'); pavPhotoInitials?.classList.remove('hidden'); pavRemoveBtn.classList.remove('visible'); if(profilePhotoInput) profilePhotoInput.value=''; pendingRemovePhoto=true;
    if(sbAvatarThumb){sbAvatarThumb.style.cssText='';sbAvatarThumb.innerHTML='<?= strtoupper(substr($first_name,0,1)) ?>';}
    const dctRemove=document.getElementById('dctChipAvatar');
    if(dctRemove && dctRemove.tagName==='IMG'){dctRemove.outerHTML='<div class="dct-chip-initials" id="dctChipAvatar"><?= strtoupper(substr($first_name,0,1)) ?></div>';}
    showToast('Photo marked for removal — save to confirm.', 'error');
  });

  /* ── PROFILE FORM ── */
  const profileForm       = document.getElementById('profileForm');
  const saveProfileBtn    = document.getElementById('saveProfileBtn');
  const cancelProfileBtn  = document.getElementById('cancelProfileBtn');
  const profileSaveStatus = document.getElementById('profileSaveStatus');
  const yrLabels = {'1':'1st Year','2':'2nd Year','3':'3rd Year','4':'4th Year'};

  profileForm?.addEventListener('submit', async e => {
    e.preventDefault();
    if (!validateProfileForm()) return;
    const fd = new FormData(profileForm);
    fd.append('_action','update_profile');
    if (profilePhotoInput?.files[0]) fd.append('profile_photo', profilePhotoInput.files[0]);
    if (pendingRemovePhoto && !profilePhotoInput?.files[0]) fd.append('remove_photo','1');
    if (saveProfileBtn) { saveProfileBtn.disabled=true; saveProfileBtn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…'; }
    try {
      const res  = await fetch('dashboard.php', { method:'POST', body:fd });
      const data = await res.json();
      if (data.success) {
        const first=document.getElementById('pf_first')?.value.trim()||'', last=document.getElementById('pf_last')?.value.trim()||'', course=document.getElementById('pf_course')?.value||'', year=document.getElementById('pf_year')?.value||'', email=document.getElementById('pf_email')?.value.trim()||'', addr=document.getElementById('pf_address')?.value.trim()||'', full=first+' '+last;
        document.getElementById('pavName')?.textContent && (document.getElementById('pavName').textContent=full);
        document.getElementById('pavCourse')?.textContent && (document.getElementById('pavCourse').textContent=course);
        const pyt=document.getElementById('pavYearTag'); if(pyt) pyt.innerHTML=`<i class="fa-solid fa-graduation-cap"></i> ${yrLabels[year]||year+' Year'}`;
        document.getElementById('pavEmail')?.textContent && (document.getElementById('pavEmail').textContent=email||'—');
        document.getElementById('pavAddress')?.textContent && (document.getElementById('pavAddress').textContent=addr||'—');
        const sbName=document.querySelector('.sb-user-name'); if(sbName) sbName.textContent=full;
        const dctName=document.getElementById('dctChipName'); if(dctName) dctName.textContent=full;
        const dctAvatar=document.getElementById('dctChipAvatar');
        if(data.photo && pavPhotoImg){ pavPhotoImg.src=data.photo+'?v='+Date.now(); pavPhotoImg.classList.add('visible'); pavPhotoInitials?.classList.add('hidden'); pavRemoveBtn?.classList.add('visible'); if(profilePhotoInput) profilePhotoInput.value='';
          if(dctAvatar){ if(dctAvatar.tagName==='IMG'){dctAvatar.src=data.photo+'?v='+Date.now();}else{dctAvatar.outerHTML=`<img src="${data.photo}?v=${Date.now()}" class="dct-chip-avatar" id="dctChipAvatar" alt="avatar"/>`;} }
        }
        if(data.photo_removed){ pavPhotoImg.src=''; pavPhotoImg.classList.remove('visible'); pavPhotoInitials?.classList.remove('hidden'); pavRemoveBtn?.classList.remove('visible');
          if(dctAvatar && dctAvatar.tagName==='IMG'){ dctAvatar.outerHTML=`<div class="dct-chip-initials" id="dctChipAvatar">${first.charAt(0).toUpperCase()}</div>`; }
        }
        pendingRemovePhoto=false;
        ['pf_cur_pw','pf_new_pw','pf_rep_pw'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
        showToast(data.message||'Profile updated!','success'); showSaveStatus('success','✓ Saved');
      } else { showToast(data.message||'Could not save.','error'); showSaveStatus('error','✗ Not saved'); }
    } catch { showToast('Server error.','error'); showSaveStatus('error','✗ Server error'); }
    finally { if(saveProfileBtn){saveProfileBtn.disabled=false;saveProfileBtn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Changes';} }
  });

  function showSaveStatus(type, msg) {
    if(!profileSaveStatus) return;
    profileSaveStatus.className='profile-save-status '+type;
    profileSaveStatus.innerHTML=`<i class="fa-solid ${type==='success'?'fa-circle-check':'fa-circle-xmark'}"></i> ${msg}`;
    setTimeout(()=>{profileSaveStatus.className='profile-save-status';profileSaveStatus.innerHTML='';},4000);
  }
  cancelProfileBtn?.addEventListener('click', () => { profileForm?.reset(); showToast('Changes discarded.','error'); });

  function validateProfileForm() {
    let valid = true;
    [{ id:'pf_last',errId:'pf_lastError',msg:'Last name is required.' },{ id:'pf_first',errId:'pf_firstError',msg:'First name is required.' },{ id:'pf_email',errId:'pf_emailError',msg:'Email is required.',extra:v=>/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)?'':'Enter a valid email address.' }].forEach(({id,errId,msg,extra})=>{
      const el=document.getElementById(id),err=document.getElementById(errId);
      if(!el||!err) return;
      const val=el.value.trim(),emsg=!val?msg:(extra?extra(val):'');
      err.textContent=emsg; el.classList.toggle('error',!!emsg); if(emsg) valid=false;
    });
    const curPw=document.getElementById('pf_cur_pw')?.value||'',newPw=document.getElementById('pf_new_pw')?.value||'',repPw=document.getElementById('pf_rep_pw')?.value||'';
    const curErr=document.getElementById('pf_cur_pwError'),newErr=document.getElementById('pf_new_pwError'),repErr=document.getElementById('pf_rep_pwError');
    if(curPw||newPw||repPw){
      if(!curPw&&curErr){curErr.textContent='Enter current password.';valid=false;}
      if(newPw&&newPw.length<8&&newErr){newErr.textContent='Minimum 8 characters.';valid=false;}
      if(newPw&&repPw&&newPw!==repPw&&repErr){repErr.textContent='Passwords do not match.';valid=false;}
    }
    return valid;
  }
  profileForm?.querySelectorAll('input,select').forEach(el=>{el.addEventListener('input',()=>{const e=document.getElementById(el.id+'Error');if(e)e.textContent='';el.classList.remove('error');});});

  /* ── PASSWORD TOGGLES ── */
  document.querySelectorAll('.toggle-pw').forEach(btn=>{btn.addEventListener('click',()=>{const input=document.getElementById(btn.dataset.target);if(!input)return;const isHidden=input.type==='password';input.type=isHidden?'text':'password';const icon=btn.querySelector('i');if(icon){icon.classList.toggle('fa-eye',!isHidden);icon.classList.toggle('fa-eye-slash',isHidden);}});});

  /* ── TOAST ── */
  function showToast(message, type='success') {
    document.querySelector('.toast')?.remove();
    const toast=document.createElement('div');
    toast.className='toast '+type;
    toast.innerHTML='<i class="fa-solid '+(type==='success'?'fa-circle-check':'fa-circle-xmark')+'"></i> '+message;
    document.body.appendChild(toast);
    requestAnimationFrame(()=>requestAnimationFrame(()=>toast.classList.add('show')));
    setTimeout(()=>{toast.classList.remove('show');setTimeout(()=>toast.remove(),400);},3200);
  }

  /* ── NOTIFICATION BELL ── */
  const notifPanel       = document.getElementById('notifPanel');
  const desktopNotifBtn  = document.getElementById('desktopNotifBtn');
  const mobileNotifBtn   = document.getElementById('mobileNotifBtn');
  const desktopNotifBadge = document.getElementById('desktopNotifBadge');
  const mobileNotifBadge  = document.getElementById('mobileNotifBadge');
  let annUnreadCount = parseInt(desktopNotifBadge?.textContent||'0')||0;
  let rsvUnreadCount = 0;

  function updateBadge() {
    const total = Math.max(0, annUnreadCount + rsvUnreadCount);
    [desktopNotifBadge, mobileNotifBadge].forEach(b => {
      if (!b) return;
      b.textContent = total;
      b.style.display = total > 0 ? 'flex' : 'none';
    });
  }

  function positionPanel(btn) {
    if (!notifPanel || !btn) return;
    const rect = btn.getBoundingClientRect();
    const isMobile = window.innerWidth <= 768;
    if (isMobile) {
      notifPanel.style.position = 'fixed';
      notifPanel.style.top  = (rect.bottom + 8) + 'px';
      notifPanel.style.right = '.75rem';
      notifPanel.style.left  = '.75rem';
      notifPanel.style.width = 'auto';
    } else {
      notifPanel.style.position = 'fixed';
      notifPanel.style.top  = (rect.bottom + 8) + 'px';
      notifPanel.style.right = (window.innerWidth - rect.right) + 'px';
      notifPanel.style.left  = 'auto';
      notifPanel.style.width = '340px';
    }
  }

  function openNotifPanel(btn, e) {
    e.stopPropagation();
    const isOpen = notifPanel.classList.contains('open');
    notifPanel.classList.remove('open');
    if (!isOpen) { positionPanel(btn); notifPanel.classList.add('open'); }
  }

  desktopNotifBtn?.addEventListener('click', e => openNotifPanel(desktopNotifBtn, e));
  mobileNotifBtn?.addEventListener('click',  e => openNotifPanel(mobileNotifBtn, e));

  document.addEventListener('click', e => {
    if (!desktopNotifBtn?.contains(e.target) && !mobileNotifBtn?.contains(e.target) && !notifPanel?.contains(e.target)) {
      notifPanel?.classList.remove('open');
    }
  });

  document.getElementById('markAllReadBtn')?.addEventListener('click', async () => {
    const fd1 = new FormData(); fd1.append('_action','mark_all_announcements_read');
    const fd2 = new FormData(); fd2.append('_action','mark_rsv_notif_read'); fd2.append('all','1');
    await Promise.all([
      fetch('dashboard.php',{method:'POST',body:fd1}),
      fetch('dashboard.php',{method:'POST',body:fd2}),
    ]);
    document.querySelectorAll('.notif-item.unread').forEach(i=>i.classList.remove('unread'));
    document.querySelectorAll('.ann-item.unread').forEach(i=>{i.classList.remove('unread');i.querySelector('.ann-dot')?.classList.add('read');});
    annUnreadCount = 0; rsvUnreadCount = 0; updateBadge();
  });

  async function markAnnouncementRead(annId) {
    if (!annId) return;
    const notifItem = document.querySelector(`.notif-item[data-ann-id="${annId}"]`);
    const annItem   = document.querySelector(`.ann-item[data-ann-id="${annId}"]`);
    const wasUnread = notifItem?.classList.contains('unread') || annItem?.classList.contains('unread');
    if (!wasUnread) return;
    notifItem?.classList.remove('unread');
    annItem?.classList.remove('unread');
    annItem?.querySelector('.ann-dot')?.classList.add('read');
    const fd = new FormData();
    fd.append('_action','mark_announcement_read');
    fd.append('announcement_id', annId);
    await fetch('dashboard.php',{method:'POST',body:fd});
    annUnreadCount = Math.max(0, annUnreadCount - 1); updateBadge();
  }

  /* ── ANNOUNCEMENT MODAL ── */
  const annModal = document.getElementById('annModal');
  document.getElementById('closeAnnModal')?.addEventListener('click', () => annModal?.classList.remove('open'));
  annModal?.addEventListener('click', e => { if(e.target===annModal) annModal.classList.remove('open'); });

  /* ═══════════════════════════════════════════════════════════════════════════
     LIVE RESERVATIONS POLL - Recent to Old, No Page Reload
     ═══════════════════════════════════════════════════════════════════════════ */
  const reservationsContainer = document.getElementById('reservationsContainer');
  
  const statusMap = {
    'approved': { class: 'active-reserve', badge_cls: 'active',    icon: 'fa-circle-check',  label: 'Approved' },
    'pending':  { class: 'pending-reserve', badge_cls: 'pending',   icon: 'fa-hourglass-half',label: 'Pending' },
    'cancelled':{ class: '',               badge_cls: 'cancelled',  icon: 'fa-circle-xmark',  label: 'Cancelled' },
    'done':     { class: 'done-reserve',   badge_cls: 'done',       icon: 'fa-flag-checkered',label: 'Done' }
  };

  function formatReservationDate(dateStr) {
    if (!dateStr) return '—';
    try {
      const d = new Date(dateStr);
      return d.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' });
    } catch(e) { return dateStr; }
  }

  function formatReservationTime(timeStr) {
    if (!timeStr) return '—';
    try {
      const d = new Date(`2000-01-01T${timeStr}`);
      return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    } catch(e) { return timeStr; }
  }

  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
      if (m === '&') return '&amp;';
      if (m === '<') return '&lt;';
      if (m === '>') return '&gt;';
      return m;
    });
  }

  async function handleCancelReservation(e) {
    const btn = e.currentTarget;
    const rid = btn.dataset.rid;
    if (!confirm('Cancel this reservation?')) return;
    const fd = new FormData();
    fd.append('_action', 'cancel_reservation');
    fd.append('reservation_id', rid);
    try {
      const res = await fetch('dashboard.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.success) {
        showToast('Reservation cancelled.', 'error');
        await fetchReservations();
      } else {
        showToast(data.message || 'Could not cancel.', 'error');
      }
    } catch {
      showToast('Server error.', 'error');
    }
  }

  function renderReservations(reservations) {
    if (!reservationsContainer) return;

    if (!reservations || reservations.length === 0) {
      reservationsContainer.innerHTML = `
        <div class="dash-card" style="text-align:center;padding:2rem;color:var(--muted);grid-column:1/-1">
          <i class="fa-solid fa-calendar-xmark" style="font-size:2rem;margin-bottom:.5rem;display:block"></i>
          <p>No active reservations.${_reservationEnabled ? ' Use the "New Reservation" button above to book a lab slot.' : ''}</p>
        </div>
      `;
    } else {
      let html = '';
      for (const rsv of reservations) {
        const sm = statusMap[rsv.status] || statusMap.pending;
        const rdate = formatReservationDate(rsv.date);
        const timeIn = formatReservationTime(rsv.time_in);
        html += `
          <div class="reserve-card ${rsv.status === 'approved' ? 'active-reserve' : rsv.status === 'pending' ? 'pending-reserve' : ''}" data-rsv-id="${rsv.id}">
            <div class="rc-header">
              <span class="rc-status ${sm.badge_cls}">
                <i class="fa-solid ${sm.icon}"></i> ${sm.label}
              </span>
            </div>
            <div class="rc-lab">
              <i class="fa-solid fa-flask"></i>
              <span>${escapeHtml(rsv.lab)}</span>
            </div>
            <div class="rc-details">
              <div class="rc-detail">
                <i class="fa-regular fa-calendar"></i>
                <span>${rdate}</span>
              </div>
              <div class="rc-detail">
                <i class="fa-regular fa-clock"></i>
                <span>${timeIn}</span>
              </div>
              <div class="rc-detail">
                <i class="fa-solid fa-tag"></i>
                <span>${escapeHtml(rsv.purpose)}</span>
              </div>
              ${rsv.pc_number ? `
              <div class="rc-detail">
                <i class="fa-solid fa-desktop" style="color:var(--purple-mid)"></i>
                <span>PC-${String(rsv.pc_number).padStart(2,'0')} <span style="font-size:.72rem;color:var(--muted)">(your preference)</span></span>
              </div>` : ''}
            </div>
            <div class="rc-footer">
              ${rsv.status === 'pending' ?
                `<button class="btn-outline-sm danger cancel-rsv-btn" data-rid="${rsv.id}">
                   <i class="fa-solid fa-xmark"></i> Cancel
                 </button>` :
                `<span style="font-size:.75rem;color:#16a34a;font-weight:600">
                   <i class="fa-solid fa-circle-check"></i> Approved — Ready for sit-in
                 </span>`
              }
            </div>
          </div>
        `;
      }
      if (_reservationEnabled) {
        // New reservation button is in the page header — no duplicate card needed
      }
      reservationsContainer.innerHTML = html;

      document.querySelectorAll('.cancel-rsv-btn').forEach(btn => {
        btn.removeEventListener('click', handleCancelReservation);
        btn.addEventListener('click', handleCancelReservation);
      });
    }
  }

  function applyReservationStatus(enabled) {
    // New Reservation button
    const btn = document.getElementById('openReserveModal');
    if (btn) {
      btn.disabled = !enabled;
      btn.style.background      = enabled ? '' : '#e5e7eb';
      btn.style.borderColor     = enabled ? '' : '#e5e7eb';
      btn.style.color           = enabled ? '' : '#9ca3af';
      btn.style.cursor          = enabled ? '' : 'not-allowed';
      btn.style.boxShadow       = enabled ? '' : 'none';
      btn.style.opacity         = '1';
    }
    // Disabled banner
    let banner = document.getElementById('reservationDisabledBanner');
    if (!enabled) {
      if (!banner) {
        banner = document.createElement('div');
        banner.id = 'reservationDisabledBanner';
        banner.style.cssText = 'display:flex;align-items:center;gap:1.2rem;padding:1.25rem 1.6rem;background:#f9fafb;border:1.5px solid #e5e7eb;border-radius:16px;margin-bottom:1.25rem;box-shadow:0 2px 8px rgba(0,0,0,.04)';
        banner.innerHTML = `
          <div style="width:46px;height:46px;border-radius:13px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-size:1.15rem;color:#9ca3af;flex-shrink:0">
            <i class="fa-solid fa-calendar-xmark"></i>
          </div>
          <div>
            <div style="font-family:var(--ff);font-size:.95rem;font-weight:800;color:#374151">Reservations Temporarily Unavailable</div>
            <div style="font-size:.81rem;color:#9ca3af;margin-top:.2rem;line-height:1.5">The lab reservation system has been paused by the administrator. Your existing reservations remain active — check back later or contact your lab admin.</div>
          </div>`;
        const container = document.getElementById('reservationsContainer');
        if (container) container.before(banner);
      }
      banner.style.display = 'flex';
    } else {
      if (banner) banner.style.display = 'none';
    }
    // Show toast only after initial load (when the state actually changes live)
    if (applyReservationStatus._initialized) {
      showToast(enabled ? 'Reservations are now open!' : 'Reservations have been disabled by the admin.', enabled ? 'success' : 'error');
    }
    applyReservationStatus._initialized = true;
  }

  async function fetchReservations() {
    try {
      const fd = new FormData();
      fd.append('_action', 'fetch_reservations');
      const res = await fetch('dashboard.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.success) {
        renderReservations(data.reservations);
        const activeCount = data.reservations.length;
        const statCard = document.getElementById('activeReservationCount');
        if (statCard) statCard.textContent = activeCount;

        if (data.rsv_notifications) {
          renderRsvNotifications(data.rsv_notifications);
        }
        rsvUnreadCount = data.unread_rsv_count || 0;
        updateBadge();

        // Real-time reservation system enable/disable
        if (data.reservation_enabled !== undefined) {
          const nowEnabled = !!data.reservation_enabled;
          if (nowEnabled !== _reservationEnabled) {
            _reservationEnabled = nowEnabled;
            applyReservationStatus(nowEnabled);
          }
        }
      }
    } catch(e) {
      console.error('Failed to fetch reservations:', e);
    }
  }

  function renderRsvNotifications(notifications) {
    const section = document.getElementById('rsvNotifSection');
    if (!section) return;
    const existingIds = new Set([...section.querySelectorAll('.notif-item[data-rsv-notif-id]')].map(el => el.dataset.rsvNotifId));
    let added = false;
    for (const n of notifications) {
      if (existingIds.has(String(n.id))) continue;
      added = true;
      const isApproved = n.status === 'approved';
      const icon   = isApproved ? 'fa-circle-check' : 'fa-circle-xmark';
      const color  = isApproved ? '#16a34a' : '#ef4444';
      const label  = isApproved ? 'Reservation Approved' : 'Reservation Denied';
      const msg    = isApproved
        ? `Your reservation for <strong>${escapeHtml(n.lab)}</strong> has been <strong style="color:#16a34a">approved</strong>. You may now sit in.`
        : `Your reservation for <strong>${escapeHtml(n.lab)}</strong> has been <strong style="color:#ef4444">denied</strong>.`;
      const timeStr = n.created_at ? new Date(n.created_at.replace(' ','T')).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '';
      const el = document.createElement('div');
      el.className = 'notif-item unread rsv-notif-item';
      el.dataset.rsvNotifId = n.id;
      el.style.cursor = 'default';
      el.innerHTML = `
        <div class="notif-item-icon" style="background:${isApproved?'#dcfce7':'#fee2e2'}">
          <i class="fa-solid ${icon}" style="color:${color}"></i>
        </div>
        <div class="notif-item-body">
          <div class="notif-item-title">${label}</div>
          <div class="notif-item-desc">${msg}</div>
          <span class="notif-item-time">${timeStr}</span>
        </div>
      `;
      el.addEventListener('click', async () => {
        if (!el.classList.contains('unread')) return;
        el.classList.remove('unread');
        const fd = new FormData(); fd.append('_action','mark_rsv_notif_read'); fd.append('notif_id', n.id);
        await fetch('dashboard.php',{method:'POST',body:fd});
        rsvUnreadCount = Math.max(0, rsvUnreadCount - 1); updateBadge();
      });
      section.prepend(el);
      document.getElementById('annNotifEmpty')?.remove();
    }
  }

  setTimeout(() => {
    fetchReservations();
    setInterval(fetchReservations, 3000);
  }, 500);

  /* ── SESSIONS TABLE ── */
  (function() {
    const tbody     = document.getElementById('sessionsTableBody');
    const spinner   = document.getElementById('sessionsLoadingSpinner');
    const countEl   = document.getElementById('sessionsCount');
    const filterTag = document.getElementById('sessionsFilterTag');
    const dateInput = document.getElementById('sessionDateFilter');
    const clearBtn  = document.getElementById('clearSessionFilter');
    const paginEl   = document.getElementById('sessionsPagination');

    let _sessPage = 1;
    let _sessLoading = false;

    function fmtDuration(seconds) {
      if (!seconds || seconds <= 0) return '<span class="dur-badge short">—</span>';
      const h = Math.floor(seconds / 3600);
      const m = Math.floor((seconds % 3600) / 60);
      const s = seconds % 60;
      let label = '';
      if (h > 0) label += h + 'h ';
      if (m > 0 || h > 0) label += m + 'm ';
      label += s + 's';
      const cls = seconds >= 3600 ? 'long' : seconds < 600 ? 'short' : '';
      return `<span class="dur-badge ${cls}"><i class="fa-regular fa-clock" style="font-size:.65rem"></i> ${label.trim()}</span>`;
    }

    function fmtTime(dtStr) {
      if (!dtStr) return '—';
      try { return new Date(dtStr.replace(' ','T')).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'}); }
      catch(e) { return dtStr; }
    }

    function fmtDateNice(dtStr) {
      if (!dtStr) return '—';
      try { return new Date(dtStr).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'numeric',weekday:'short'}); }
      catch(e) { return dtStr; }
    }

    function renderPagination(page, pages, total, perPage, offset) {
      if (!paginEl) return;
      if (pages <= 1) { paginEl.innerHTML = ''; return; }
      let html = '';
      if (page > 1) html += `<a class="pg-btn" data-sp="${page-1}"><i class="fa-solid fa-chevron-left"></i></a>`;
      for (let p = 1; p <= pages; p++) {
        if (pages > 7 && Math.abs(p - page) > 2 && p !== 1 && p !== pages) {
          if (p === 2 || p === pages - 1) html += `<a class="pg-btn" style="pointer-events:none;opacity:.4">…</a>`;
          continue;
        }
        html += `<a class="pg-btn ${p === page ? 'active' : ''}" data-sp="${p}">${p}</a>`;
      }
      if (page < pages) html += `<a class="pg-btn" data-sp="${page+1}"><i class="fa-solid fa-chevron-right"></i></a>`;
      paginEl.innerHTML = html;
      paginEl.querySelectorAll('.pg-btn[data-sp]').forEach(btn => {
        btn.addEventListener('click', e => {
          e.preventDefault();
          const p = parseInt(btn.dataset.sp);
          if (!isNaN(p)) { _sessPage = p; loadSessions(); }
        });
      });
    }

    async function loadSessions() {
      if (_sessLoading) return;
      _sessLoading = true;
      if (spinner) spinner.style.display = 'flex';
      const filterDate = dateInput?.value || '';
      const fd = new FormData();
      fd.append('_action', 'fetch_sessions');
      fd.append('page', _sessPage);
      if (filterDate) fd.append('filter_date', filterDate);
      try {
        const res  = await fetch('dashboard.php', {method:'POST', body:fd});
        const data = await res.json();
        if (!data.success) return;
        const sessions = data.sessions || [];
        const total    = data.total    || 0;
        const page     = data.page     || 1;
        const pages    = data.pages    || 1;
        const perPage  = data.per_page || 10;
        const offset   = data.offset   || 0;

        if (filterDate && filterTag) {
          filterTag.textContent   = fmtDateNice(filterDate);
          filterTag.style.display = 'inline';
        } else if (filterTag) {
          filterTag.style.display = 'none';
        }

        if (sessions.length === 0) {
          tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:2.5rem;color:var(--muted)">
            <i class="fa-solid fa-inbox" style="font-size:2rem;color:#ddd6fe;display:block;margin-bottom:.6rem"></i>
            ${filterDate ? 'No sessions found for this date.' : 'No completed sessions yet.'}
           </div></tr>`;
          if (paginEl) paginEl.innerHTML = '';
        } else {
          tbody.innerHTML = sessions.map((s, i) => {
            const statusMap = {
              done:   { cls:'sess-status-done',   icon:'fa-circle-check', label:'Done'   },
              active: { cls:'sess-status-active', icon:'fa-circle-dot',   label:'Active' },
            };
            const sm = statusMap[s.status] || { cls:'sess-status-done', icon:'fa-circle-check', label: s.status || 'Done' };
            return `<tr>
              <td style="color:var(--muted);font-size:.8rem">${offset + i + 1}</div>
              <td><div style="font-weight:600;font-size:.83rem">${fmtDateNice(s.date)}</div></div>
              <td style="font-size:.83rem;font-weight:600">${fmtTime(s.time_in)}</div>
              <td style="font-size:.83rem;font-weight:600">${fmtTime(s.time_out)}</div>
              <td>${fmtDuration(parseInt(s.duration_seconds)||0)}</div>
              <td><span class="lab-tag">${s.lab_name||'—'}</span></div>
              <td><span class="sessions-status-badge ${sm.cls}"><i class="fa-solid ${sm.icon}"></i> ${sm.label}</span></div>
            </tr>`;
          }).join('');
          renderPagination(page, pages, total, perPage, offset);
        }

        if (countEl) {
          const showing = Math.min(offset + perPage, total) - offset;
          countEl.textContent = `Showing ${showing} of ${total} session${total !== 1 ? 's' : ''}${filterDate ? ' · filtered' : ''}`;
        }
      } catch(e) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:2rem;color:#ef4444">Error loading sessions.</div></td>`;
      } finally {
        if (spinner) spinner.style.display = 'none';
        _sessLoading = false;
      }
    }

    dateInput?.addEventListener('change', () => {
      _sessPage = 1;
      if (clearBtn) clearBtn.style.display = dateInput.value ? '' : 'none';
      loadSessions();
    });
    clearBtn?.addEventListener('click', () => {
      if (dateInput) dateInput.value = '';
      clearBtn.style.display = 'none';
      _sessPage = 1;
      loadSessions();
    });

    document.querySelectorAll('.sb-link[data-page="sessions"]').forEach(link => {
      link.addEventListener('click', () => setTimeout(loadSessions, 50));
    });
  })();

});
function openAnnouncementModal(annId, sender, content, time) {
  document.getElementById('annModalSender').textContent  = sender;
  document.getElementById('annModalAdmin').textContent   = sender;
  document.getElementById('annModalTime').textContent    = time;
  document.getElementById('annModalContent').textContent = content;
  document.getElementById('annModal')?.classList.add('open');
  setTimeout(() => {
    const notifItem = document.querySelector(`.notif-item[data-ann-id="${annId}"]`);
    const annItem   = document.querySelector(`.ann-item[data-ann-id="${annId}"]`);
    const wasUnread = notifItem?.classList.contains('unread') || annItem?.classList.contains('unread');
    if (!wasUnread) return;
    notifItem?.classList.remove('unread');
    annItem?.classList.remove('unread');
    annItem?.querySelector('.ann-dot')?.classList.add('read');
    const fd = new FormData();
    fd.append('_action','mark_announcement_read');
    fd.append('announcement_id', annId);
    fetch('dashboard.php',{method:'POST',body:fd});
    const badges = [document.getElementById('desktopNotifBadge'), document.getElementById('mobileNotifBadge')];
    annUnreadCount = Math.max(0, annUnreadCount - 1);
    updateBadge();
  }, 0);
}
</script>


<script>
/* ── LIVE HISTORY POLL — fully AJAX, no page reload ── */
(function() {
  const idNum  = <?= json_encode($id_number) ?>;
  const stName = <?= json_encode($student_name) ?>;

  let _histPage   = <?= (int)$history_page ?>;
  let _histSearch = <?= json_encode($history_search) ?>;
  let _histTotal  = <?= (int)$history_total ?>;
  let _histPages  = <?= (int)$history_pages ?>;
  let _polling    = false;

  function fmt(dtStr, type) {
    if (!dtStr) return '—';
    try {
      const d = new Date(dtStr.replace(' ', 'T'));
      if (type === 'time') return d.toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
      if (type === 'date') return d.toISOString().slice(0, 10);
    } catch(e) {}
    return dtStr;
  }

  function bindFeedbackBtns() {
    document.querySelectorAll('.btn-feedback:not(.done):not([data-fb-bound])').forEach(btn => {
      btn.setAttribute('data-fb-bound', '1');
      btn.addEventListener('click', () => {
        document.getElementById('fb_sitin_id').value = btn.dataset.sitinId;
        document.getElementById('fb_rating').value   = 0;
        document.getElementById('fb_comment').value  = '';
        document.querySelectorAll('#starRating i').forEach(s => s.classList.remove('active','hover'));
        document.getElementById('feedbackModal')?.classList.add('open');
      });
    });
  }

  function renderPagination(page, pages, total, perPage, offset, search) {
    const footer = document.querySelector('.table-footer');
    if (!footer) return;

    const shown   = Math.min(offset + perPage, total) - offset;
    const sess    = total !== 1 ? 's' : '';
    const qs      = search ? `&hsearch=${encodeURIComponent(search)}` : '';

    let pgHtml = '';
    if (pages > 1) {
      pgHtml += '<div class="pagination">';
      if (page > 1) pgHtml += `<a class="pg-btn" data-hpage="${page-1}"><i class="fa-solid fa-chevron-left"></i></a>`;
      for (let p = 1; p <= pages; p++) {
        pgHtml += `<a class="pg-btn ${p === page ? 'active' : ''}" data-hpage="${p}">${p}</a>`;
      }
      if (page < pages) pgHtml += `<a class="pg-btn" data-hpage="${page+1}"><i class="fa-solid fa-chevron-right"></i></a>`;
      pgHtml += '</div>';
    }

    footer.innerHTML = `
      <span class="tf-count">Showing <strong>${shown}</strong> of <strong>${total}</strong> session${sess}</span>
      ${pgHtml}
    `;

    footer.querySelectorAll('.pg-btn[data-hpage]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.preventDefault();
        const p = parseInt(btn.dataset.hpage);
        if (!isNaN(p)) { _histPage = p; pollHistory(true); }
      });
    });
  }

  async function pollHistory(force = false) {
    if (_polling) return;
    _polling = true;
    try {
      const fd = new FormData();
      fd.append('_action', 'fetch_history');
      fd.append('page',   _histPage);
      fd.append('search', _histSearch);
      const res  = await fetch('dashboard.php', {method:'POST', body:fd});
      const data = await res.json();
      if (!data.success || !data.sitins) return;

      _histTotal  = data.total;
      _histPages  = data.pages;
      _histPage   = data.page;

      const tbody = document.querySelector('.history-table tbody');
      if (!tbody) return;

      if (!force) {
        const firstId  = data.sitins[0]?.id;
        const curFirst = tbody.querySelector('tr[data-sitin-id]')?.dataset?.sitinId;
        const curCount = tbody.querySelectorAll('tr[data-sitin-id]').length;
        if (String(firstId) === String(curFirst) && curCount === data.sitins.length) return;
      }

      if (data.sitins.length === 0) {
        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:2.5rem 1rem;color:var(--muted)">
          <i class="fa-solid fa-inbox" style="font-size:2rem;color:#ddd6fe;display:block;margin-bottom:.6rem"></i>
          ${_histSearch ? 'No sessions match your search.' : 'No completed sit-in sessions yet.'} </div></tr>`;
      } else {
        const offset = (data.page - 1) * 10;
        tbody.innerHTML = data.sitins.map((s, i) => {
          const hasFb = data.feedbacked_ids.includes(parseInt(s.id));
          const fbBtn = hasFb
            ? `<button class="btn-feedback done" disabled><i class="fa-solid fa-check"></i> Done</button>`
            : `<button class="btn-feedback" data-sitin-id="${s.id}"><i class="fa-solid fa-comment"></i> Feedback</button>`;
          return `<tr data-sitin-id="${s.id}">
            <td class="td-num">${offset + i + 1}</td>
            <td>${idNum}</td>
            <td>${stName}</td>
            <td><span class="purpose-tag">${s.purpose||'—'}</span></td>
            <td><span class="lab-tag">${s.lab||'—'}</span></td>
            <td>${fmt(s.time_in,'time')}</td>
            <td>${fmt(s.time_out,'time')}</td>
            <td>${fmt(s.time_in,'date')}</td>
            <td>${fbBtn}</td>
          </tr>`;
        }).join('');
        bindFeedbackBtns();
      }

      renderPagination(data.page, data.pages, data.total, 10, (data.page-1)*10, data.search);

      const remEl  = document.querySelector('.stat-card.purple .sc-value');
      const usedEl = document.querySelector('.stat-card.green  .sc-value');
      const totEl  = document.querySelector('.stat-card.yellow .sc-value');
      if (remEl)  remEl.textContent  = data.remaining_session;
      if (usedEl) usedEl.textContent = data.sessions_used;
      if (totEl)  totEl.textContent  = data.sessions_used;
      // Keep profile sidebar + reservation form in sync with DB value
      const pavLeft  = document.getElementById('pavSessionsLeft');
      const pavUsed  = document.getElementById('pavSessionsUsed');
      const resLeft  = document.getElementById('resFormSessionsLeft');
      if (pavLeft)  pavLeft.textContent  = data.remaining_session;
      if (pavUsed)  pavUsed.textContent  = data.sessions_used;
      if (resLeft)  resLeft.value        = data.remaining_session;
      if (data.score !== undefined) {
        const pDesktop = document.getElementById('pointsChipVal');
        const pMobile  = document.getElementById('pointsChipValMobile');
        if (pDesktop) pDesktop.textContent = data.score;
        if (pMobile)  pMobile.textContent  = data.score;
      }

    } catch(e) { /* silent */ }
    finally { _polling = false; }
  }

  function interceptStaticPagination() {
    document.querySelectorAll('.pagination .pg-btn[href]').forEach(a => {
      a.addEventListener('click', e => {
        e.preventDefault();
        const url = new URL(a.href, location.href);
        const p = parseInt(url.searchParams.get('hpage') || '1');
        _histPage = p;
        pollHistory(true);
      });
    });
  }

  function interceptHistorySearch() {
    const form  = document.getElementById('historySearchForm');
    const input = document.getElementById('historySearch');
    if (!form || !input) return;
    let _timer;
    form.addEventListener('submit', e => {
      e.preventDefault();
      _histSearch = input.value.trim();
      _histPage   = 1;
      pollHistory(true);
    }, true);

    input.addEventListener('input', () => {
      clearTimeout(_timer);
      _timer = setTimeout(() => {
        _histSearch = input.value.trim();
        _histPage   = 1;
        pollHistory(true);
      }, 500);
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    interceptStaticPagination();
    interceptHistorySearch();
    pollHistory(true);
    setInterval(() => pollHistory(false), 5000);
  });
})();

/* ══ RESOURCES PAGE ══ */
(function() {
  const catColors = {general:'#6c3fcf',announcement:'#ef4444',schedule:'#0369a1',report:'#15803d',resource:'#a21caf',other:'#9ca3af'};
  const fileIcons = {
    pdf: {icon:'fa-file-pdf', color:'#ef4444', cls:'pdf'},
    doc: {icon:'fa-file-word', color:'#2563eb', cls:'doc'},
    docx:{icon:'fa-file-word', color:'#2563eb', cls:'doc'},
    xls: {icon:'fa-file-excel', color:'#15803d', cls:'xls'},
    xlsx:{icon:'fa-file-excel', color:'#15803d', cls:'xls'},
    ppt: {icon:'fa-file-powerpoint', color:'#ea580c', cls:'ppt'},
    pptx:{icon:'fa-file-powerpoint', color:'#ea580c', cls:'ppt'},
    txt: {icon:'fa-file-lines', color:'#6b7280', cls:'txt'},
    jpg: {icon:'fa-file-image', color:'#0891b2', cls:'img'},
    jpeg:{icon:'fa-file-image', color:'#0891b2', cls:'img'},
    png: {icon:'fa-file-image', color:'#0891b2', cls:'img'},
    gif: {icon:'fa-file-image', color:'#0891b2', cls:'img'},
    webp:{icon:'fa-file-image', color:'#0891b2', cls:'img'},
    zip: {icon:'fa-file-zipper', color:'#d97706', cls:'zip'},
  };
  function getExt(name) { return (name.split('.').pop() || '').toLowerCase(); }
  function typeInfo(name) { const e = getExt(name); return fileIcons[e] || {icon:'fa-file', color:'#9ca3af', cls:'other'}; }
  function escHtml(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
  function fmtDate(dt) { return new Date(dt).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }

  async function loadSoftwarePdfBanner() {
    const banner  = document.getElementById('softwarePdfBanner');
    const loading = document.getElementById('softwarePdfBannerLoading');
    const prevBtn = document.getElementById('softwarePdfPreviewBtn');
    const dlBtn   = document.getElementById('softwarePdfDownloadBtn');
    if (!banner || !loading) return;
    try {
      const fd = new FormData(); fd.append('_action','get_software_pdf');
      const res  = await fetch('dashboard.php',{method:'POST',body:fd});
      const data = await res.json();
      loading.style.display = 'none';
      if (data.success && data.url) {
        banner.style.display = 'block';
        if (dlBtn) { dlBtn.href = data.url; dlBtn.download = data.filename || 'software_list.pdf'; }
        if (prevBtn) {
          prevBtn.onclick = () => openPdfPreview(data.url, 'Software Availability List');
        }
      }
      // If no PDF uploaded, banner stays hidden — no error shown to student
    } catch(e) { loading.style.display = 'none'; }
  }

  async function loadResources() {
    const cat = document.getElementById('resourcesCategoryFilter')?.value || 'all';
    const grid = document.getElementById('resourcesGrid');
    const loading = document.getElementById('resourcesLoading');
    const empty = document.getElementById('resourcesEmpty');
    if (!grid) return;

    loading.style.display = 'block';
    grid.style.display = 'none';
    empty.style.display = 'none';

    const fd = new FormData();
    fd.append('_action', 'get_public_files');
    fd.append('category', cat);

    try {
      const res  = await fetch('dashboard.php', {method:'POST', body:fd});
      const data = await res.json();
      loading.style.display = 'none';

      if (!data.success || !data.files || data.files.length === 0) {
        empty.style.display = 'block';
        return;
      }

      grid.style.display = 'grid';
      grid.innerHTML = data.files.map(f => {
        const ti = typeInfo(f.original_name);
        const catColor = catColors[f.category] || '#9ca3af';
        const isPdf = f.is_pdf;

        // Use the proper view_url (inline) for iframe preview, download_url for saving
        const viewUrl     = f.view_url     || ('dashboard.php?view_file='     + f.id);
        const downloadUrl = f.download_url || ('admin.php?download_file='     + f.id);

        const previewBtn = isPdf
          ? `<button onclick="openPdfPreview('${escHtml(viewUrl)}','${escHtml(f.original_name)}','${escHtml(downloadUrl)}')" style="flex:1;display:flex;align-items:center;justify-content:center;gap:.5rem;padding:.6rem .9rem;background:linear-gradient(135deg,var(--purple-mid),#8b5cf6);color:#fff;border:none;border-radius:10px;font-size:.82rem;font-weight:700;cursor:pointer;transition:opacity .18s;box-shadow:0 3px 10px rgba(108,63,207,.25)" onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'"><i class="fa-solid fa-eye"></i> View PDF</button>`
          : `<a href="${escHtml(downloadUrl)}" download="${escHtml(f.original_name)}" style="flex:1;display:flex;align-items:center;justify-content:center;gap:.5rem;padding:.6rem .9rem;background:#f3f0ff;color:var(--purple-mid);border:1.5px solid #ddd6fe;border-radius:10px;font-size:.82rem;font-weight:700;text-decoration:none;transition:background .18s" onmouseover="this.style.background='#ede9fe'" onmouseout="this.style.background='#f3f0ff'"><i class="fa-solid fa-download"></i> Download</a>`;

        // For PDFs: show a mini-thumbnail preview strip inside the card
        const pdfThumbnail = isPdf ? `
          <div onclick="openPdfPreview('${escHtml(viewUrl)}','${escHtml(f.original_name)}','${escHtml(downloadUrl)}')"
               style="cursor:pointer;height:130px;background:linear-gradient(135deg,#f8f5ff,#f0ecff);border-bottom:1.5px solid #ede9fe;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;flex-direction:column;gap:.5rem">
            <i class="fa-solid fa-file-pdf" style="font-size:3rem;color:#ef4444;opacity:.85"></i>
            <div style="font-size:.7rem;font-weight:700;color:#6b7280;background:#fff;padding:.2rem .6rem;border-radius:20px;border:1px solid #ede9fe">Click to preview</div>
          </div>` : '';

        return `<div style="background:#fff;border-radius:16px;border:1.5px solid #ece9f8;overflow:hidden;display:flex;flex-direction:column;transition:box-shadow .2s,transform .2s;box-shadow:0 2px 10px rgba(108,63,207,.07)" onmouseover="this.style.boxShadow='0 8px 28px rgba(108,63,207,.16)';this.style.transform='translateY(-3px)'" onmouseout="this.style.boxShadow='0 2px 10px rgba(108,63,207,.07)';this.style.transform='none'">
          <div style="height:4px;background:linear-gradient(90deg,${isPdf?'#ef4444':ti.color},${isPdf?'#f97316':ti.color}aa)"></div>
          ${pdfThumbnail}
          <div style="padding:1rem 1.1rem .7rem;flex:1">
            <div style="display:flex;align-items:flex-start;gap:.85rem">
              <div style="width:42px;height:42px;border-radius:11px;background:${isPdf?'#fee2e2':''+ti.color+'18'};display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:${isPdf?'#ef4444':ti.color};flex-shrink:0">
                <i class="fa-solid ${isPdf?'fa-file-pdf':ti.icon}"></i>
              </div>
              <div style="flex:1;min-width:0">
                <div style="font-family:var(--ff);font-size:.88rem;font-weight:800;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${escHtml(f.original_name)}">${escHtml(f.original_name)}</div>
                <div style="display:flex;align-items:center;gap:.35rem;margin-top:.28rem;flex-wrap:wrap">
                  <span style="background:${catColor}18;color:${catColor};font-size:.67rem;font-weight:700;padding:.1rem .4rem;border-radius:5px;text-transform:capitalize">${f.category}</span>
                  <span style="font-size:.71rem;color:var(--muted)">${f.size_human}</span>
                  <span style="color:#d1d5db;font-size:.68rem">·</span>
                  <span style="font-size:.71rem;color:var(--muted)">${fmtDate(f.created_at)}</span>
                </div>
                ${f.description ? `<div style="margin-top:.4rem;font-size:.75rem;color:#6b7280;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">${escHtml(f.description)}</div>` : ''}
              </div>
            </div>
          </div>
          <div style="padding:.65rem 1.1rem 1rem;display:flex;gap:.45rem">
            ${previewBtn}
            <a href="${escHtml(downloadUrl)}" download="${escHtml(f.original_name)}" title="Download file" style="width:38px;height:38px;border-radius:10px;background:#f4f2fb;color:var(--purple-mid);display:flex;align-items:center;justify-content:center;font-size:.88rem;text-decoration:none;flex-shrink:0;transition:background .18s;border:1.5px solid #ede9fe" onmouseover="this.style.background='#ede9fe'" onmouseout="this.style.background='#f4f2fb'"><i class="fa-solid fa-download"></i></a>
          </div>
        </div>`;
      }).join('');
    } catch(err) {
      loading.style.display = 'none';
      empty.style.display = 'block';
    }
  }

  window.openPdfPreview = function(viewUrl, name, downloadUrl) {
    const dlUrl = downloadUrl || viewUrl;
    document.getElementById('pdfPreviewTitle').textContent = name;
    document.getElementById('pdfPreviewFrame').src = viewUrl;
    document.getElementById('pdfPreviewDownload').href = dlUrl;
    document.getElementById('pdfPreviewDownload').download = name;
    document.getElementById('pdfFallbackDownload').href = dlUrl;
    document.getElementById('pdfFallbackDownload').download = name;
    // Show iframe, hide fallback initially
    document.getElementById('pdfPreviewFrame').style.display = 'block';
    document.getElementById('pdfPreviewFallback').style.display = 'none';
    // If iframe fails to load PDF (mobile/some browsers), show fallback
    document.getElementById('pdfPreviewFrame').onerror = function() {
      this.style.display = 'none';
      document.getElementById('pdfPreviewFallback').style.display = 'flex';
    };
    document.getElementById('pdfPreviewModal').classList.add('open');
  };

  document.getElementById('closePdfPreview')?.addEventListener('click', () => {
    document.getElementById('pdfPreviewModal').classList.remove('open');
    document.getElementById('pdfPreviewFrame').src = '';
  });
  document.getElementById('pdfPreviewModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
      this.classList.remove('open');
      document.getElementById('pdfPreviewFrame').src = '';
    }
  });

  document.getElementById('resourcesCategoryFilter')?.addEventListener('change', loadResources);

  // Load when nav link clicked
  document.querySelectorAll('.sb-link[data-page="resources"]').forEach(l => {
    l.addEventListener('click', () => setTimeout(() => { loadSoftwarePdfBanner(); loadResources(); }, 60));
  });

  const studentRefreshRegistry = {
    'home':        () => {
      if (typeof fetchReservations === 'function') fetchReservations();
      if (typeof pollHistory       === 'function') pollHistory(true);
    },
    'history':     () => { if (typeof pollHistory       === 'function') pollHistory(true); },
    'reservation': () => { if (typeof fetchReservations === 'function') fetchReservations(); },
    'sessions':    () => { if (typeof loadSessions      === 'function') loadSessions(); },
    'resources':   () => { loadSoftwarePdfBanner(); loadResources(); },
  };

  // Single authoritative switchPage — uses the one defined in the first block
  function switchPage(pageId) {
    if (typeof window._dashSwitchPage === 'function') window._dashSwitchPage(pageId);
    if (studentRefreshRegistry[pageId]) studentRefreshRegistry[pageId]();
  }

  function studentHeartbeat() {
    if (typeof fetchReservations === 'function') fetchReservations();
    const activePage = document.querySelector('.dash-page.active')?.id.replace('page-', '');
    if (activePage && studentRefreshRegistry[activePage]) {
      studentRefreshRegistry[activePage]();
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    // Re-bind sidebar links to this switchPage (which also triggers data loaders)
    document.querySelectorAll('.sb-link[data-page]').forEach(link => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        switchPage(link.dataset.page);
      });
    });

    // Restore last visited page from URL hash — default to 'home'
    const VALID_PAGES = ['home','history','reservation','sessions','resources','profile'];
    const hashPage = location.hash.replace('#', '');
    const restored = (hashPage && VALID_PAGES.includes(hashPage)) ? hashPage : 'home';
    switchPage(restored);

    setInterval(studentHeartbeat, 5000);
  });
})();
</script>
</body>
</html>