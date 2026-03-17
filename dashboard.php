<?php
session_start();
require 'db.php';

/* ══════════════════════════════════════════════════
   HANDLE LOGIN  (POST _action=login — no session needed)
   ══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'login') {
    header('Content-Type: application/json');

    $id_number = trim($_POST['loginId']       ?? '');
    $password  =      $_POST['loginPassword'] ?? '';

    if (!$id_number || !$password) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM students WHERE id_number = ?");
    $stmt->execute([$id_number]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student && password_verify($password, $student['password'])) {
        session_regenerate_id(true);
        // Wipe any leftover admin session data so roles never bleed across
        unset($_SESSION['admin_logged_in'], $_SESSION['admin_name']);
        $_SESSION['role']          = 'student';
        $_SESSION['student_id']    = $student['id'];
        $_SESSION['student_name']  = $student['first_name'] . ' ' . $student['last_name'];
        $_SESSION['id_number']     = $student['id_number'];
        $_SESSION['course']        = $student['course'];
        $_SESSION['profile_photo'] = $student['profile_photo'] ?? '';

        echo json_encode(['success' => true, 'message' => 'Login successful! Redirecting…']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid ID Number or password.']);
    }
    exit;
}
/* ═══════════════════════════════════════════════ */

if (!isset($_SESSION['student_id']) || ($_SESSION['role'] ?? '') === 'admin') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not logged in.']);
        exit;
    }
    header('Location: login.php');
    exit;
}

$is_admin   = false;
$student_id = $_SESSION['student_id'];

/* ══════════════════════════════════════════════════
   HANDLE PROFILE UPDATE  (POST to this same file)
   ══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'update_profile') {
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

    // ── Validate ─────────────────────────────────
    if (!$last_name || !$first_name) {
        echo json_encode(['success' => false, 'message' => 'First and last name are required.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }
    if ($year_level < 1 || $year_level > 4) {
        echo json_encode(['success' => false, 'message' => 'Year level must be 1 to 4.']);
        exit;
    }
    if (!in_array($course, ['BSIT','BSCS','BSCE','BSME','BSEE','BSECE','BSIE','BEEd','BSEd','BSCrim','BSA','BSBA','BSHRM','BSCA','BSOA','BSSW','AB Political Science'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid course selected.']);
        exit;
    }

    // Check email not taken by another student
    $stmt = $pdo->prepare("SELECT id FROM students WHERE email = ? AND id != ?");
    $stmt->execute([$email, $student_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'That email is already used by another account.']);
        exit;
    }

    // ── Password change (optional) ────────────────
    $password_sql    = '';
    $password_params = [];

    if ($cur_pw !== '' || $new_pw !== '' || $rep_pw !== '') {
        if (!$cur_pw) {
            echo json_encode(['success' => false, 'message' => 'Enter your current password to change it.']);
            exit;
        }
        if (strlen($new_pw) < 8) {
            echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
            exit;
        }
        if ($new_pw !== $rep_pw) {
            echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
            exit;
        }
        // Verify current password against DB
        $stmt = $pdo->prepare("SELECT password FROM students WHERE id = ?");
        $stmt->execute([$student_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($cur_pw, $row['password'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
            exit;
        }
        $password_sql    = ', password = ?';
        $password_params = [password_hash($new_pw, PASSWORD_DEFAULT)];
    }

    // ── Photo upload / removal (optional) ────────
    $photo_sql    = '';
    $photo_params = [];
    $photo_path   = '';
    $remove_photo = ($_POST['remove_photo'] ?? '') === '1';

    if ($remove_photo) {
        // Delete old file from disk
        $stmt_old = $pdo->prepare("SELECT profile_photo FROM students WHERE id = ?");
        $stmt_old->execute([$student_id]);
        $old_row = $stmt_old->fetch(PDO::FETCH_ASSOC);
        if (!empty($old_row['profile_photo'])) {
            $old_file = __DIR__ . '/' . ltrim($old_row['profile_photo'], '/');
            if (file_exists($old_file)) @unlink($old_file);
        }
        $photo_sql    = ", profile_photo = ''";
        $_SESSION['profile_photo'] = '';
    } elseif (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $file    = $_FILES['profile_photo'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Invalid photo type. Use JPG, PNG, GIF, or WEBP.']);
            exit;
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Photo must be under 2 MB.']);
            exit;
        }

        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $dest_dir = __DIR__ . '/uploads/profiles/';
        if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);

        // Delete old photo file to avoid orphan files
        $stmt_old = $pdo->prepare("SELECT profile_photo FROM students WHERE id = ?");
        $stmt_old->execute([$student_id]);
        $old_row = $stmt_old->fetch(PDO::FETCH_ASSOC);
        if (!empty($old_row['profile_photo'])) {
            $old_file = __DIR__ . '/' . ltrim($old_row['profile_photo'], '/');
            if (file_exists($old_file)) @unlink($old_file);
        }

        $filename = 'profile_' . $student_id . '.' . $ext;   // fixed name — always overwrites
        if (move_uploaded_file($file['tmp_name'], $dest_dir . $filename)) {
            $photo_path   = 'uploads/profiles/' . $filename;
            $photo_sql    = ', profile_photo = ?';
            $photo_params = [$photo_path];
        }
    }

    // ── Execute UPDATE ────────────────────────────
    $params = array_merge(
        [$last_name, $first_name, $middle_name, $course, $year_level, $email, $address],
        $photo_params,
        $password_params,
        [$student_id]
    );

    $stmt = $pdo->prepare("
        UPDATE students
        SET last_name   = ?,
            first_name  = ?,
            middle_name = ?,
            course      = ?,
            year_level  = ?,
            email       = ?,
            address     = ?
            {$photo_sql}
            {$password_sql}
        WHERE id = ?
    ");
    $stmt->execute($params);

    // ── Refresh session ───────────────────────────
    $_SESSION['student_name'] = $first_name . ' ' . $last_name;
    $_SESSION['course']       = $course;
    if (!empty($photo_path)) {
        $_SESSION['profile_photo'] = $photo_path;
    }
    // (remove_photo already cleared $_SESSION['profile_photo'] above)

    echo json_encode([
        'success'      => true,
        'message'      => 'Profile updated successfully!',
        'student_name' => $_SESSION['student_name'],
        'course'       => $course,
        'year_level'   => $year_level,
        'email'        => $email,
        'address'      => $address,
        'middle_name'  => $middle_name,
        'photo'        => $photo_path ?: null,
        'photo_removed'=> $remove_photo,
    ]);
    exit;
}
/* ═══════════════════════════════════════════════ */

// ── Fetch full student record for page render ──
$student = [];
if (!$is_admin) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$student_name  = isset($student['first_name'])
    ? $student['first_name'] . ' ' . $student['last_name']
    : ($_SESSION['student_name'] ?? 'Student');

$id_number     = $student['id_number']    ?? ($_SESSION['id_number'] ?? '--------');
$course        = $student['course']       ?? ($_SESSION['course']    ?? 'BSIT');
$first_name    = $student['first_name']   ?? explode(' ', $student_name)[0];
$last_name     = $student['last_name']    ?? '';
$middle_name   = $student['middle_name']  ?? '';
$year_level    = (int)($student['year_level'] ?? 1);
$email         = $student['email']        ?? '';
$address       = $student['address']      ?? '';
$profile_photo = $student['profile_photo'] ?? ($_SESSION['profile_photo'] ?? '');

// Sessions — replace with real DB query when sessions table is ready
$sessions_left = 30;
$sessions_used = 24;

// Trust the DB value directly — file_exists() breaks if XAMPP path doesn't align
$photo_exists = !empty($profile_photo);
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
/* ============================================
   CCS Dashboard — dashboard.css
   Sidebar layout · Cards · Tables · Modals
   ============================================ */

/* ── LAYOUT ─────────────────────────────────── */
.dash-body {
  display: flex;
  min-height: 100vh;
  background: #f4f2fb;
  overflow-x: hidden;
}

/* ── SIDEBAR ─────────────────────────────────── */
.sidebar {
  width: 240px;
  min-width: 240px;
  background: #ffffff;
  border-right: 1px solid #ece9f8;
  display: flex;
  flex-direction: column;
  height: 100vh;
  position: sticky;
  top: 0;
  z-index: 300;
  box-shadow: 2px 0 20px rgba(108,63,207,.06);
  transition: transform .28s cubic-bezier(.4,0,.2,1);
}

/* Brand */
.sb-brand {
  display: flex;
  align-items: center;
  gap: .7rem;
  padding: 1.4rem 1.2rem 1rem;
  border-bottom: 1px solid #ece9f8;
}

.sb-logo {
  width: 38px;
  height: 38px;
  object-fit: contain;
  flex-shrink: 0;
}

.sb-brand-text { display: flex; flex-direction: column; gap: .05rem; }

.sb-title {
  font-family: var(--ff);
  font-size: .9rem;
  font-weight: 800;
  color: var(--text);
  line-height: 1.2;
}

.sb-sub {
  font-size: .68rem;
  color: var(--muted);
  font-weight: 500;
}

/* Nav links */
.sb-nav { padding: .8rem .7rem 0; flex: 1; }

.sb-nav ul { list-style: none; display: flex; flex-direction: column; gap: .15rem; }

.sb-link {
  display: flex;
  align-items: center;
  gap: .8rem;
  padding: .62rem .9rem;
  border-radius: 10px;
  text-decoration: none;
  color: #4b5563;
  font-size: .875rem;
  font-weight: 500;
  transition: all .2s ease;
  position: relative;
}

.sb-link:hover {
  background: #f3f0ff;
  color: var(--purple-mid);
}

.sb-link.active {
  background: linear-gradient(135deg, #ede9fe, #f5f3ff);
  color: var(--purple-mid);
  font-weight: 700;
  box-shadow: 0 2px 8px rgba(108,63,207,.1);
}

.sb-link.active::before {
  content: '';
  position: absolute;
  left: 0; top: 20%; bottom: 20%;
  width: 3px;
  background: var(--purple-mid);
  border-radius: 0 3px 3px 0;
}

.sb-icon {
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 7px;
  font-size: .85rem;
  flex-shrink: 0;
  background: transparent;
  transition: background .2s;
}

.sb-link.active .sb-icon { background: rgba(108,63,207,.12); }
.sb-link:hover .sb-icon  { background: rgba(108,63,207,.08); }

.sb-label { font-size: .875rem; }

/* Spacer */
.sb-spacer { flex: 1; }

/* Bottom user section */
.sb-user-section {
  padding: .7rem .7rem 1rem;
  border-top: 1px solid #ece9f8;
  position: relative;
}

.sb-user-btn {
  display: flex;
  align-items: center;
  gap: .7rem;
  padding: .55rem .65rem;
  border-radius: 10px;
  cursor: pointer;
  transition: background .2s;
}

.sb-user-btn:hover { background: #f3f0ff; }

.sb-avatar {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--purple-mid), var(--purple-light));
  color: #fff;
  font-family: var(--ff);
  font-size: .95rem;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 2px 8px rgba(108,63,207,.35);
}

.sb-avatar.sm { width: 30px; height: 30px; font-size: .78rem; border-radius: 8px; }

