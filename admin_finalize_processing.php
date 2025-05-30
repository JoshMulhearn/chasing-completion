<?php
session_start();
require_once __DIR__ . '/db_connect.php'; // Your PDO connection

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}
$admin_id_session = $_SESSION['admin_id']; // From admin login
$admin_username_session = $_SESSION['admin_username'];

$processing_action_type = $_POST['processing_action_type'] ?? null;
$processed_items_data = $_POST['items'] ?? [];

if (empty($processing_action_type) || empty($processed_items_data) || !is_array($processed_items_data)) {
    header("Location: admin_dashboard.php?error=No data received for finalization.");
    exit;
}

// TODO: Implement CSRF token validation here

$redirect_to_edit_desc_params = null; // To store params if one item needs redirect

try {
    $pdo->beginTransaction();

    foreach ($processed_items_data as $item_db_id => $data) {
        $item_db_id_int = (int)$item_db_id;
        $decision = $data['decision'] ?? 'ignore';
        $admin_internal_notes = trim($data['admin_internal_notes'] ?? '');

        if ($processing_action_type === 'process_requests') {
            $table_name = "achievement_requests";
            $new_status = null;

            if ($decision === 'resolved_by_admin_desc') {
                // This specific request will be handled by redirecting.
                // We only redirect for the *first* such item if multiple are selected.
                if ($redirect_to_edit_desc_params === null) {
                    $redirect_to_edit_desc_params = [
                        'appid' => $data['appid'],
                        'apiname' => $data['apiname'],
                        'ach_name' => $data['ach_name'],
                        'source_request_id' => $item_db_id_int // To link back and update status after editing
                    ];
                    // Mark as 'in_progress'
                    $stmt_status = $pdo->prepare("UPDATE $table_name SET status = 'in_progress', admin_id_processed = ?, processed_at = CURRENT_TIMESTAMP, admin_notes_internal = ? WHERE id = ? AND status = 'pending'");
                    $stmt_status->execute([$admin_id_session, $admin_internal_notes, $item_db_id_int]);
                }
                continue; // Skip further direct DB update for this item, redirect will handle
            } elseif ($decision === 'resolved_other') {
                $new_status = 'resolved_other';
            } elseif ($decision === 'ignore') {
                // No DB change for 'ignore', or you could set status to 'viewed'
                // error_log("Ignoring request ID: " . $item_db_id_int);
                continue;
            }

            if ($new_status) {
                $stmt_update = $pdo->prepare("UPDATE $table_name SET status = ?, admin_id_processed = ?, processed_at = CURRENT_TIMESTAMP, admin_notes_internal = ? WHERE id = ? AND status = 'pending'");
                $stmt_update->execute([$new_status, $admin_id_session, $admin_internal_notes, $item_db_id_int]);
            }

        } elseif ($processing_action_type === 'process_notes') {
            $table_name = "user_achievement_notes";
            $new_status = null;
            $rejection_reason = trim($data['rejection_reason'] ?? '');

            if ($decision === 'approved') {
                $new_status = 'approved';
            } elseif ($decision === 'rejected') {
                $new_status = 'rejected';
            } elseif ($decision === 'ignore') {
                // error_log("Ignoring note ID: " . $item_db_id_int);
                continue;
            }

            if ($new_status) {
                $stmt_update = $pdo->prepare("UPDATE $table_name SET status = ?, reviewed_by_admin_id = ?, reviewed_at = CURRENT_TIMESTAMP, admin_rejection_reason = ?, admin_notes_internal = ? WHERE id = ? AND status = 'pending_review'");
                $stmt_update->execute([$new_status, $admin_id_session, ($new_status === 'rejected' ? $rejection_reason : NULL), $admin_internal_notes, $item_db_id_int]);
            }
        }
    }

    $pdo->commit();

    if ($redirect_to_edit_desc_params !== null) {
        $query_string = http_build_query($redirect_to_edit_desc_params);
        header("Location: edit_achievement_description.php?" . $query_string);
        exit;
    } else {
        header("Location: admin_dashboard.php?success=Items processed.");
        exit;
    }

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Error in admin_finalize_processing.php: " . $e->getMessage());
    header("Location: admin_dashboard.php?error=Database error during finalization. Code: " . $e->getCode());
    exit;
}
?>