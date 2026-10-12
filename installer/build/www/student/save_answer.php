<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['cbt_student'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$student = $_SESSION['cbt_student'];
$conn = get_db_connection();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['examid']) || empty($data['questionid'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Payload']);
    exit;
}

$studentid = $student['studentid'];
$examid = $data['examid'];
$questionid = $data['questionid'];
$option = isset($data['selected_option']) ? strtoupper(trim($data['selected_option'])) : null;
$bookmarked = isset($data['is_bookmarked']) ? intval($data['is_bookmarked']) : 0;

$stmt = $conn->prepare("REPLACE INTO tbl_cbt_responses (studentid, examid, questionid, selected_option, is_bookmarked, last_updated) VALUES (?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("ssssi", $studentid, $examid, $questionid, $option, $bookmarked);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'saved_option' => $option, 'bookmarked' => $bookmarked]);
} else {
    echo json_encode(['status' => 'error', 'message' => $conn->error]);
}