.sb-user-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.sb-user-name {
  font-size: .82rem;
  font-weight: 700;
  color: var(--text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sb-user-id {
  font-size: .7rem;
  color: var(--muted);
}

.sb-chevron {
  font-size: .65rem;
  color: var(--muted);
  transition: transform .2s;
  flex-shrink: 0;
}

.sb-chevron.open { transform: rotate(180deg); }

/* User popup menu */
.sb-user-menu {
  position: absolute;
  bottom: calc(100% + .3rem);
  left: .7rem;
  right: .7rem;
  background: var(--white);
  border: 1px solid #ece9f8;
  border-radius: 12px;
  box-shadow: 0 8px 32px rgba(0,0,0,.12);
  overflow: hidden;
  display: none;
  z-index: 400;
  animation: fadeUp .18s ease;
}

.sb-user-menu.open { display: block; }

.sb-menu-item {
  display: flex;
  align-items: center;
  gap: .6rem;
  padding: .7rem 1rem;
  font-size: .85rem;
  font-weight: 500;
  color: #374151;
  text-decoration: none;
  transition: background .15s;
}

.sb-menu-item i { font-size: .85rem; width: 16px; text-align: center; color: var(--purple-mid); }
.sb-menu-item:hover { background: #f9fafb; }
.sb-menu-item.danger { color: #ef4444; }
.sb-menu-item.danger i { color: #ef4444; }
.sb-menu-item.danger:hover { background: #fef2f2; }

.sb-menu-divider {
  height: 1px;
  background: #f0ecff;
  margin: 0;
}

/* ── MOBILE TOPBAR ───────────────────────────── */
.dash-topbar {
  display: none;
  align-items: center;
  justify-content: space-between;
  height: 56px;
  padding: 0 1rem;
  background: var(--white);
  border-bottom: 1px solid #ece9f8;
  position: fixed;
  top: 0; left: 0; right: 0;
  z-index: 250;
  box-shadow: 0 2px 10px rgba(0,0,0,.06);
}

.dash-topbar-brand {
  display: flex;
  align-items: center;
  gap: .5rem;
  font-family: var(--ff);
  font-size: .9rem;
  font-weight: 800;
  color: var(--text);
}

.sb-toggle {
  background: none;
  border: none;
  font-size: 1.1rem;
  color: var(--text);
  cursor: pointer;
  padding: .4rem;
  border-radius: 8px;
  transition: background .15s;
}

.sb-toggle:hover { background: #f3f0ff; }

/* Sidebar overlay for mobile */
.sidebar-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.35);
  z-index: 290;
}

/* ── MAIN CONTENT ────────────────────────────── */
.dash-main {
  flex: 1;
  min-width: 0;
  padding: 1.8rem 2rem;
  overflow-y: auto;
}

/* Pages */
.dash-page { display: none; }
.dash-page.active { display: block; animation: fadeUp .3s ease both; }

/* ── PAGE HEADER ─────────────────────────────── */
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 1.5rem;
  flex-wrap: wrap;
  gap: 1rem;
}

.page-title {
  font-family: var(--ff);
  font-size: 1.55rem;
  font-weight: 800;
  color: var(--text);
  letter-spacing: -.025em;
  line-height: 1.2;
}

.page-sub {
  color: var(--muted);
  font-size: .875rem;
  margin-top: .2rem;
}

.page-actions {
  display: flex;
  align-items: center;
  gap: .75rem;
  flex-wrap: wrap;
}

/* ── WELCOME BANNER ──────────────────────────── */
.welcome-banner {
  background: linear-gradient(135deg, var(--purple-dark) 0%, var(--purple-mid) 60%, var(--purple-light) 100%);
  border-radius: 18px;
  padding: 1.8rem 2rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.5rem;
  flex-wrap: wrap;
  gap: 1rem;
  position: relative;
  overflow: hidden;
  box-shadow: 0 8px 32px rgba(108,63,207,.35);
}

.welcome-banner::after {
  content: '';
  position: absolute;
  right: -40px; top: -40px;
  width: 200px; height: 200px;
  background: rgba(255,255,255,.05);
  border-radius: 50%;
  pointer-events: none;
}

.welcome-eyebrow {
  font-size: .82rem;
  color: rgba(255,255,255,.75);
  font-weight: 600;
  margin-bottom: .3rem;
}

.welcome-title {
  font-family: var(--ff);
  font-size: 1.6rem;
  font-weight: 800;
  color: #fff;
  letter-spacing: -.025em;
  line-height: 1.2;
}

.welcome-title span { color: var(--yellow-light); }

.welcome-sub {
  font-size: .86rem;
  color: rgba(255,255,255,.7);
  margin-top: .4rem;
}

.welcome-badge {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: .35rem;
  z-index: 1;
}

.badge-course {
  background: var(--yellow);
  color: #1a1a00;
  font-family: var(--ff);
  font-size: .82rem;
  font-weight: 800;
  padding: .3rem .8rem;
  border-radius: 8px;
  letter-spacing: .05em;
}

.badge-id {
  font-size: .8rem;
  color: rgba(255,255,255,.7);
  font-family: monospace;
  letter-spacing: .1em;
}

/* ── STAT CARDS ──────────────────────────────── */
.stat-cards {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.stat-card {
  border-radius: 14px;
  padding: 1.2rem 1.3rem;
  display: flex;
  align-items: center;
  gap: 1rem;
  position: relative;
  overflow: hidden;
  transition: transform .2s, box-shadow .2s;
  cursor: default;
}

.stat-card:hover { transform: translateY(-2px); }

.stat-card.purple {
  background: linear-gradient(135deg, #f3f0ff, #ede9fe);
  border: 1px solid #ddd6fe;
}

.stat-card.yellow {
  background: linear-gradient(135deg, #fffbeb, #fef9c3);
  border: 1px solid #fde68a;
}

.stat-card.green {
  background: linear-gradient(135deg, #f0fdf4, #dcfce7);
  border: 1px solid #bbf7d0;
}

.stat-card.pink {
  background: linear-gradient(135deg, #fdf2f8, #fce7f3);
  border: 1px solid #f9a8d4;
}

.sc-icon {
  width: 42px;
  height: 42px;
  border-radius: 11px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
  flex-shrink: 0;
}

.stat-card.purple .sc-icon { background: rgba(108,63,207,.15); color: var(--purple-mid); }
.stat-card.yellow .sc-icon { background: rgba(245,197,24,.2);  color: #d97706; }
.stat-card.green  .sc-icon { background: rgba(34,197,94,.15);  color: #16a34a; }
.stat-card.pink   .sc-icon { background: rgba(217,70,239,.15); color: #c026d3; }

.sc-value {
  font-family: var(--ff);
  font-size: 1.7rem;
  font-weight: 800;
  color: var(--text);
  line-height: 1;
}

.sc-label {
  font-size: .74rem;
  color: var(--muted);
  font-weight: 600;
  margin-top: .2rem;
}

.sc-bg-icon {
  position: absolute;
  right: -5px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 3.5rem;
  opacity: .05;
  pointer-events: none;
  color: var(--text);
}

/* ── HOME GRID ───────────────────────────────── */
.home-grid {
  display: grid;
  grid-template-columns: 1.1fr 0.9fr;
  gap: 1.25rem;
}

/* ── DASH CARD ───────────────────────────────── */
.dash-card {
  background: var(--white);
  border-radius: 16px;
  padding: 1.3rem 1.5rem;
  box-shadow: 0 2px 12px rgba(0,0,0,.05);
  border: 1px solid #ece9f8;
}

.dash-card.no-pad { padding: 0; }
.dash-card.no-pad .card-header { padding: 1.1rem 1.5rem; }

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
}

.card-header h2 {
  font-family: var(--ff);
  font-size: .95rem;
  font-weight: 800;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: .5rem;
}

.card-header h2 i { color: var(--purple-mid); font-size: .88rem; }

.card-badge {
  background: var(--yellow);
  color: #1a1a00;
  font-size: .68rem;
  font-weight: 800;
  padding: .18rem .55rem;
  border-radius: 6px;
  letter-spacing: .03em;
}

/* ── ANNOUNCEMENTS ───────────────────────────── */
.announcement-list { list-style: none; display: flex; flex-direction: column; gap: .3rem; }

.ann-item {
  display: flex;
  gap: .75rem;
  padding: .7rem .8rem;
  border-radius: 10px;
  transition: background .15s;
}

.ann-item:hover { background: #fafafa; }
.ann-item.unread { background: #f9f7ff; }

.ann-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--purple-mid);
  flex-shrink: 0;
  margin-top: .35rem;
  box-shadow: 0 0 0 2px rgba(108,63,207,.2);
}

.ann-dot.read { background: #d1d5db; box-shadow: none; }

.ann-title {
  font-size: .85rem;
  font-weight: 700;
  color: var(--text);
  line-height: 1.3;
}

.ann-desc {
  font-size: .78rem;
  color: var(--muted);
  line-height: 1.5;
  margin-top: .15rem;
}

.ann-time {
  font-size: .7rem;
  color: #9ca3af;
  display: block;
  margin-top: .25rem;
}

/* ── LAB STATUS ──────────────────────────────── */
.status-live {
  display: flex;
  align-items: center;
  gap: .4rem;
  font-size: .72rem;
  font-weight: 700;
  color: #16a34a;
  background: #dcfce7;
  padding: .2rem .6rem;
  border-radius: 20px;
}

.live-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #16a34a;
  animation: pulse 1.5s infinite;
}

.lab-list { display: flex; flex-direction: column; gap: .9rem; }

.lab-item {}

.lab-info {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: .35rem;
}

.lab-name { font-size: .84rem; font-weight: 700; color: var(--text); }
.lab-computers { font-size: .72rem; color: var(--muted); }

.lab-bar-wrap {
  height: 6px;
  background: #f0ecff;
  border-radius: 6px;
  overflow: hidden;
  margin-bottom: .3rem;
}

.lab-bar {
  height: 100%;
  width: var(--fill);
  background: var(--color);
  border-radius: 6px;
  animation: barGrow .7s cubic-bezier(.4,0,.2,1) both;
  transform-origin: left;
}

.lab-meta {
  display: flex;
  justify-content: space-between;
  font-size: .72rem;
}

.lab-used { color: var(--muted); }
.lab-free { font-weight: 700; }
.lab-free.open { color: #16a34a; }
.lab-free.full { color: var(--red); }

/* ── HISTORY PAGE ────────────────────────────── */
.history-summary {
  display: flex;
  gap: 1rem;
  margin-bottom: 1.25rem;
  flex-wrap: wrap;
}

.hs-item {
  flex: 1;
  min-width: 140px;
  background: var(--white);
  border-radius: 12px;
  padding: .85rem 1.1rem;
  display: flex;
  align-items: center;
  gap: .75rem;
  border: 1px solid #ece9f8;
  box-shadow: 0 1px 6px rgba(0,0,0,.04);
}

.hs-item i { font-size: 1.1rem; color: var(--purple-mid); }

.hs-val {
  font-family: var(--ff);
  font-size: 1.15rem;
  font-weight: 800;
  color: var(--text);
  display: block;
  line-height: 1.1;
}

.hs-lbl {
  font-size: .7rem;
  color: var(--muted);
  font-weight: 600;
  display: block;
}

/* Search */
.search-wrap {
  position: relative;
  display: flex;
  align-items: center;
}

.search-wrap i {
  position: absolute;
  left: .75rem;
  color: #9ca3af;
  font-size: .82rem;
  pointer-events: none;
}

.search-input {
  padding: .5rem .9rem .5rem 2.2rem;
  border: 1.5px solid var(--border);
  border-radius: 9px;
  font-family: var(--fb);
  font-size: .84rem;
  color: var(--text);
  background: #fafafa;
  outline: none;
  width: 200px;
  transition: border-color .2s, box-shadow .2s;
}

.search-input:focus {
  border-color: var(--purple-mid);
  box-shadow: 0 0 0 3px rgba(108,63,207,.1);
  background: var(--white);
  width: 240px;
}

/* Table */
.table-wrap { overflow-x: auto; }

.history-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .84rem;
}

.history-table thead tr {
  background: #fafafa;
  border-bottom: 2px solid #ece9f8;
}

.history-table th {
  padding: .75rem 1rem;
  text-align: left;
  font-size: .72rem;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .06em;
  white-space: nowrap;
}

.history-table td {
  padding: .75rem 1rem;
  border-bottom: 1px solid #f4f2fb;
  color: var(--text);
  white-space: nowrap;
}

.history-table tbody tr:hover { background: #faf9ff; }
.history-table tbody tr:last-child td { border-bottom: none; }

.td-num { color: var(--muted); font-size: .8rem; }

.lab-tag {
  background: #ede9fe;
  color: var(--purple-mid);
  font-size: .74rem;
  font-weight: 700;
  padding: .2rem .55rem;
  border-radius: 6px;
}

.purpose-tag {
  background: #fef9c3;
  color: #92400e;
  font-size: .74rem;
  font-weight: 600;
  padding: .2rem .55rem;
  border-radius: 6px;
}

.status-badge {
  font-size: .72rem;
  font-weight: 700;
  padding: .22rem .6rem;
  border-radius: 6px;
  letter-spacing: .03em;
}

.status-badge.completed { background: #dcfce7; color: #15803d; }
.status-badge.cancelled { background: #fee2e2; color: #b91c1c; }
.status-badge.pending   { background: #fef9c3; color: #92400e; }

.table-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: .85rem 1.5rem;
  border-top: 1px solid #f4f2fb;
}

.tf-count { font-size: .8rem; color: var(--muted); }

.pagination { display: flex; gap: .3rem; }

.pg-btn {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1.5px solid var(--border);
  background: var(--white);
  font-size: .8rem;
  font-weight: 600;
  color: var(--muted);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all .15s;
}

.pg-btn:hover { border-color: var(--purple-mid); color: var(--purple-mid); background: #f3f0ff; }
.pg-btn.active { background: var(--purple-mid); color: #fff; border-color: var(--purple-mid); }

/* ── RESERVATION PAGE ────────────────────────── */
.reservation-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.1rem;
  margin-bottom: 0;
}

.reserve-card {
  background: var(--white);
  border-radius: 16px;
  border: 1.5px solid #ece9f8;
  padding: 1.2rem;
  box-shadow: 0 2px 10px rgba(0,0,0,.04);
  display: flex;
  flex-direction: column;
  gap: .8rem;
}

.active-reserve  { border-color: #c4b5fd; }
.pending-reserve { border-color: #fde68a; }

.rc-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.rc-status {
  display: flex;
  align-items: center;
  gap: .4rem;
  font-size: .74rem;
  font-weight: 700;
  padding: .2rem .6rem;
  border-radius: 20px;
}

.rc-status.active  { background: #dcfce7; color: #15803d; }
.rc-status.pending { background: #fef9c3; color: #92400e; }

.rc-status i { font-size: .65rem; }

.rc-id { font-size: .74rem; color: var(--muted); font-family: monospace; }

.rc-lab {
  display: flex;
  align-items: center;
  gap: .5rem;
  font-family: var(--ff);
  font-size: .92rem;
  font-weight: 800;
  color: var(--text);
}

.rc-lab i { color: var(--purple-mid); }

.rc-details { display: flex; flex-direction: column; gap: .4rem; }

.rc-detail {
  display: flex;
  align-items: center;
  gap: .55rem;
  font-size: .82rem;
  color: #4b5563;
}

.rc-detail i { color: var(--purple-light); width: 14px; text-align: center; }

.rc-footer {
  display: flex;
  gap: .6rem;
  margin-top: auto;
}

/* New reservation CTA card */
.new-reserve-cta {
  align-items: center;
  justify-content: center;
  text-align: center;
  border: 2px dashed #ddd6fe;
  background: #faf8ff;
  cursor: pointer;
  transition: all .2s;
  padding: 2rem 1.2rem;
  gap: .6rem;
}

.new-reserve-cta:hover { border-color: var(--purple-mid); background: #f3f0ff; }

.new-reserve-cta i { font-size: 2rem; color: #c4b5fd; }

.new-reserve-cta p {
  font-family: var(--ff);
  font-size: .95rem;
  font-weight: 800;
  color: var(--purple-mid);
}

.new-reserve-cta span { font-size: .78rem; color: var(--muted); }

/* Slot filter */
.slot-filter { display: flex; gap: .3rem; }

.sf-btn {
  padding: .28rem .7rem;
  border-radius: 7px;
  border: 1.5px solid var(--border);
  background: var(--white);
  font-size: .76rem;
  font-weight: 600;
  color: var(--muted);
  cursor: pointer;
  transition: all .15s;
}

.sf-btn:hover { border-color: var(--purple-mid); color: var(--purple-mid); }
.sf-btn.active { background: var(--purple-mid); color: #fff; border-color: var(--purple-mid); }

/* Slots grid */
.slots-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: .85rem;
  margin-top: .3rem;
}

.slot-item {
  border-radius: 12px;
  padding: 1rem;
  border: 1.5px solid #ece9f8;
  background: #fafafa;
  display: flex;
  flex-direction: column;
  gap: .4rem;
  transition: all .18s;
}

.slot-item.open   { border-color: #bbf7d0; background: #f0fdf4; }
.slot-item.low    { border-color: #fde68a; background: #fffbeb; }
.slot-item.full   { border-color: #fecaca; background: #fef2f2; opacity: .75; }

.slot-lab { font-family: var(--ff); font-size: .82rem; font-weight: 800; color: var(--text); }
.slot-time { font-size: .77rem; color: var(--muted); }

.slot-avail { display: flex; gap: .3rem; flex-wrap: wrap; }

.slot-badge {
  font-size: .7rem;
  font-weight: 700;
  padding: .15rem .5rem;
  border-radius: 5px;
}

.slot-badge.open { background: #dcfce7; color: #15803d; }
.slot-badge.low  { background: #fef9c3; color: #92400e; }
.slot-badge.full { background: #fee2e2; color: #b91c1c; }

.slot-btn {
  margin-top: .2rem;
  width: 100%;
  padding: .4rem;
  border-radius: 8px;
  border: none;
  background: var(--purple-mid);
  color: #fff;
  font-size: .78rem;
  font-weight: 700;
  cursor: pointer;
  transition: all .15s;
}

.slot-btn:hover { background: var(--purple-dark); transform: translateY(-1px); }
.slot-btn.disabled { background: #e5e7eb; color: #9ca3af; cursor: not-allowed; transform: none; }

/* ── PROFILE PAGE ────────────────────────────── */
.profile-layout {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 1.25rem;
  align-items: flex-start;
}

.profile-avatar-card {
  background: var(--white);
  border-radius: 20px;
  border: 1px solid #ece9f8;
  padding: 1.8rem 1.4rem 1.4rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: .55rem;
  box-shadow: 0 2px 16px rgba(108,63,207,.07);
  position: sticky;
  top: 1.8rem;
}

/* ── Photo upload widget ── */
.pav-photo-wrap {
  position: relative;
  width: 96px;
  height: 96px;
  margin-bottom: .2rem;
}

.pav-photo-img {
  width: 96px;
  height: 96px;
  border-radius: 22px;
  object-fit: cover;
  border: 3px solid #ede9fe;
  box-shadow: 0 4px 18px rgba(108,63,207,.22);
  display: none;
}

.pav-photo-img.visible { display: block; }

.pav-photo-initials {
  width: 96px;
  height: 96px;
  border-radius: 22px;
  background: linear-gradient(135deg, var(--purple-dark), var(--purple-mid));
  color: #fff;
  font-family: var(--ff);
  font-size: 2.2rem;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 6px 24px rgba(108,63,207,.38);
  transition: opacity .2s;
}

.pav-photo-initials.hidden { display: none; }

.pav-camera-btn {
  position: absolute;
  bottom: -6px;
  right: -6px;
  width: 30px;
  height: 30px;
  border-radius: 9px;
  background: var(--purple-mid);
  color: #fff;
  border: 2.5px solid #fff;
  font-size: .72rem;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 8px rgba(108,63,207,.4);
  transition: background .15s, transform .15s;
}

.pav-camera-btn:hover {
  background: var(--purple-dark);
  transform: scale(1.1);
}

/* Upload zone — shown below avatar */
.pav-upload-zone {
  width: 100%;
  border: 1.5px dashed #c4b5fd;
  border-radius: 12px;
  padding: .6rem .5rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: .25rem;
  cursor: pointer;
  transition: all .18s;
  background: transparent;
  margin-top: .2rem;
}

.pav-upload-zone:hover {
  background: #f5f3ff;
  border-color: var(--purple-mid);
}

.pav-upload-label {
  font-size: .75rem;
  font-weight: 700;
  color: var(--purple-mid);
  display: flex;
  align-items: center;
  gap: .35rem;
  cursor: pointer;
}

.pav-upload-hint {
  font-size: .68rem;
  color: #9ca3af;
}

.pav-remove-btn {
  font-size: .72rem;
  font-weight: 600;
  color: var(--red);
  background: none;
  border: none;
  cursor: pointer;
  display: none;
  align-items: center;
  gap: .3rem;
  padding: .2rem .5rem;
  border-radius: 6px;
  transition: background .15s;
  margin-top: .15rem;
}

.pav-remove-btn.visible {
  display: inline-flex;
}

.pav-remove-btn:hover { background: #fee2e2; }

#profilePhotoInput { display: none; }

/* Student info below photo */
.pav-name {
  font-family: var(--ff);
  font-size: 1.02rem;
  font-weight: 800;
  color: var(--text);
  line-height: 1.2;
  margin-top: .15rem;
}

.pav-course {
  font-size: .78rem;
  color: var(--muted);
  font-weight: 600;
}

.pav-tags {
  display: flex;
  flex-direction: column;
  gap: .3rem;
  width: 100%;
  margin-top: .1rem;
}

.ptag {
  background: #f3f0ff;
  color: var(--purple-mid);
  font-size: .75rem;
  font-weight: 700;
  padding: .32rem .7rem;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: .4rem;
  justify-content: center;
}

.pav-sessions {
  display: flex;
  align-items: center;
  gap: 1rem;
  background: linear-gradient(135deg, #ede9fe, #f5f3ff);
  border-radius: 13px;
  padding: .8rem 1rem;
  margin-top: .2rem;
  width: 100%;
}

.psi-item { flex: 1; text-align: center; }
.psi-val  { font-family: var(--ff); font-size: 1.3rem; font-weight: 800; color: var(--purple-mid); display: block; }
.psi-lbl  { font-size: .68rem; color: var(--muted); font-weight: 600; display: block; margin-top: .1rem; }
.psi-divider { width: 1px; height: 32px; background: #ddd6fe; }

/* ── Profile info list (email/address under tags) ── */
.pav-info-list {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: .3rem;
  margin-top: .1rem;
}

.pav-info-item {
  display: flex;
  align-items: flex-start;
  gap: .5rem;
  font-size: .77rem;
  color: #4b5563;
  text-align: left;
  line-height: 1.4;
}

.pav-info-item i {
  color: var(--purple-mid);
  font-size: .72rem;
  flex-shrink: 0;
  margin-top: .15rem;
  width: 14px;
  text-align: center;
}

/* Save status inline */
.profile-save-status {
  font-size: .8rem;
  font-weight: 600;
  display: none;
  align-items: center;
  gap: .35rem;
}

.profile-save-status.success { color: #16a34a; display: flex; }
.profile-save-status.error   { color: #ef4444; display: flex; }

.profile-form-card {
  background: var(--white);
  border-radius: 20px;
  border: 1px solid #ece9f8;
  padding: 1.5rem 1.7rem;
  box-shadow: 0 2px 16px rgba(0,0,0,.05);
}

.profile-section-title {
  display: flex;
  align-items: center;
  gap: .5rem;
  font-family: var(--ff);
  font-size: .84rem;
  font-weight: 800;
  color: var(--text);
  margin-top: .3rem;
  padding-top: 1rem;
  border-top: 1px solid #ece9f8;
}

.profile-section-title i { color: var(--purple-mid); }
.section-opt { font-weight: 400; color: var(--muted); font-size: .78rem; font-family: var(--fb); }

.input-disabled {
  background: #f9fafb !important;
  color: var(--muted) !important;
  cursor: not-allowed;
}

/* ── BUTTONS ─────────────────────────────────── */
.btn-outline-sm {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  padding: .42rem .9rem;
  border-radius: 8px;
  border: 1.5px solid var(--border);
  background: var(--white);
  font-size: .8rem;
  font-weight: 600;
  color: #4b5563;
  cursor: pointer;
  text-decoration: none;
  transition: all .15s;
  font-family: var(--fb);
}

.btn-outline-sm:hover { border-color: var(--purple-mid); color: var(--purple-mid); background: #f3f0ff; }
.btn-outline-sm.danger:hover { border-color: var(--red); color: var(--red); background: #fef2f2; }

.btn-primary-sm {
  display: inline-flex;
  align-items: center;
  gap: .45rem;
  padding: .5rem 1.1rem;
  border-radius: 9px;
  border: none;
  background: linear-gradient(135deg, var(--purple-mid), var(--purple-light));
  color: #fff;
  font-size: .84rem;
  font-weight: 700;
  cursor: pointer;
  transition: all .18s;
  font-family: var(--fb);
  box-shadow: 0 3px 12px rgba(108,63,207,.3);
}

.btn-primary-sm:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(108,63,207,.4); }

/* ── MODAL ───────────────────────────────────── */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.4);
  z-index: 500;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  backdrop-filter: blur(2px);
}

.modal-overlay.open { display: flex; }

.modal-card {
  background: var(--white);
  border-radius: 20px;
  padding: 1.8rem;
  width: 100%;
  max-width: 520px;
  box-shadow: 0 24px 64px rgba(0,0,0,.2);
  animation: fadeUp .25s ease both;
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.3rem;
}

.modal-header h3 {
  font-family: var(--ff);
  font-size: 1.1rem;
  font-weight: 800;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: .5rem;
}

.modal-header h3 i { color: var(--purple-mid); }

.modal-close {
  width: 32px; height: 32px;
  border-radius: 8px;
  border: none;
  background: #f3f4f6;
  color: var(--muted);
  font-size: .9rem;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all .15s;
}

.modal-close:hover { background: #fee2e2; color: var(--red); }

/* ── RESPONSIVE ──────────────────────────────── */
@media (max-width: 1100px) {
  .stat-cards { grid-template-columns: repeat(2, 1fr); }
  .reservation-grid { grid-template-columns: repeat(2, 1fr); }
  .profile-layout { grid-template-columns: 1fr; }
  .profile-avatar-card { position: static; flex-direction: row; text-align: left; flex-wrap: wrap; align-items: center; }
  .pav-photo-wrap { flex-shrink: 0; }
  .pav-upload-zone, .pav-remove-btn, .pav-tags, .pav-sessions { width: 100%; }
}

@media (max-width: 900px) {
  .home-grid { grid-template-columns: 1fr; }
}

@media (max-width: 768px) {
  .dash-topbar { display: flex; }

  .sidebar {
    position: fixed;
    left: 0; top: 0; bottom: 0;
    transform: translateX(-100%);
    z-index: 310;
  }

  .sidebar.open { transform: translateX(0); }
  .sidebar-overlay { display: block; opacity: 0; pointer-events: none; transition: opacity .25s; }
  .sidebar-overlay.open { opacity: 1; pointer-events: auto; }

  .dash-main { padding: 4.5rem 1rem 2rem; }
  .reservation-grid { grid-template-columns: 1fr; }
  .stat-cards { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 500px) {
  .stat-cards { grid-template-columns: 1fr 1fr; }
  .history-summary { flex-direction: column; }
  .welcome-title { font-size: 1.25rem; }
  .page-header { flex-direction: column; }
  .slot-filter { flex-wrap: wrap; }
}



/* ── ADMIN SIDEBAR SEPARATOR ─────────────────── */
.sb-section-label {
  font-size: .65rem;
  font-weight: 700;
  color: #9ca3af;
  letter-spacing: .08em;
  text-transform: uppercase;
  padding: .75rem .9rem .3rem;
}

/* ── ADMIN SEARCH PAGE ───────────────────────── */
.admin-search-wrap {
  max-width: 560px;
  margin: 0 auto 2rem;
}

.admin-search-box {
  display: flex;
  gap: .6rem;
  background: var(--white);
  border: 2px solid #ede9fe;
  border-radius: 14px;
  padding: .55rem .7rem;
  box-shadow: 0 4px 20px rgba(108,63,207,.08);
  transition: border-color .2s;
}

.admin-search-box:focus-within { border-color: var(--purple-mid); }

.admin-search-box i {
  color: var(--purple-mid);
  font-size: 1rem;
  align-self: center;
  padding: 0 .3rem;
}

.admin-search-input {
  flex: 1;
  border: none;
  outline: none;
  font-size: .95rem;
  font-family: var(--fb);
  color: var(--text);
  background: transparent;
  font-weight: 500;
}

.admin-search-input::placeholder { color: #9ca3af; font-weight: 400; }

.admin-search-btn {
  padding: .5rem 1.2rem;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--purple-mid), var(--purple-light));
  color: #fff;
  border: none;
  font-size: .85rem;
  font-weight: 700;
  font-family: var(--fb);
  cursor: pointer;
  white-space: nowrap;
  transition: all .18s;
  box-shadow: 0 3px 10px rgba(108,63,207,.3);
}

.admin-search-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(108,63,207,.4); }

.search-hint {
  text-align: center;
  font-size: .78rem;
  color: var(--muted);
  margin-top: .6rem;
}

/* recent searches */
.recent-searches {
  margin-top: 1.5rem;
}

.recent-title {
  font-size: .8rem;
  font-weight: 700;
  color: var(--muted);
  margin-bottom: .7rem;
  display: flex;
  align-items: center;
  gap: .4rem;
}

.recent-chips {
  display: flex;
  flex-wrap: wrap;
  gap: .5rem;
}

.recent-chip {
  background: var(--white);
  border: 1.5px solid #ede9fe;
  border-radius: 8px;
  padding: .3rem .75rem;
  font-size: .78rem;
  font-weight: 600;
  color: var(--purple-mid);
  cursor: pointer;
  transition: all .15s;
  display: flex;
  align-items: center;
  gap: .4rem;
}

.recent-chip:hover { background: #f3f0ff; border-color: var(--purple-mid); }

/* empty/no result state */
.search-empty {
  text-align: center;
  padding: 2.5rem 1rem;
  color: var(--muted);
}

.search-empty i { font-size: 2.5rem; color: #ddd6fe; display: block; margin-bottom: .7rem; }
.search-empty p { font-size: .9rem; }

/* ── STUDENT DETAIL MODAL ────────────────────── */
.student-modal-card {
  max-width: 580px;
}

.student-modal-profile {
  display: flex;
  align-items: center;
  gap: 1.1rem;
  padding: 1rem 1.2rem;
  background: linear-gradient(135deg, #f5f3ff, #ede9fe);
  border-radius: 14px;
  margin-bottom: 1.2rem;
}

.smp-avatar {
  width: 60px;
  height: 60px;
  border-radius: 14px;
  background: linear-gradient(135deg, var(--purple-mid), var(--purple-light));
  color: #fff;
  font-family: var(--ff);
  font-size: 1.4rem;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 4px 12px rgba(108,63,207,.35);
  overflow: hidden;
}

.smp-avatar img { width: 100%; height: 100%; object-fit: cover; }

.smp-info { flex: 1; min-width: 0; }
.smp-name { font-family: var(--ff); font-size: 1.05rem; font-weight: 800; color: var(--text); line-height: 1.2; }
.smp-course { font-size: .78rem; font-weight: 600; color: var(--purple-mid); margin-top: .15rem; }
.smp-id { font-size: .75rem; color: var(--muted); font-family: monospace; letter-spacing: .05em; margin-top: .1rem; }

.smp-sessions {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: .2rem;
}

.smp-sess-val {
  font-family: var(--ff);
  font-size: 1.4rem;
  font-weight: 800;
  color: var(--purple-mid);
  line-height: 1;
}

.smp-sess-lbl { font-size: .68rem; color: var(--muted); font-weight: 600; text-align: right; }

.student-info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .6rem;
  margin-bottom: 1.1rem;
}

.sinfo-item {
  background: #fafafa;
  border: 1px solid #ece9f8;
  border-radius: 10px;
  padding: .6rem .85rem;
}

.sinfo-label {
  font-size: .68rem;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .05em;
  margin-bottom: .15rem;
}

.sinfo-value {
  font-size: .88rem;
  font-weight: 600;
  color: var(--text);
}

.sinfo-item.full-col { grid-column: 1 / -1; }

.student-modal-actions {
  display: flex;
  gap: .6rem;
  justify-content: flex-end;
  padding-top: .6rem;
  border-top: 1px solid #ece9f8;
}

/* ── ADMIN STATS / TABLE ─────────────────────── */
.admin-stats-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.admin-table-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .8rem;
  flex-wrap: wrap;
  margin-bottom: .9rem;
}

.admin-filter-tabs {
  display: flex;
  gap: .4rem;
}

.aft-btn {
  padding: .32rem .8rem;
  border-radius: 8px;
  border: 1.5px solid var(--border);
  background: var(--white);
  font-size: .78rem;
  font-weight: 600;
  color: #4b5563;
  cursor: pointer;
  transition: all .15s;
  font-family: var(--fb);
}

.aft-btn.active { background: #f3f0ff; border-color: var(--purple-mid); color: var(--purple-mid); }
.aft-btn:hover  { border-color: var(--purple-mid); color: var(--purple-mid); }

.view-row-btn {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  padding: .28rem .7rem;
  font-size: .75rem;
  font-weight: 600;
  color: var(--purple-mid);
  background: #f3f0ff;
  border: 1px solid #ddd6fe;
  border-radius: 7px;
  cursor: pointer;
  transition: all .15s;
}

.view-row-btn:hover { background: #ede9fe; }

/* placeholder pages */
.admin-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 4rem 2rem;
  text-align: center;
  color: var(--muted);
}

.admin-placeholder i { font-size: 3rem; color: #ddd6fe; margin-bottom: 1rem; }
.admin-placeholder h3 { font-family: var(--ff); font-size: 1.1rem; color: var(--text); margin-bottom: .4rem; }
.admin-placeholder p { font-size: .875rem; max-width: 360px; }

  </style>
</head>
<body class="dash-body">

 <!-- ── SIDEBAR ── -->
  <aside class="sidebar" id="sidebar">
    <div class="sb-brand">
      <img src="images/ccslogo.png" alt="CCS Logo" class="sb-logo"/>
      <div class="sb-brand-text">
        <span class="sb-title">CCS Sit-in System</span>
        <span class="sb-sub"><?= $is_admin ? 'Admin Panel' : 'College of Computer Studies' ?></span>
      </div>
    </div>

    <?php if ($is_admin): ?>
    <!-- ── ADMIN NAV ── -->
    <nav class="sb-nav">
      <div class="sb-section-label">Main</div>
      <ul>
        <li>
          <a href="#" class="sb-link active" data-page="admin-search">
            <span class="sb-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
            <span class="sb-label">Search</span>
          </a>
        </li>
        <li>
          <a href="#" class="sb-link" data-page="admin-students">
            <span class="sb-icon"><i class="fa-solid fa-users"></i></span>
            <span class="sb-label">Students</span>
          </a>
        </li>
      </ul>
      <div class="sb-section-label" style="margin-top:.4rem">Sit-in</div>
      <ul>
        <li>
          <a href="#" class="sb-link" data-page="admin-sitin">
            <span class="sb-icon"><i class="fa-solid fa-desktop"></i></span>
            <span class="sb-label">Sit-in</span>
          </a>
        </li>
        <li>
          <a href="#" class="sb-link" data-page="admin-sitin-records">
            <span class="sb-icon"><i class="fa-solid fa-table-list"></i></span>
            <span class="sb-label">View Sit-in Records</span>
          </a>
        </li>
        <li>
          <a href="#" class="sb-link" data-page="admin-sitin-reports">
            <span class="sb-icon"><i class="fa-solid fa-chart-bar"></i></span>
            <span class="sb-label">Sit-in Reports</span>
          </a>
        </li>
        <li>
          <a href="#" class="sb-link" data-page="admin-feedback">
            <span class="sb-icon"><i class="fa-solid fa-comment-dots"></i></span>
            <span class="sb-label">Feedback Reports</span>
          </a>
        </li>
      </ul>
      <div class="sb-section-label" style="margin-top:.4rem">Booking</div>
      <ul>
        <li>
          <a href="#" class="sb-link" data-page="admin-reservation">
            <span class="sb-icon"><i class="fa-solid fa-calendar-check"></i></span>
            <span class="sb-label">Reservation</span>
          </a>
        </li>
      </ul>
    </nav>

    <div class="sb-spacer"></div>

    <div class="sb-user-section">
      <div class="sb-user-btn" id="userMenuBtn">
        <div class="sb-avatar" style="background:linear-gradient(135deg,#dc2626,#f97316)">A</div>
        <div class="sb-user-info">
          <span class="sb-user-name">Administrator</span>
          <span class="sb-user-id">admin@ccs.edu</span>
        </div>
        <i class="fa-solid fa-chevron-up sb-chevron" id="userChevron"></i>
      </div>
      <div class="sb-user-menu" id="userMenu">
        <a href="index.php" class="sb-menu-item danger">
          <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
      </div>
    </div>

    <?php else: ?>
    <!-- ── STUDENT NAV ── -->
    <nav class="sb-nav">
      <ul>
        <li>
          <a href="#" class="sb-link active" data-page="home">
            <span class="sb-icon"><i class="fa-solid fa-house"></i></span>
            <span class="sb-label">Home</span>
          </a>
        </li>
        <li>
          <a href="#" class="sb-link" data-page="history">
            <span class="sb-icon"><i class="fa-solid fa-clock-rotate-left"></i></span>
            <span class="sb-label">History</span>
          </a>
        </li>
        <li>
          <a href="#" class="sb-link" data-page="reservation">
            <span class="sb-icon"><i class="fa-solid fa-calendar-plus"></i></span>
            <span class="sb-label">Reservation</span>
          </a>
        </li>
      </ul>
    </nav>

    <div class="sb-spacer"></div>

    <div class="sb-user-section">
      <div class="sb-user-btn" id="userMenuBtn">
        <?php if ($photo_exists): ?>
        <div class="sb-avatar" id="sbAvatarThumb" style="background:none;padding:0;overflow:hidden">
          <img src="<?= htmlspecialchars($profile_photo) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:10px" alt="avatar"/>
        </div>
        <?php else: ?>
        <div class="sb-avatar" id="sbAvatarThumb"><?= strtoupper(substr($first_name, 0, 1)) ?></div>
        <?php endif; ?>
        <div class="sb-user-info">
          <span class="sb-user-name"><?= htmlspecialchars($student_name) ?></span>
          <span class="sb-user-id"><?= htmlspecialchars($id_number) ?></span>
        </div>
        <i class="fa-solid fa-chevron-up sb-chevron" id="userChevron"></i>
      </div>
      <div class="sb-user-menu" id="userMenu">
        <a href="#" class="sb-menu-item" data-page="profile">
          <i class="fa-solid fa-user-pen"></i> Edit Profile
        </a>
        <div class="sb-menu-divider"></div>
        <a href="index.php" class="sb-menu-item danger">
          <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
      </div>
    </div>
    <?php endif; ?>

  </aside>

  <!-- ── MOBILE TOPBAR ── -->
  <header class="dash-topbar" id="dashTopbar">
    <button class="sb-toggle" id="sbToggle" aria-label="Toggle sidebar">
      <i class="fa-solid fa-bars"></i>
    </button>
    <div class="dash-topbar-brand">
      <img src="images/ccslogo.png" alt="Logo" style="width:30px;height:30px;object-fit:contain"/>
      <span>CCS Portal</span>
    </div>
    <?php if (!$is_admin && $photo_exists): ?>
    <div class="sb-avatar sm" style="background:none;padding:0;overflow:hidden">
      <img src="<?= htmlspecialchars($profile_photo) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:8px" alt="avatar"/>
    </div>
    <?php else: ?>
    <?php if (!$is_admin && $photo_exists): ?>
    <div class="sb-avatar sm" style="background:none;padding:0;overflow:hidden">
      <img src="<?= htmlspecialchars($profile_photo) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:8px" alt="avatar"/>
    </div>
    <?php else: ?>
    <div class="sb-avatar sm" <?= $is_admin ? 'style="background:linear-gradient(135deg,#dc2626,#f97316)"' : '' ?>><?= $is_admin ? 'A' : strtoupper(substr($first_name, 0, 1)) ?></div>
    <?php endif; ?>
    <?php endif; ?>
  </header>

  <!-- ── MAIN ── -->
  <main class="dash-main" id="dashMain">

  <?php if (!$is_admin): ?>
    <div class="dash-page active" id="page-home">

      <div class="welcome-banner">
        <div class="welcome-text">
          <p class="welcome-eyebrow">Good day!</p>
          <h1 class="welcome-title">Welcome back, <span><?= htmlspecialchars($first_name) ?>!</span></h1>
          <p class="welcome-sub">Here's an overview of your sit-in activity and lab status.</p>
        </div>
        <div class="welcome-badge">
          <div class="badge-course"><?= htmlspecialchars($course) ?></div>
          <div class="badge-id"><?= htmlspecialchars($id_number) ?></div>
        </div>
      </div>


      </div><!-- /.home-grid -->

    </div><!-- /#page-home -->


   
        </div>
      </div><!-- /.dash-card -->

    </div><!-- /#page-history -->
      </div><!-- /.dash-card -->

    </div><!-- /#page-reservation -->


    <!-- ══ PROFILE ══ -->
    <div class="dash-page" id="page-profile">
      <div class="page-header">
        <div>
          <h1 class="page-title">My Profile</h1>
          <p class="page-sub">View and update your account information.</p>
        </div>
      </div>

      <div class="profile-layout">

        <!-- ── Left: Avatar card ── -->
        <div class="profile-avatar-card">

          <!-- Photo -->
          <div class="pav-photo-wrap">
            <?php if ($photo_exists): ?>
              <img src="<?= htmlspecialchars($profile_photo) ?>" alt="Profile Photo"
                   class="pav-photo-img visible" id="pavPhotoImg"/>
              <div class="pav-photo-initials hidden" id="pavPhotoInitials"><?= strtoupper(substr($first_name, 0, 1)) ?></div>
            <?php else: ?>
              <img src="" alt="Profile Photo" class="pav-photo-img" id="pavPhotoImg"/>
              <div class="pav-photo-initials" id="pavPhotoInitials"><?= strtoupper(substr($first_name, 0, 1)) ?></div>
            <?php endif; ?>
            <button type="button" class="pav-camera-btn" id="pavCameraBtn" title="Change photo">
              <i class="fa-solid fa-camera"></i>
            </button>
          </div>

          <!-- Upload zone -->
          <label class="pav-upload-zone" for="profilePhotoInput">
            <span class="pav-upload-label">
              <i class="fa-solid fa-arrow-up-from-bracket"></i> Upload Photo
            </span>
            <span class="pav-upload-hint">JPG, PNG, GIF, WEBP · Max 2 MB</span>
          </label>
          <input type="file" id="profilePhotoInput" accept="image/jpeg,image/png,image/gif,image/webp"/>

          <button type="button" class="pav-remove-btn <?= $photo_exists ? 'visible' : '' ?>" id="pavRemoveBtn">
            <i class="fa-solid fa-trash"></i> Remove Photo
          </button>

          <!-- Name + course -->
          <h3 class="pav-name" id="pavName"><?= htmlspecialchars($student_name) ?></h3>
          <p class="pav-course" id="pavCourse"><?= htmlspecialchars($course) ?></p>

          <!-- Info tags -->
          <div class="pav-tags">
            <span class="ptag"><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($id_number) ?></span>
            <span class="ptag" id="pavYearTag">
              <i class="fa-solid fa-graduation-cap"></i>
              <?php
                $yr_labels = [1=>'1st Year',2=>'2nd Year',3=>'3rd Year',4=>'4th Year'];
                echo $yr_labels[$year_level] ?? $year_level . ' Year';
              ?>
            </span>
          </div>

          <!-- Info details below tags -->
          <div class="pav-info-list">
            <div class="pav-info-item">
              <i class="fa-solid fa-envelope"></i>
              <span id="pavEmail"><?= $email ? htmlspecialchars($email) : '<span style="color:#9ca3af">—</span>' ?></span>
            </div>
            <div class="pav-info-item">
              <i class="fa-solid fa-location-dot"></i>
              <span id="pavAddress"><?= $address ? htmlspecialchars($address) : '<span style="color:#9ca3af">—</span>' ?></span>
            </div>
          </div>

          <!-- Sessions -->
          <div class="pav-sessions">
            <div class="psi-divider"></div>
            <div class="psi-item">
              <span class="psi-val"><?= $sessions_used ?></span>
              <span class="psi-lbl">Sessions Used</span>
            </div>
          </div>

        </div><!-- /.profile-avatar-card -->

        <!-- ── Right: Edit form ── -->
        <div class="profile-form-card">
          <div class="card-header">
            <h2><i class="fa-solid fa-user-pen"></i> Edit Profile</h2>
          </div>

          <form id="profileForm" class="auth-form" novalidate enctype="multipart/form-data">

            <!-- Row 1: ID Number (read-only) + Year Level (1–4) -->
            <div class="form-row two-col">
              <div class="form-group">
                <label for="pf_id">ID Number</label>
                <input type="text" id="pf_id"
                  value="<?= htmlspecialchars($id_number) ?>"
                  disabled class="input-disabled"/>
                <span style="font-size:.72rem;color:var(--muted)">ID cannot be changed.</span>
              </div>
              <div class="form-group">
                <label for="pf_year">Year Level</label>
                <select id="pf_year" name="pf_year">
                  <option value="1" <?= $year_level == 1 ? 'selected' : '' ?>>1</option>
                  <option value="2" <?= $year_level == 2 ? 'selected' : '' ?>>2</option>
                  <option value="3" <?= $year_level == 3 ? 'selected' : '' ?>>3</option>
                  <option value="4" <?= $year_level == 4 ? 'selected' : '' ?>>4</option>
                </select>
              </div>
            </div>

            <!-- Row 2: Last / First / Middle (same order as register) -->
            <div class="form-row three-col">
              <div class="form-group">
                <label for="pf_last">Last Name</label>
                <input type="text" id="pf_last" name="pf_last"
                  value="<?= htmlspecialchars($last_name) ?>"
                  placeholder="Last name" required/>
                <span class="form-error" id="pf_lastError"></span>
              </div>
              <div class="form-group">
                <label for="pf_first">First Name</label>
                <input type="text" id="pf_first" name="pf_first"
                  value="<?= htmlspecialchars($first_name) ?>"
                  placeholder="First name" required/>
                <span class="form-error" id="pf_firstError"></span>
              </div>
              <div class="form-group">
                <label for="pf_middle">Middle Name</label>
                <input type="text" id="pf_middle" name="pf_middle"
                  value="<?= htmlspecialchars($middle_name) ?>"
                  placeholder="Middle name (optional)"/>
              </div>
            </div>

            <!-- Row 3: Course + Email (same order as register) -->
            <div class="form-row two-col">
              <div class="form-group">
                <label for="pf_course">Course</label>
                <select id="pf_course" name="pf_course">
                  <option value="BSIT" <?= $course === 'BSIT' ? 'selected' : '' ?>>Bachelor of Science in Information Technology (BSIT)</option>
                  <option value="BSCS" <?= $course === 'BSCS' ? 'selected' : '' ?>>Bachelor of Science in Computer Science (BSCS)</option>

                  <option value="BSCE" <?= $course === 'BSCE' ? 'selected' : '' ?>>Bachelor of Science in Civil Engineering (BSCE)</option>
                  <option value="BSME" <?= $course === 'BSME' ? 'selected' : '' ?>>Bachelor of Science in Mechanical Engineering (BSME)</option>
                  <option value="BSEE" <?= $course === 'BSEE' ? 'selected' : '' ?>>Bachelor of Science in Electrical Engineering (BSEE)</option>
                  <option value="BSECE" <?= $course === 'BSECE' ? 'selected' : '' ?>>Bachelor of Science in Electronics Engineering (BSECE)</option>
                  <option value="BSIE" <?= $course === 'BSIE' ? 'selected' : '' ?>>Bachelor of Science in Industrial Engineering (BSIE)</option>

                  <option value="BEEd" <?= $course === 'BEEd' ? 'selected' : '' ?>>Bachelor of Elementary Education (BEEd)</option>
                  <option value="BSEd" <?= $course === 'BSEd' ? 'selected' : '' ?>>Bachelor of Secondary Education (BSEd)</option>

                  <option value="BSCrim" <?= $course === 'BSCrim' ? 'selected' : '' ?>>Bachelor of Science in Criminology (BSCrim)</option>

                  <option value="BSA" <?= $course === 'BSA' ? 'selected' : '' ?>>Bachelor of Science in Accountancy (BSA)</option>
                  <option value="BSBA" <?= $course === 'BSBA' ? 'selected' : '' ?>>Bachelor of Science in Business Administration (BSBA)</option>

                  <option value="BSHRM" <?= $course === 'BSHRM' ? 'selected' : '' ?>>Bachelor of Science in Hotel and Restaurant Management (BSHRM)</option>

                  <option value="BSCA" <?= $course === 'BSCA' ? 'selected' : '' ?>>Bachelor of Science in Customs Administration (BSCA)</option>

                  <option value="BSOA" <?= $course === 'BSOA' ? 'selected' : '' ?>>Bachelor of Science in Office Administration (BSOA)</option>

                  <option value="BSSW" <?= $course === 'BSSW' ? 'selected' : '' ?>>Bachelor of Science in Social Work (BSSW)</option>

                  <option value="AB Political Science" <?= $course === 'AB Political Science' ? 'selected' : '' ?>>Bachelor of Arts in Political Science (AB Political Science)</option>
                </select>
              </div>
              <div class="form-group">
                <label for="pf_email">Email Address</label>
                <input type="email" id="pf_email" name="pf_email"
                  value="<?= htmlspecialchars($email) ?>"
                  placeholder="you@email.com" required/>
                <span class="form-error" id="pf_emailError"></span>
              </div>
            </div>

            <!-- Row 4: Address -->
            <div class="form-group">
              <label for="pf_address">Address</label>
              <input type="text" id="pf_address" name="pf_address"
                value="<?= htmlspecialchars($address) ?>"
                placeholder="Street, Barangay, City"/>
            </div>

            <!-- Change Password section -->
            <div class="profile-section-title">
              <i class="fa-solid fa-lock"></i> Change Password
              <span class="section-opt">(leave blank to keep current)</span>
            </div>

            <div class="form-row three-col">
              <div class="form-group">
                <label for="pf_cur_pw">Current Password</label>
                <div class="input-icon-wrap">
                  <input type="password" id="pf_cur_pw" name="pf_cur_pw" placeholder="Current password"/>
                  <button type="button" class="toggle-pw" data-target="pf_cur_pw" tabindex="-1">
                    <i class="fa-regular fa-eye"></i>
                  </button>
                </div>
                <span class="form-error" id="pf_cur_pwError"></span>
              </div>
              <div class="form-group">
                <label for="pf_new_pw">New Password</label>
                <div class="input-icon-wrap">
                  <input type="password" id="pf_new_pw" name="pf_new_pw" placeholder="Min. 8 characters"/>
                  <button type="button" class="toggle-pw" data-target="pf_new_pw" tabindex="-1">
                    <i class="fa-regular fa-eye"></i>
                  </button>
                </div>
                <span class="form-error" id="pf_new_pwError"></span>
              </div>
              <div class="form-group">
                <label for="pf_rep_pw">Repeat New Password</label>
                <div class="input-icon-wrap">
                  <input type="password" id="pf_rep_pw" name="pf_rep_pw" placeholder="Repeat password"/>
                  <button type="button" class="toggle-pw" data-target="pf_rep_pw" tabindex="-1">
                    <i class="fa-regular fa-eye"></i>
                  </button>
                </div>
                <span class="form-error" id="pf_rep_pwError"></span>
              </div>
            </div>

            <!-- Actions -->
            <div style="display:flex;gap:.75rem;margin-top:.7rem;flex-wrap:wrap;align-items:center">
              <button type="submit" class="btn btn-primary" id="saveProfileBtn">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
              </button>
              <button type="button" class="btn-outline-sm" id="cancelProfileBtn">
                <i class="fa-solid fa-rotate-left"></i> Discard
              </button>
              <span class="profile-save-status" id="profileSaveStatus"></span>
            </div>

          </form>
        </div><!-- /.profile-form-card -->

      </div><!-- /.profile-layout -->

    </div><!-- /#page-profile -->

  <?php endif; // end !is_admin student pages ?>


    <!-- ══════════════════════════════════════════ -->
    <!-- ══ ADMIN PAGES ══════════════════════════ -->
    <!-- ══════════════════════════════════════════ -->
  <?php if ($is_admin): ?>
    <!-- ══════════════════════════════════════════ -->

    <!-- ══ ADMIN: SEARCH ══ -->
    <div class="dash-page <?= $is_admin ? 'active' : '' ?>" id="page-admin-search">
      <div class="page-header">
        <div>
          <h1 class="page-title">Search Student</h1>
          <p class="page-sub">Look up a student by their ID number to view their details.</p>
        </div>
      </div>

      <div class="admin-search-wrap">
        <div class="admin-search-box">
          <i class="fa-solid fa-id-badge"></i>
          <input
            type="text"
            id="adminSearchInput"
            class="admin-search-input"
            placeholder="Enter 8-digit ID number…"
            maxlength="8"
            inputmode="numeric"
          />
          <button class="admin-search-btn" id="adminSearchBtn">
            <i class="fa-solid fa-magnifying-glass"></i> Search
          </button>
        </div>
        <p class="search-hint">Enter the student's 8-digit ID number and press Search.</p>

        <!-- Recent Searches -->
        <div class="recent-searches" id="recentSearches">
          <div class="recent-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent Searches</div>
          <div class="recent-chips" id="recentChips">
            <span class="recent-chip" data-id="12345678"><i class="fa-solid fa-user"></i> 12345678</span>
            <span class="recent-chip" data-id="87654321"><i class="fa-solid fa-user"></i> 87654321</span>
            <span class="recent-chip" data-id="11223344"><i class="fa-solid fa-user"></i> 11223344</span>
          </div>
        </div>
      </div>

      <!-- Empty state shown by default -->
      <div class="dash-card" id="searchEmptyState">
        <div class="search-empty">
          <i class="fa-solid fa-user-magnifying-glass"></i>
          <p>Enter a student ID number above to view their profile, session count, and records.</p>
        </div>
      </div>

    </div><!-- /#page-admin-search -->


    <!-- ══ ADMIN: STUDENTS ══ -->
    <div class="dash-page" id="page-admin-students">
      <div class="page-header">
        <div>
          <h1 class="page-title">Students</h1>
          <p class="page-sub">All enrolled students in the CCS Sit-in System.</p>
        </div>
        <div class="page-actions">
          <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="studentsSearch" placeholder="Search students…" class="search-input"/>
          </div>
          <button class="btn-outline-sm"><i class="fa-solid fa-download"></i> Export</button>
        </div>
      </div>

      <div class="admin-stats-row">
        <div class="stat-card purple">
          <div class="sc-icon"><i class="fa-solid fa-users"></i></div>
          <div class="sc-body"><div class="sc-value">248</div><div class="sc-label">Total Students</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-users"></i></div>
        </div>
        <div class="stat-card yellow">
          <div class="sc-icon"><i class="fa-solid fa-graduation-cap"></i></div>
          <div class="sc-body"><div class="sc-value">142</div><div class="sc-label">BSIT Enrolled</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-graduation-cap"></i></div>
        </div>
        <div class="stat-card green">
          <div class="sc-icon"><i class="fa-solid fa-graduation-cap"></i></div>
          <div class="sc-body"><div class="sc-value">106</div><div class="sc-label">BSCS Enrolled</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-graduation-cap"></i></div>
        </div>
        <div class="stat-card pink">
          <div class="sc-icon"><i class="fa-solid fa-circle-dot"></i></div>
          <div class="sc-body"><div class="sc-value">32</div><div class="sc-label">Currently Active</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-circle-dot"></i></div>
        </div>
      </div>

      <div class="dash-card no-pad">
        <div class="card-header" style="padding:1.1rem 1.5rem .6rem">
          <h2><i class="fa-solid fa-list"></i> Student List</h2>
          <div class="admin-filter-tabs">
            <button class="aft-btn active">All</button>
            <button class="aft-btn">BSIT</button>
            <button class="aft-btn">BSCS</button>
          </div>
        </div>
        <div class="table-wrap">
          <table class="history-table" id="studentsTable">
            <thead>
              <tr>
                <th>#</th><th>ID Number</th><th>Name</th><th>Course</th><th>Year</th><th>Email</th><th>Sessions Left</th><th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $students_demo = [
                [1, '12345678', 'Santos, Maria Clara', 'BSIT', '3rd', 'mclaras@uc.edu.ph', 28],
                [2, '87654321', 'Reyes, Juan Paolo',   'BSCS', '2nd', 'jpreyes@uc.edu.ph', 30],
                [3, '11223344', 'Cruz, Ana Liza',      'BSIT', '1st', 'alcruz@uc.edu.ph',  15],
                [4, '55667788', 'Lim, Roberto Jr.',    'BSCS', '4th', 'rjlim@uc.edu.ph',   5],
                [5, '99887766', 'Dela Cruz, Hannah',   'BSIT', '2nd', 'hdelacruz@uc.edu.ph', 30],
                [6, '44332211', 'Flores, Carlo M.',    'BSCS', '3rd', 'cmflores@uc.edu.ph', 22],
              ];
              foreach ($students_demo as $s): ?>
              <tr>
                <td class="td-num"><?= $s[0] ?></td>
                <td><code style="font-size:.82rem;background:#f3f0ff;color:var(--purple-mid);padding:.15rem .45rem;border-radius:5px"><?= $s[1] ?></code></td>
                <td><strong><?= $s[2] ?></strong></td>
                <td><span class="lab-tag"><?= $s[3] ?></span></td>
                <td><?= $s[4] ?></td>
                <td style="font-size:.8rem;color:var(--muted)"><?= $s[5] ?></td>
                <td>
                  <span class="status-badge <?= $s[6] <= 5 ? 'cancelled' : 'completed' ?>">
                    <?= $s[6] ?> left
                  </span>
                </td>
                <td>
                  <button class="view-row-btn admin-view-student"
                    data-id="<?= $s[1] ?>"
                    data-name="<?= $s[2] ?>"
                    data-course="<?= $s[3] ?>"
                    data-year="<?= $s[4] ?>"
                    data-email="<?= $s[5] ?>"
                    data-sessions="<?= $s[6] ?>">
                    <i class="fa-solid fa-eye"></i> View
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /#page-admin-students -->


    <!-- ══ ADMIN: SIT-IN ══ -->
    <div class="dash-page" id="page-admin-sitin">
      <div class="page-header">
        <div>
          <h1 class="page-title">Sit-in Management</h1>
          <p class="page-sub">Monitor and manage active sit-in sessions in all laboratories.</p>
        </div>
        <button class="btn-primary-sm"><i class="fa-solid fa-plus"></i> Log Sit-in</button>
      </div>
      <div class="admin-stats-row">
        <div class="stat-card purple">
          <div class="sc-icon"><i class="fa-solid fa-desktop"></i></div>
          <div class="sc-body"><div class="sc-value">32</div><div class="sc-label">Active Now</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-desktop"></i></div>
        </div>
        <div class="stat-card green">
          <div class="sc-icon"><i class="fa-solid fa-door-open"></i></div>
          <div class="sc-body"><div class="sc-value">16</div><div class="sc-label">Free Computers</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-door-open"></i></div>
        </div>
        <div class="stat-card yellow">
          <div class="sc-icon"><i class="fa-solid fa-building"></i></div>
          <div class="sc-body"><div class="sc-value">4</div><div class="sc-label">Labs Open</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-building"></i></div>
        </div>
        <div class="stat-card pink">
          <div class="sc-icon"><i class="fa-solid fa-clock"></i></div>
          <div class="sc-body"><div class="sc-value">2h 14m</div><div class="sc-label">Avg. Duration</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-clock"></i></div>
        </div>
      </div>
      <div class="dash-card no-pad">
        <div class="card-header" style="padding:1.1rem 1.5rem .6rem">
          <h2><i class="fa-solid fa-circle-dot" style="color:#16a34a"></i> Active Sit-in Sessions</h2>
          <span class="status-live"><span class="live-dot"></span> LIVE</span>
        </div>
        <div class="table-wrap">
          <table class="history-table">
            <thead>
              <tr><th>#</th><th>ID Number</th><th>Name</th><th>Lab</th><th>PC</th><th>Time In</th><th>Duration</th><th>Purpose</th><th>Action</th></tr>
            </thead>
            <tbody>
              <?php
              $active = [
                [1,'12345678','Santos, Maria Clara','Lab 2','PC-12','08:00 AM','2h 14m','Programming'],
                [2,'87654321','Reyes, Juan Paolo',  'Lab 1','PC-05','08:30 AM','1h 44m','Research'],
                [3,'11223344','Cruz, Ana Liza',     'Lab 3','PC-22','09:00 AM','1h 14m','Project Work'],
                [4,'55667788','Lim, Roberto Jr.',   'Lab 2','PC-08','09:15 AM','0h 59m','Programming'],
              ];
              foreach ($active as $a): ?>
              <tr>
                <td class="td-num"><?= $a[0] ?></td>
                <td><code style="font-size:.82rem;background:#f3f0ff;color:var(--purple-mid);padding:.15rem .45rem;border-radius:5px"><?= $a[1] ?></code></td>
                <td><strong><?= $a[2] ?></strong></td>
                <td><span class="lab-tag"><?= $a[3] ?></span></td>
                <td><?= $a[4] ?></td>
                <td><?= $a[5] ?></td>
                <td><strong style="color:var(--purple-mid)"><?= $a[6] ?></strong></td>
                <td><span class="purpose-tag"><?= $a[7] ?></span></td>
                <td><button class="btn-outline-sm danger" style="font-size:.72rem;padding:.25rem .65rem"><i class="fa-solid fa-right-from-bracket"></i> End</button></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /#page-admin-sitin -->


    <!-- ══ ADMIN: VIEW SIT-IN RECORDS ══ -->
    <div class="dash-page" id="page-admin-sitin-records">
      <div class="page-header">
        <div>
          <h1 class="page-title">Sit-in Records</h1>
          <p class="page-sub">Complete historical log of all sit-in sessions.</p>
        </div>
        <div class="page-actions">
          <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="recordsSearch" placeholder="Search records…" class="search-input"/>
          </div>
          <button class="btn-outline-sm"><i class="fa-solid fa-download"></i> Export CSV</button>
        </div>
      </div>
      <div class="dash-card no-pad">
        <div class="card-header" style="padding:1.1rem 1.5rem .6rem">
          <h2><i class="fa-solid fa-table-list"></i> All Records</h2>
          <span style="font-size:.78rem;color:var(--muted)">Showing 320 total sessions this month</span>
        </div>
        <div class="table-wrap">
          <table class="history-table" id="recordsTable">
            <thead>
              <tr><th>#</th><th>Date</th><th>ID Number</th><th>Name</th><th>Lab</th><th>PC</th><th>Time In</th><th>Time Out</th><th>Duration</th><th>Purpose</th></tr>
            </thead>
            <tbody>
              <?php
              $records = [
                [1,'Mar 14','12345678','Santos, Maria C.','Lab 2','PC-12','08:00 AM','10:14 AM','2h 14m','Programming'],
                [2,'Mar 14','87654321','Reyes, Juan P.',  'Lab 1','PC-05','08:30 AM','10:14 AM','1h 44m','Research'],
                [3,'Mar 13','11223344','Cruz, Ana L.',    'Lab 3','PC-22','09:00 AM','11:45 AM','2h 45m','Project Work'],
                [4,'Mar 13','55667788','Lim, Roberto J.', 'Lab 2','PC-08','02:30 PM','04:00 PM','1h 30m','Programming'],
                [5,'Mar 12','99887766','Dela Cruz, H.',   'Lab 4','PC-17','10:00 AM','12:30 PM','2h 30m','Research'],
                [6,'Mar 12','44332211','Flores, Carlo M.','Lab 1','PC-03','08:30 AM','10:00 AM','1h 30m','Programming'],
                [7,'Mar 11','12345678','Santos, Maria C.','Lab 2','PC-11','01:00 PM','04:15 PM','3h 15m','Project Work'],
              ];
              foreach ($records as $r): ?>
              <tr>
                <td class="td-num"><?= $r[0] ?></td>
                <td><?= $r[1] ?></td>
                <td><code style="font-size:.82rem;background:#f3f0ff;color:var(--purple-mid);padding:.15rem .45rem;border-radius:5px"><?= $r[2] ?></code></td>
                <td><strong><?= $r[3] ?></strong></td>
                <td><span class="lab-tag"><?= $r[4] ?></span></td>
                <td><?= $r[5] ?></td>
                <td><?= $r[6] ?></td>
                <td><?= $r[7] ?></td>
                <td><strong><?= $r[8] ?></strong></td>
                <td><span class="purpose-tag"><?= $r[9] ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="table-footer">
          <span class="tf-count">Showing <strong>7</strong> of <strong>320</strong> records</span>
          <div class="pagination">
            <button class="pg-btn active">1</button>
            <button class="pg-btn">2</button>
            <button class="pg-btn">3</button>
            <button class="pg-btn"><i class="fa-solid fa-chevron-right"></i></button>
          </div>
        </div>
      </div>
    </div><!-- /#page-admin-sitin-records -->


    <!-- ══ ADMIN: SIT-IN REPORTS ══ -->
    <div class="dash-page" id="page-admin-sitin-reports">
      <div class="page-header">
        <div>
          <h1 class="page-title">Sit-in Reports</h1>
          <p class="page-sub">Analytics and usage statistics across all laboratories.</p>
        </div>
        <button class="btn-outline-sm"><i class="fa-solid fa-print"></i> Print Report</button>
      </div>
      <div class="admin-stats-row">
        <div class="stat-card purple">
          <div class="sc-icon"><i class="fa-solid fa-calendar-check"></i></div>
          <div class="sc-body"><div class="sc-value">320</div><div class="sc-label">Sessions This Month</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-calendar-check"></i></div>
        </div>
        <div class="stat-card yellow">
          <div class="sc-icon"><i class="fa-solid fa-hourglass-half"></i></div>
          <div class="sc-body"><div class="sc-value">756h</div><div class="sc-label">Total Hours Logged</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-hourglass-half"></i></div>
        </div>
        <div class="stat-card green">
          <div class="sc-icon"><i class="fa-solid fa-star"></i></div>
          <div class="sc-body"><div class="sc-value">2h 21m</div><div class="sc-label">Avg. Session Length</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-star"></i></div>
        </div>
        <div class="stat-card pink">
          <div class="sc-icon"><i class="fa-solid fa-building"></i></div>
          <div class="sc-body"><div class="sc-value">Lab 2</div><div class="sc-label">Most Used Lab</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-building"></i></div>
        </div>
      </div>
      <div class="home-grid">
        <div class="dash-card">
          <div class="card-header"><h2><i class="fa-solid fa-chart-bar"></i> Sessions per Lab</h2></div>
          <div class="lab-list">
            <?php
            $lab_stats = [
              ['Lab 1', 78, 78, '#6c3fcf'],
              ['Lab 2', 95, 95, '#a259f7'],
              ['Lab 3', 62, 62, '#f5c518'],
              ['Lab 4', 85, 85, '#16a34a'],
            ];
            foreach ($lab_stats as $l): ?>
            <div class="lab-item">
              <div class="lab-info">
                <span class="lab-name"><?= $l[0] ?></span>
                <span class="lab-computers"><?= $l[1] ?> sessions</span>
              </div>
              <div class="lab-bar-wrap">
                <div class="lab-bar" style="--fill:<?= $l[2] ?>%;--color:<?= $l[3] ?>"></div>
              </div>
              <div class="lab-meta">
                <span class="lab-used"><?= round($l[1]/3.2, 0) ?>% of total</span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="dash-card">
          <div class="card-header"><h2><i class="fa-solid fa-tag"></i> Sessions by Purpose</h2></div>
          <div class="lab-list">
            <?php
            $purposes = [
              ['Programming',  42, 42, '#6c3fcf'],
              ['Research',     28, 28, '#a259f7'],
              ['Project Work', 18, 18, '#f5c518'],
              ['Online Exam',   8,  8, '#16a34a'],
              ['Other',         4,  4, '#9ca3af'],
            ];
            foreach ($purposes as $p): ?>
            <div class="lab-item">
              <div class="lab-info">
                <span class="lab-name"><?= $p[0] ?></span>
                <span class="lab-computers"><?= $p[1] ?>%</span>
              </div>
              <div class="lab-bar-wrap">
                <div class="lab-bar" style="--fill:<?= $p[2] ?>%;--color:<?= $p[3] ?>"></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div><!-- /#page-admin-sitin-reports -->


    <!-- ══ ADMIN: FEEDBACK REPORTS ══ -->
    <div class="dash-page" id="page-admin-feedback">
      <div class="page-header">
        <div>
          <h1 class="page-title">Feedback Reports</h1>
          <p class="page-sub">Student feedback and satisfaction scores from sit-in sessions.</p>
        </div>
        <button class="btn-outline-sm"><i class="fa-solid fa-download"></i> Export</button>
      </div>
      <div class="admin-stats-row">
        <div class="stat-card green">
          <div class="sc-icon"><i class="fa-solid fa-star"></i></div>
          <div class="sc-body"><div class="sc-value">4.7</div><div class="sc-label">Avg. Rating</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-star"></i></div>
        </div>
        <div class="stat-card purple">
          <div class="sc-icon"><i class="fa-solid fa-comment"></i></div>
          <div class="sc-body"><div class="sc-value">184</div><div class="sc-label">Total Feedbacks</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-comment"></i></div>
        </div>
        <div class="stat-card yellow">
          <div class="sc-icon"><i class="fa-solid fa-face-smile"></i></div>
          <div class="sc-body"><div class="sc-value">92%</div><div class="sc-label">Positive Rate</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-face-smile"></i></div>
        </div>
        <div class="stat-card pink">
          <div class="sc-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
          <div class="sc-body"><div class="sc-value">6</div><div class="sc-label">Issues Reported</div></div>
          <div class="sc-bg-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        </div>
      </div>
      <div class="dash-card no-pad">
        <div class="card-header" style="padding:1.1rem 1.5rem .6rem">
          <h2><i class="fa-solid fa-comments"></i> Recent Feedback</h2>
        </div>
        <div class="table-wrap">
          <table class="history-table">
            <thead>
              <tr><th>#</th><th>Date</th><th>Student</th><th>Lab</th><th>Rating</th><th>Comment</th></tr>
            </thead>
            <tbody>
              <?php
              $feedbacks = [
                [1,'Mar 14','Santos, Maria C.','Lab 2', 5, 'Great environment, very clean and fast PCs.'],
                [2,'Mar 13','Reyes, Juan P.',  'Lab 1', 4, 'Good but AC was not working properly.'],
                [3,'Mar 13','Cruz, Ana L.',    'Lab 3', 5, 'Very helpful staff. Will definitely come back.'],
                [4,'Mar 12','Lim, Roberto J.', 'Lab 2', 3, 'PC-08 had some keyboard keys not responding.'],
                [5,'Mar 12','Dela Cruz, H.',   'Lab 4', 5, 'Perfect for studying. Quiet and well-maintained.'],
                [6,'Mar 11','Flores, Carlo M.','Lab 1', 4, 'Overall good. Internet was a bit slow.'],
              ];
              foreach ($feedbacks as $f): ?>
              <tr>
                <td class="td-num"><?= $f[0] ?></td>
                <td><?= $f[1] ?></td>
                <td><strong><?= $f[2] ?></strong></td>
                <td><span class="lab-tag"><?= $f[3] ?></span></td>
                <td>
                  <span style="color:<?= $f[4] >= 4 ? '#d97706' : ($f[4] == 3 ? '#6b7280' : '#ef4444') ?>">
                    <?= str_repeat('★', $f[4]) ?><?= str_repeat('☆', 5 - $f[4]) ?>
                  </span>
                </td>
                <td style="font-size:.8rem;max-width:280px"><?= htmlspecialchars($f[5]) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /#page-admin-feedback -->


    <!-- ══ ADMIN: RESERVATION ══ -->
    <div class="dash-page" id="page-admin-reservation">
      <div class="page-header">
        <div>
          <h1 class="page-title">Reservation Management</h1>
          <p class="page-sub">Review and manage all student reservation requests.</p>
        </div>
        <div class="page-actions">
          <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" placeholder="Search reservations…" class="search-input"/>
          </div>
        </div>
      </div>
      <div class="dash-card no-pad">
        <div class="card-header" style="padding:1.1rem 1.5rem .6rem">
          <h2><i class="fa-solid fa-calendar-check"></i> All Reservations</h2>
          <div class="admin-filter-tabs">
            <button class="aft-btn active">All</button>
            <button class="aft-btn">Pending</button>
            <button class="aft-btn">Confirmed</button>
            <button class="aft-btn">Cancelled</button>
          </div>
        </div>
        <div class="table-wrap">
          <table class="history-table">
            <thead>
              <tr><th>#</th><th>Ref</th><th>Student</th><th>Lab</th><th>Date</th><th>Time</th><th>Purpose</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
              <?php
              $reservations = [
                ['RSV-0042','Santos, Maria C.','Lab 2','Mar 15','10:00–12:00','Programming','confirmed'],
                ['RSV-0043','Reyes, Juan P.',  'Lab 4','Mar 15','02:00–04:00','Research',   'pending'],
                ['RSV-0041','Cruz, Ana L.',    'Lab 1','Mar 14','08:00–10:00','Project Work','confirmed'],
                ['RSV-0040','Lim, Roberto J.', 'Lab 3','Mar 14','01:00–03:00','Programming','cancelled'],
                ['RSV-0039','Dela Cruz, H.',   'Lab 2','Mar 13','10:00–12:00','Research',   'confirmed'],
              ];
              foreach ($reservations as $i => $r): ?>
              <tr>
                <td class="td-num"><?= $i+1 ?></td>
                <td><code style="font-size:.78rem;color:var(--purple-mid)"><?= $r[0] ?></code></td>
                <td><strong><?= $r[1] ?></strong></td>
                <td><span class="lab-tag"><?= $r[2] ?></span></td>
                <td><?= $r[3] ?></td>
                <td style="font-size:.8rem"><?= $r[4] ?></td>
                <td><span class="purpose-tag"><?= $r[5] ?></span></td>
                <td><span class="status-badge <?= $r[6] ?>"><?= ucfirst($r[6]) ?></span></td>
                <td style="display:flex;gap:.3rem">
                  <?php if ($r[6] === 'pending'): ?>
                  <button class="view-row-btn" style="background:#dcfce7;border-color:#bbf7d0;color:#16a34a"><i class="fa-solid fa-check"></i> Approve</button>
                  <button class="view-row-btn" style="background:#fee2e2;border-color:#fca5a5;color:#ef4444"><i class="fa-solid fa-xmark"></i> Reject</button>
                  <?php else: ?>
                  <button class="view-row-btn"><i class="fa-solid fa-eye"></i> View</button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /#page-admin-reservation -->

  <?php endif; // end is_admin ?>

  </main><!-- /.dash-main -->


  <!-- ══ STUDENT DETAIL MODAL (Admin Search) ══ -->
  <?php if ($is_admin): ?>
  <div class="modal-overlay" id="studentModal">
    <div class="modal-card student-modal-card">
      <div class="modal-header">
        <h3><i class="fa-solid fa-id-card"></i> Student Profile</h3>
        <button class="modal-close" id="closeStudentModal"><i class="fa-solid fa-xmark"></i></button>
      </div>

      <div class="student-modal-profile">
        <div class="smp-avatar" id="smpAvatar">?</div>
        <div class="smp-info">
          <div class="smp-name" id="smpName">—</div>
          <div class="smp-course" id="smpCourse">—</div>
          <div class="smp-id" id="smpId">ID: —</div>
        </div>
        <div class="smp-sessions">
          <div class="smp-sess-val" id="smpSessions">—</div>
          <div class="smp-sess-lbl">Sessions<br>Remaining</div>
        </div>
      </div>

      <div class="student-info-grid">
        <div class="sinfo-item">
          <div class="sinfo-label"><i class="fa-solid fa-graduation-cap"></i> Course</div>
          <div class="sinfo-value" id="siCourse">—</div>
        </div>
        <div class="sinfo-item">
          <div class="sinfo-label"><i class="fa-solid fa-layer-group"></i> Year Level</div>
          <div class="sinfo-value" id="siYear">—</div>
        </div>
        <div class="sinfo-item full-col">
          <div class="sinfo-label"><i class="fa-solid fa-envelope"></i> Email Address</div>
          <div class="sinfo-value" id="siEmail">—</div>
        </div>
        <div class="sinfo-item">
          <div class="sinfo-label"><i class="fa-solid fa-clock-rotate-left"></i> Total Sessions Used</div>
          <div class="sinfo-value" id="siUsed">—</div>
        </div>
        <div class="sinfo-item">
          <div class="sinfo-label"><i class="fa-solid fa-calendar-check"></i> Last Sit-in</div>
          <div class="sinfo-value" id="siLastSitin">—</div>
        </div>
        <div class="sinfo-item full-col">
          <div class="sinfo-label"><i class="fa-solid fa-location-dot"></i> Address</div>
          <div class="sinfo-value" id="siAddress">—</div>
        </div>
      </div>

      <div class="student-modal-actions">
        <button class="btn-outline-sm" id="closeStudentModal2">Close</button>
        <button class="btn-primary-sm"><i class="fa-solid fa-desktop"></i> Log Sit-in</button>
        <button class="btn-primary-sm" style="background:linear-gradient(135deg,#16a34a,#22c55e);box-shadow:0 3px 10px rgba(22,163,74,.3)">
          <i class="fa-solid fa-clock-rotate-left"></i> View History
        </button>
      </div>
    </div>
  </div><!-- /#studentModal -->
  <?php endif; // end is_admin modal ?>

  <!-- ── RESERVATION MODAL (Student only) ── -->
  <?php if (!$is_admin): ?>
  <div class="modal-overlay" id="reserveModal">
    <div class="modal-card">
      <div class="modal-header">
        <h3><i class="fa-solid fa-calendar-plus"></i> New Reservation</h3>
        <button class="modal-close" id="closeModal"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form id="reserveForm" class="auth-form" style="gap:.8rem">
        <div class="form-row two-col">
          <div class="form-group">
            <label for="res_lab">Laboratory</label>
            <select id="res_lab" name="res_lab">
              <option value="">Select Lab</option>
              <option value="Lab 1">Lab 1 — Ground Floor</option>
              <option value="Lab 2">Lab 2 — 2nd Floor</option>
              <option value="Lab 3">Lab 3 — 2nd Floor</option>
              <option value="Lab 4">Lab 4 — 3rd Floor</option>
            </select>
          </div>
          <div class="form-group">
            <label for="res_pc">Preferred Computer</label>
            <select id="res_pc" name="res_pc">
              <option value="any">Any Available</option>
              <?php for ($i = 1; $i <= 30; $i++): ?>
              <option value="PC-<?= str_pad($i, 2, '0', STR_PAD_LEFT) ?>">PC-<?= str_pad($i, 2, '0', STR_PAD_LEFT) ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
        <div class="form-row two-col">
          <div class="form-group">
            <label for="res_date">Date</label>
            <input type="date" id="res_date" name="res_date" min="<?= date('Y-m-d') ?>"/>
          </div>
          <div class="form-group">
            <label for="res_time">Time Slot</label>
            <select id="res_time" name="res_time">
              <option value="">Select Time</option>
              <option value="08:00-10:00">08:00 AM – 10:00 AM</option>
              <option value="10:00-12:00">10:00 AM – 12:00 PM</option>
              <option value="13:00-15:00">01:00 PM – 03:00 PM</option>
              <option value="15:00-17:00">03:00 PM – 05:00 PM</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label for="res_purpose">Purpose</label>
          <select id="res_purpose" name="res_purpose">
            <option value="Programming">Programming</option>
            <option value="Research">Research</option>
            <option value="Project Work">Project Work</option>
            <option value="Online Exam">Online Exam</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="form-group">
          <label for="res_notes">Notes <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
          <input type="text" id="res_notes" name="res_notes" placeholder="Any additional details…"/>
        </div>
        <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:.3rem">
          <button type="button" class="btn-outline-sm" id="cancelModal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-calendar-check"></i> Submit Reservation
          </button>
        </div>
      </form>
    </div><!-- /.modal-card -->
  </div><!-- /.modal-overlay -->
  <?php endif; // end !is_admin reservation modal ?>

  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <script src="script.js"></script>
  <script>
document.addEventListener('DOMContentLoaded', () => {

  /* ── PAGE SWITCHING ── */
  const sbLinks   = document.querySelectorAll('.sb-link[data-page]');
  const menuItems = document.querySelectorAll('.sb-menu-item[data-page]');
  const pages     = document.querySelectorAll('.dash-page');

  function switchPage(pageId) {
    pages.forEach(p => p.classList.remove('active'));
    sbLinks.forEach(l => l.classList.remove('active'));
    const target = document.getElementById('page-' + pageId);
    if (target) target.classList.add('active');
    sbLinks.forEach(l => { if (l.dataset.page === pageId) l.classList.add('active'); });
    closeSidebar();
  }

  sbLinks.forEach(l => l.addEventListener('click', e => { e.preventDefault(); switchPage(l.dataset.page); }));
  menuItems.forEach(item => {
    item.addEventListener('click', e => {
      e.preventDefault();
      if (item.dataset.page) { switchPage(item.dataset.page); closeUserMenu(); }
    });
  });

  /* ── USER MENU ── */
  const userMenuBtn = document.getElementById('userMenuBtn');
  const userMenu    = document.getElementById('userMenu');
  const userChevron = document.getElementById('userChevron');

  function closeUserMenu() {
    if (!userMenu) return;
    userMenu.classList.remove('open');
    if (userChevron) userChevron.classList.remove('open');
  }

  if (userMenuBtn) {
    userMenuBtn.addEventListener('click', e => {
      e.stopPropagation();
      const isOpen = userMenu.classList.toggle('open');
      if (userChevron) userChevron.classList.toggle('open', isOpen);
    });
  }

  document.addEventListener('click', e => {
    if (userMenuBtn && !userMenuBtn.contains(e.target) && userMenu && !userMenu.contains(e.target)) closeUserMenu();
  });

  /* ── MOBILE SIDEBAR ── */
  const sidebar        = document.getElementById('sidebar');
  const sbToggle       = document.getElementById('sbToggle');
  const sidebarOverlay = document.getElementById('sidebarOverlay');

  function closeSidebar() {
    sidebar.classList.remove('open');
    sidebarOverlay.classList.remove('open');
  }

  sbToggle?.addEventListener('click', () => {
    const isOpen = sidebar.classList.toggle('open');
    sidebarOverlay.classList.toggle('open', isOpen);
  });

  sidebarOverlay.addEventListener('click', closeSidebar);

  /* ── RESERVATION MODAL ── */
  const reserveModal = document.getElementById('reserveModal');
  if (reserveModal) {
    document.getElementById('openReserveModal')?.addEventListener('click', () => reserveModal.classList.add('open'));
    document.getElementById('openReserveModal2')?.addEventListener('click', () => reserveModal.classList.add('open'));
    document.getElementById('closeModal')?.addEventListener('click', () => reserveModal.classList.remove('open'));
    document.getElementById('cancelModal')?.addEventListener('click', () => reserveModal.classList.remove('open'));
    reserveModal.addEventListener('click', e => { if (e.target === reserveModal) reserveModal.classList.remove('open'); });
  }

  /* ── RESERVE FORM ── */
  document.getElementById('reserveForm')?.addEventListener('submit', e => {
    e.preventDefault();
    if (!document.getElementById('res_lab').value || !document.getElementById('res_date').value || !document.getElementById('res_time').value) {
      showToast('Please fill in lab, date, and time slot.', 'error'); return;
    }
    reserveModal.classList.remove('open');
    showToast('Reservation submitted! Awaiting confirmation.', 'success');
    e.target.reset();
  });

  /* ── SLOT FILTER ── */
  document.querySelectorAll('.sf-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.sf-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const filter = btn.dataset.lab;
      document.querySelectorAll('.slot-item').forEach(item => {
        const lab = item.querySelector('.slot-lab')?.textContent.trim();
        item.style.display = (filter === 'all' || lab === 'Lab ' + filter) ? '' : 'none';
      });
    });
  });

  /* ── SLOT RESERVE BUTTONS ── */
  document.querySelectorAll('.slot-btn:not(.disabled)').forEach(btn => {
    btn.addEventListener('click', () => {
      const lab = btn.closest('.slot-item')?.querySelector('.slot-lab')?.textContent;
      const sel = document.getElementById('res_lab');
      if (sel && lab) for (const opt of sel.options) if (opt.value.startsWith(lab)) { opt.selected = true; break; }
      reserveModal.classList.add('open');
    });
  });

  /* ── HISTORY SEARCH ── */
  document.getElementById('historySearch')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#historyTable tbody tr').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });

  /* ── STUDENTS TABLE SEARCH ── */
  document.getElementById('studentsSearch')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#studentsTable tbody tr').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });

  /* ── RECORDS SEARCH ── */
  document.getElementById('recordsSearch')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#recordsTable tbody tr').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });

  /* ── ADMIN FILTER TABS ── */
  document.querySelectorAll('.admin-filter-tabs').forEach(group => {
    group.querySelectorAll('.aft-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        group.querySelectorAll('.aft-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
      });
    });
  });

  /* ── PHOTO UPLOAD ── */
  const profilePhotoInput = document.getElementById('profilePhotoInput');
  const pavPhotoImg       = document.getElementById('pavPhotoImg');
  const pavPhotoInitials  = document.getElementById('pavPhotoInitials');
  const pavCameraBtn      = document.getElementById('pavCameraBtn');
  const pavRemoveBtn      = document.getElementById('pavRemoveBtn');
  const sbAvatarThumb     = document.getElementById('sbAvatarThumb');

  // Track whether the user clicked "Remove Photo" so we can tell PHP to clear DB
  let pendingRemovePhoto = false;

  // Camera icon button also opens file picker
  pavCameraBtn?.addEventListener('click', () => profilePhotoInput?.click());

  profilePhotoInput?.addEventListener('change', () => {
    const file = profilePhotoInput.files[0];
    if (!file) return;

    if (file.size > 2 * 1024 * 1024) {
      showToast('Photo must be under 2 MB.', 'error');
      profilePhotoInput.value = '';
      return;
    }

    pendingRemovePhoto = false;   // new photo cancels any pending remove

    const reader = new FileReader();
    reader.onload = e => {
      const src = e.target.result;
      pavPhotoImg.src = src;
      pavPhotoImg.classList.add('visible');
      pavPhotoInitials.classList.add('hidden');
      pavRemoveBtn.classList.add('visible');

      // Sync sidebar avatar
      if (sbAvatarThumb) {
        sbAvatarThumb.style.cssText = 'background:none;padding:0;overflow:hidden;border-radius:10px';
        sbAvatarThumb.innerHTML = `<img src="${src}" style="width:100%;height:100%;object-fit:cover" alt="avatar"/>`;
      }

      showToast('Photo selected — save changes to confirm.', 'success');
    };
    reader.readAsDataURL(file);
  });

  pavRemoveBtn?.addEventListener('click', () => {
    pavPhotoImg.src = '';
    pavPhotoImg.classList.remove('visible');
    pavPhotoInitials.classList.remove('hidden');
    pavRemoveBtn.classList.remove('visible');
    if (profilePhotoInput) profilePhotoInput.value = '';
    pendingRemovePhoto = true;   // flag: tell PHP to clear the photo on next save

    if (sbAvatarThumb) {
      sbAvatarThumb.style.cssText = '';
      sbAvatarThumb.innerHTML     = '<?= strtoupper(substr($first_name, 0, 1)) ?>';
    }
    showToast('Photo marked for removal — save to confirm.', 'error');
  });

  /* ── PROFILE FORM — posts to this same file ── */
  const profileForm      = document.getElementById('profileForm');
  const saveProfileBtn   = document.getElementById('saveProfileBtn');
  const cancelProfileBtn = document.getElementById('cancelProfileBtn');
  const profileSaveStatus = document.getElementById('profileSaveStatus');

  // Year level labels map
  const yrLabels = { '1':'1st Year', '2':'2nd Year', '3':'3rd Year', '4':'4th Year' };

  profileForm?.addEventListener('submit', async e => {
    e.preventDefault();
    if (!validateProfileForm()) return;

    const fd = new FormData(profileForm);
    fd.append('_action', 'update_profile');  // tells PHP which handler to run

    // Append photo file if chosen
    if (profilePhotoInput?.files[0]) {
      fd.append('profile_photo', profilePhotoInput.files[0]);
    }

    // Tell PHP to clear the photo from DB if Remove was clicked
    if (pendingRemovePhoto && !profilePhotoInput?.files[0]) {
      fd.append('remove_photo', '1');
    }

    // Disable button while saving
    if (saveProfileBtn) {
      saveProfileBtn.disabled = true;
      saveProfileBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    }

    try {
      const res  = await fetch('dashboard.php', { method: 'POST', body: fd });
      const data = await res.json();

      if (data.success) {
        // ── Update avatar card immediately ──────────
        const first  = document.getElementById('pf_first')?.value.trim() || '';
        const last   = document.getElementById('pf_last')?.value.trim()  || '';
        const course = document.getElementById('pf_course')?.value       || '';
        const year   = document.getElementById('pf_year')?.value         || '';
        const email  = document.getElementById('pf_email')?.value.trim() || '';
        const addr   = document.getElementById('pf_address')?.value.trim() || '';
        const full   = first + ' ' + last;

        // Name + course
        const pavName   = document.getElementById('pavName');
        const pavCourse = document.getElementById('pavCourse');
        if (pavName)   pavName.textContent   = full;
        if (pavCourse) pavCourse.textContent = course;

        // Year tag
        const pavYearTag = document.getElementById('pavYearTag');
        if (pavYearTag) pavYearTag.innerHTML =
          `<i class="fa-solid fa-graduation-cap"></i> ${yrLabels[year] || year + ' Year'}`;

        // Email + address in info list
        const pavEmail   = document.getElementById('pavEmail');
        const pavAddress = document.getElementById('pavAddress');
        if (pavEmail)   pavEmail.textContent   = email   || '—';
        if (pavAddress) pavAddress.textContent  = addr    || '—';

        // Sidebar name + initials
        const sbName = document.querySelector('.sb-user-name');
        if (sbName) sbName.textContent = full;

        if (sbAvatarThumb && !pavPhotoImg?.classList.contains('visible')) {
          sbAvatarThumb.textContent = first.charAt(0).toUpperCase();
        }

        // Initials fallback inside card
        if (pavPhotoInitials && !pavPhotoImg?.classList.contains('visible')) {
          pavPhotoInitials.textContent = first.charAt(0).toUpperCase();
        }

        // If server returned a saved photo path, update preview src to real path
        if (data.photo && pavPhotoImg) {
          pavPhotoImg.src = data.photo + '?v=' + Date.now(); // cache-bust
          pavPhotoImg.classList.add('visible');
          pavPhotoInitials?.classList.add('hidden');
          pavRemoveBtn?.classList.add('visible');
          // Clear the staged file so next save doesn't re-upload
          if (profilePhotoInput) profilePhotoInput.value = '';
        }

        // If photo was cleared from DB, make sure UI stays empty
        if (data.photo_removed) {
          pavPhotoImg.src = '';
          pavPhotoImg.classList.remove('visible');
          pavPhotoInitials?.classList.remove('hidden');
          pavRemoveBtn?.classList.remove('visible');
        }

        // Reset removal flag after a successful save in either direction
        pendingRemovePhoto = false;

        // Clear password fields after successful save
        ['pf_cur_pw','pf_new_pw','pf_rep_pw'].forEach(id => {
          const el = document.getElementById(id);
          if (el) el.value = '';
        });

        showToast(data.message || 'Profile updated!', 'success');
        showSaveStatus('success', '✓ Saved');

      } else {
        showToast(data.message || 'Could not save. Try again.', 'error');
        showSaveStatus('error', '✗ Not saved');
      }

    } catch (err) {
      showToast('Server error — make sure XAMPP is running.', 'error');
      showSaveStatus('error', '✗ Server error');
    } finally {
      if (saveProfileBtn) {
        saveProfileBtn.disabled = false;
        saveProfileBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
      }
    }
  });

  function showSaveStatus(type, msg) {
    if (!profileSaveStatus) return;
    profileSaveStatus.className = 'profile-save-status ' + type;
    profileSaveStatus.innerHTML =
      `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark'}"></i> ${msg}`;
    setTimeout(() => {
      profileSaveStatus.className = 'profile-save-status';
      profileSaveStatus.innerHTML = '';
    }, 4000);
  }

  cancelProfileBtn?.addEventListener('click', () => {
    profileForm?.reset();
    // Reset photo preview if nothing was saved
    if (profilePhotoInput?.files[0]) {
      pavPhotoImg.src = '';
      pavPhotoImg.classList.remove('visible');
      pavPhotoInitials?.classList.remove('hidden');
      pavRemoveBtn?.classList.remove('visible');
      profilePhotoInput.value = '';
    }
    showToast('Changes discarded.', 'error');
  });

  function validateProfileForm() {
    let valid = true;

    // Required text fields
    const required = [
      { id: 'pf_last',  errId: 'pf_lastError',  msg: 'Last name is required.'  },
      { id: 'pf_first', errId: 'pf_firstError', msg: 'First name is required.' },
      { id: 'pf_email', errId: 'pf_emailError', msg: 'Email is required.',
        extra: v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) ? '' : 'Enter a valid email address.' },
    ];

    required.forEach(({ id, errId, msg, extra }) => {
      const el  = document.getElementById(id);
      const err = document.getElementById(errId);
      if (!el || !err) return;
      const val  = el.value.trim();
      const emsg = !val ? msg : (extra ? extra(val) : '');
      err.textContent = emsg;
      el.classList.toggle('error', !!emsg);
      if (emsg) valid = false;
    });

    // Password block — only validate if any pw field is touched
    const curPw  = document.getElementById('pf_cur_pw')?.value  || '';
    const newPw  = document.getElementById('pf_new_pw')?.value  || '';
    const repPw  = document.getElementById('pf_rep_pw')?.value  || '';
    const curErr = document.getElementById('pf_cur_pwError');
    const newErr = document.getElementById('pf_new_pwError');
    const repErr = document.getElementById('pf_rep_pwError');

    if (curPw || newPw || repPw) {
      if (!curPw && curErr) { curErr.textContent = 'Enter current password.'; valid = false; }
      if (newPw && newPw.length < 8 && newErr) { newErr.textContent = 'Minimum 8 characters.'; valid = false; }
      if (newPw && repPw && newPw !== repPw && repErr) { repErr.textContent = 'Passwords do not match.'; valid = false; }
    }

    return valid;
  }

  // Live: clear error on each field as user types
  profileForm?.querySelectorAll('input, select').forEach(el => {
    el.addEventListener('input', () => {
      const errEl = document.getElementById(el.id + 'Error');
      if (errEl) errEl.textContent = '';
      el.classList.remove('error');
    });
  });

  /* ── PAGINATION ── */
  document.querySelectorAll('.pg-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      btn.closest('.pagination').querySelectorAll('.pg-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
    });
  });

  /* ── CANCEL RESERVATIONS ── */
  document.querySelectorAll('.reserve-card .btn-outline-sm.danger').forEach(btn => {
    btn.addEventListener('click', () => {
      const card = btn.closest('.reserve-card');
      const id   = card?.querySelector('.rc-id')?.textContent;
      if (confirm('Cancel reservation ' + id + '?')) {
        card.remove();
        showToast('Reservation ' + id + ' cancelled.', 'error');
      }
    });
  });

  /* ── PASSWORD TOGGLES ── */
  document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.target);
      if (!input) return;
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      const icon = btn.querySelector('i');
      if (icon) { icon.classList.toggle('fa-eye', !isHidden); icon.classList.toggle('fa-eye-slash', isHidden); }
    });
  });

  /* ── ADMIN: STUDENT DETAIL MODAL ── */
  const studentModal = document.getElementById('studentModal');

  // Dummy student data keyed by ID number (simulate DB lookup)
  const studentDB = {
    '12345678': { name: 'Santos, Maria Clara', course: 'BSIT', year: '3rd Year', email: 'mclaras@uc.edu.ph', sessions: 28, used: 2, lastSitin: 'Mar 11, 2026', address: 'Brgy. Talamban, Cebu City' },
    '87654321': { name: 'Reyes, Juan Paolo',   course: 'BSCS', year: '2nd Year', email: 'jpreyes@uc.edu.ph', sessions: 30, used: 0, lastSitin: 'Mar 14, 2026', address: 'Brgy. Lahug, Cebu City' },
    '11223344': { name: 'Cruz, Ana Liza',      course: 'BSIT', year: '1st Year', email: 'alcruz@uc.edu.ph',  sessions: 15, used: 15, lastSitin: 'Mar 13, 2026', address: 'Brgy. Mandaue, Mandaue City' },
    '55667788': { name: 'Lim, Roberto Jr.',    course: 'BSCS', year: '4th Year', email: 'rjlim@uc.edu.ph',   sessions: 5,  used: 25, lastSitin: 'Mar 12, 2026', address: 'Brgy. Banilad, Cebu City' },
    '99887766': { name: 'Dela Cruz, Hannah',   course: 'BSIT', year: '2nd Year', email: 'hdelacruz@uc.edu.ph', sessions: 30, used: 0, lastSitin: 'Mar 12, 2026', address: 'Brgy. Apas, Cebu City' },
    '44332211': { name: 'Flores, Carlo M.',    course: 'BSCS', year: '3rd Year', email: 'cmflores@uc.edu.ph', sessions: 22, used: 8, lastSitin: 'Mar 11, 2026', address: 'Brgy. Mabolo, Cebu City' },
  };

  function openStudentModal(idNum) {
    const s = studentDB[idNum];
    if (!s) {
      showToast('No student found with ID: ' + idNum, 'error');
      return;
    }

    const initials = s.name.split(',')[0].trim().charAt(0).toUpperCase();
    document.getElementById('smpAvatar').textContent   = initials;
    document.getElementById('smpName').textContent     = s.name;
    document.getElementById('smpCourse').textContent   = s.course + ' — ' + s.year;
    document.getElementById('smpId').textContent       = 'ID: ' + idNum;
    document.getElementById('smpSessions').textContent = s.sessions;
    document.getElementById('siCourse').textContent    = s.course;
    document.getElementById('siYear').textContent      = s.year;
    document.getElementById('siEmail').textContent     = s.email;
    document.getElementById('siUsed').textContent      = s.used + ' sessions';
    document.getElementById('siLastSitin').textContent = s.lastSitin;
    document.getElementById('siAddress').textContent   = s.address;

    studentModal.classList.add('open');
  }

  function closeStudentModal() {
    studentModal?.classList.remove('open');
  }

  document.getElementById('closeStudentModal')?.addEventListener('click', closeStudentModal);
  document.getElementById('closeStudentModal2')?.addEventListener('click', closeStudentModal);
  studentModal?.addEventListener('click', e => { if (e.target === studentModal) closeStudentModal(); });

  /* ── ADMIN: VIEW STUDENT BUTTONS (from Students table) ── */
  document.querySelectorAll('.admin-view-student').forEach(btn => {
    btn.addEventListener('click', () => openStudentModal(btn.dataset.id));
  });

  /* ── ADMIN: SEARCH PAGE ── */
  const adminSearchInput = document.getElementById('adminSearchInput');
  const adminSearchBtn   = document.getElementById('adminSearchBtn');
  const searchEmptyState = document.getElementById('searchEmptyState');

  function runAdminSearch() {
    const idNum = adminSearchInput?.value.trim();
    if (!idNum) { showToast('Please enter an ID number.', 'error'); return; }
    if (!/^\d{1,8}$/.test(idNum)) { showToast('ID number must be up to 8 digits.', 'error'); return; }

    // Add to recent chips
    addRecentChip(idNum);

    openStudentModal(idNum);
  }

  adminSearchBtn?.addEventListener('click', runAdminSearch);
  adminSearchInput?.addEventListener('keydown', e => { if (e.key === 'Enter') runAdminSearch(); });

  /* recent chips */
  document.querySelectorAll('.recent-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      if (adminSearchInput) adminSearchInput.value = chip.dataset.id;
      openStudentModal(chip.dataset.id);
    });
  });

  function addRecentChip(idNum) {
    const container = document.getElementById('recentChips');
    if (!container) return;
    // Don't duplicate
    if ([...container.querySelectorAll('.recent-chip')].some(c => c.dataset.id === idNum)) return;
    const chip = document.createElement('span');
    chip.className   = 'recent-chip';
    chip.dataset.id  = idNum;
    chip.innerHTML   = `<i class="fa-solid fa-user"></i> ${idNum}`;
    chip.addEventListener('click', () => {
      if (adminSearchInput) adminSearchInput.value = idNum;
      openStudentModal(idNum);
    });
    container.prepend(chip);
  }

  /* ── TOAST ── */
  function showToast(message, type = 'success') {
    document.querySelector('.toast')?.remove();
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.innerHTML = '<i class="fa-solid ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark') + '"></i> ' + message;
    document.body.appendChild(toast);
    requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('show')));
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 400); }, 3200);
  }

});
  </script>
</body>
</html>