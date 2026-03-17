<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$id_number   = trim($_POST['idNumber']       ?? '');
$last_name   = trim($_POST['lastName']       ?? '');
$first_name  = trim($_POST['firstName']      ?? '');
$middle_name = trim($_POST['middleName']     ?? '');
$course      = trim($_POST['course']         ?? '');
$year_level  = intval($_POST['courseLevel']  ?? 0);
$email       = trim($_POST['email']          ?? '');
$address     = trim($_POST['address']        ?? '');
$password    =      $_POST['password']       ?? '';
$repeat_pw   =      $_POST['repeatPassword'] ?? '';

// Validation
if (!$id_number || !$last_name || !$first_name || !$email || !$password) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}
if (!preg_match('/^\d{8}$/', $id_number)) {
    echo json_encode(['success' => false, 'message' => 'ID Number must be exactly 8 digits.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}
if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    exit;
}
if ($password !== $repeat_pw) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}
if ($year_level < 1 || $year_level > 4) {
    echo json_encode(['success' => false, 'message' => 'Year level must be 1 to 4.']);
    exit;
}

// Check duplicate
$stmt = $pdo->prepare("SELECT id FROM students WHERE id_number = ? OR email = ?");
$stmt->execute([$id_number, $email]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'ID Number or email is already registered.']);
    exit;
}

// Insert into database
$hashed = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("
    INSERT INTO students 
    (id_number, last_name, first_name, middle_name, course, year_level, email, address, password)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $id_number, $last_name, $first_name, $middle_name,
    $course, $year_level, $email, $address, $hashed
]);

// Auto login after register
session_regenerate_id(true);
unset($_SESSION['admin_logged_in'], $_SESSION['admin_name']);
$_SESSION['role']         = 'student';
$_SESSION['student_id']   = $pdo->lastInsertId();
$_SESSION['student_name'] = $first_name . ' ' . $last_name;
$_SESSION['id_number']    = $id_number;
$_SESSION['course']       = $course;

echo json_encode(['success' => true, 'message' => 'Account created! Redirecting…']);
?>