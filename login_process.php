<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

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
    $_SESSION['student_id']    = $student['id'];
    $_SESSION['student_name']  = $student['first_name'] . ' ' . $student['last_name'];
    $_SESSION['id_number']     = $student['id_number'];
    $_SESSION['course']        = $student['course'];
    $_SESSION['profile_photo'] = $student['profile_photo'] ?? '';

    echo json_encode(['success' => true, 'message' => 'Login successful! Redirecting…']);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid ID Number or password.']);
}
?>