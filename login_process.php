<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$login_id  = trim($_POST['loginId']       ?? '');
$password  =      $_POST['loginPassword'] ?? '';
$role      = trim($_POST['loginRole']     ?? 'student');

if (!$login_id || !$password) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
    exit;
}

if ($role === 'admin' || str_starts_with($login_id, 'ADM-')) {
    // --- ADMIN LOGIN ---
    // Support both username login and ADM- ID number login
    if (str_starts_with($login_id, 'ADM-')) {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE id_number = ?");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
    }
    $stmt->execute([$login_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($password, $admin['password'])) {
        session_regenerate_id(true);
        $_SESSION['role']           = 'admin';
        $_SESSION['admin_id']       = $admin['id'];
        $_SESSION['admin_name']     = $admin['name'];
        $_SESSION['admin_logged_in'] = true;

        echo json_encode([
            'success'  => true,
            'role'     => 'admin',
            'message'  => 'Welcome, Admin! Redirecting…'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    }

} else {
    // --- STUDENT LOGIN ---
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id_number = ?");
    $stmt->execute([$login_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student && password_verify($password, $student['password'])) {
        session_regenerate_id(true);
        $_SESSION['role']          = 'student';
        $_SESSION['student_id']    = $student['id'];
        $_SESSION['student_name']  = $student['first_name'] . ' ' . $student['last_name'];
        $_SESSION['id_number']     = $student['id_number'];
        $_SESSION['course']        = $student['course'];
        $_SESSION['profile_photo'] = $student['profile_photo'] ?? '';

        echo json_encode([
            'success'  => true,
            'role'     => 'student',
            'message'  => 'Login successful! Redirecting…'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid ID Number or password.']);
    }
}
?>