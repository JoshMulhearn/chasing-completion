<?php
session_start();
require_once __DIR__ . '/db_connect.php'; // Your PDO connection

header('Content-Type: application/json');

// User must be logged in (non-admin)
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['userData']['steam_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required. Please log in.']);
    exit;
}
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    echo json_encode(['success' => false, 'message' => 'Admins cannot perform this user action.']);
    exit;
}

$steam_id = $_SESSION['userData']['steam_id'];
$username = $_SESSION['userData']['name'] ?? 'Steam User'; // Get username from session
$action = $_POST['action'] ?? null;

if (!$action) {
    echo json_encode(['success' => false, 'message' => 'No action specified.']);
    exit;
}

// TODO: Implement CSRF token validation here

$appid = filter_input(INPUT_POST, 'appid', FILTER_VALIDATE_INT);
$apiname = trim(filter_input(INPUT_POST, 'apiname', FILTER_UNSAFE_RAW)); // Using UNSAFE_RAW and relying on prepared statements
$achname = trim(filter_input(INPUT_POST, 'achname', FILTER_UNSAFE_RAW)); // Display name

if (!$appid || empty($apiname) || empty($achname)) {
    echo json_encode(['success' => false, 'message' => 'Missing required achievement data.']);
    exit;
}

try {
    if ($action === 'request_help') {
        $request_message = trim(filter_input(INPUT_POST, 'message', FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES));

        // Check for existing PENDING request
        $stmt_check = $pdo->prepare("SELECT id FROM achievement_requests WHERE user_steam_id = ? AND appid = ? AND achievement_apiname = ? AND status = 'pending'");
        $stmt_check->execute([$steam_id, $appid, $apiname]);
        if ($stmt_check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'You already have a pending help request for this achievement.']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO achievement_requests (user_steam_id, username_at_request, appid, achievement_apiname, ach_display_name_at_request, request_message, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$steam_id, $username, $appid, $apiname, $achname, $request_message]);
        echo json_encode(['success' => true, 'message' => 'Help request submitted! Admins will review it.']);

    } elseif ($action === 'write_note') {
        $note_text = trim($_POST['note'] ?? ''); // Raw from prompt, will be escaped
        if (empty($note_text)) {
            echo json_encode(['success' => false, 'message' => 'Note text cannot be empty.']);
            exit;
        }
        // Sanitize for display, but store potentially raw (or use markdown, then process for display)
        // For now, htmlspecialchars on output is key.

        $stmt_check_note = $pdo->prepare("SELECT id FROM user_achievement_notes WHERE user_steam_id = ? AND appid = ? AND achievement_apiname = ? AND status = 'pending_review'");
        $stmt_check_note->execute([$steam_id, $appid, $apiname]);
        if ($stmt_check_note->fetch()) {
            echo json_encode(['success' => false, 'message' => 'You already have a note pending review for this achievement.']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO user_achievement_notes (user_steam_id, username_at_submission, appid, achievement_apiname, ach_display_name_at_submission, note_text, status) VALUES (?, ?, ?, ?, ?, ?, 'pending_review')");
        // Storing note_text as is, will use htmlspecialchars on display
        $stmt->execute([$steam_id, $username, $appid, $apiname, $achname, $note_text]);
        echo json_encode(['success' => true, 'message' => 'Your note has been submitted for review!']);

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
    }

} catch (PDOException $e) {
    error_log("Error in process_user_achievement_action.php: " . $e->getMessage() . " for action " . $action);
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please try again. Details: ' . $e->getCode()]);
}
?>