<?php
session_start();
require_once __DIR__ . '/db_connect.php'; // Your PDO connection

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}
// $admin_username_session = $_SESSION['admin_username']; // Available if needed

$action_type = $_POST['action_type'] ?? null;
$selected_ids_raw = $_POST['selected_ids'] ?? [];

if (empty($action_type) || empty($selected_ids_raw) || !is_array($selected_ids_raw)) {
    header("Location: admin_dashboard.php?error=No items selected or invalid action.");
    exit;
}

// Sanitize selected IDs to ensure they are integers
$selected_ids = array_map('intval', $selected_ids_raw);
$selected_ids = array_filter($selected_ids, function($id) { return $id > 0; }); // Remove non-positive integers

if (empty($selected_ids)) {
    header("Location: admin_dashboard.php?error=No valid items selected.");
    exit;
}

// TODO: Implement CSRF token validation here

$items_to_process = [];
$page_title = "Process Selections";
$form_action_target = "admin_finalize_processing.php";

try {
    $placeholders = implode(',', array_fill(0, count($selected_ids), '?')); // Creates ?,?,?

    if ($action_type === 'process_requests') {
        $page_title = "Process User Help Requests";
        // Fetch full details including the request message
        $stmt = $pdo->prepare("SELECT * FROM achievement_requests WHERE id IN ($placeholders) AND status = 'pending' ORDER BY requested_at ASC");
        $stmt->execute($selected_ids);
        $items_to_process = $stmt->fetchAll();
    } elseif ($action_type === 'process_notes') {
        $page_title = "Review User Submitted Notes";
        // Fetch full details including the note text
        $stmt = $pdo->prepare("SELECT * FROM user_achievement_notes WHERE id IN ($placeholders) AND status = 'pending_review' ORDER BY submitted_at ASC");
        $stmt->execute($selected_ids);
        $items_to_process = $stmt->fetchAll();
    } else {
        header("Location: admin_dashboard.php?error=Invalid action type.");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching items for admin processing: " . $e->getMessage());
    die("Database error fetching items. Please check logs. Error: " . $e->getCode());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($page_title); ?> - Admin</title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { font-family: 'Roboto', sans-serif; background-color: #f4f7f6; color: #333; margin: 0; }
        .processing-container { max-width: 900px; margin: 20px auto; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .item-to-process { border: 1px solid #e0e0e0; padding: 20px; margin-bottom: 20px; border-radius: 5px; background: #fdfdfd; }
        .item-to-process h4 { margin-top: 0; color: #2c3e50; }
        .item-meta { font-size: 0.9em; color: #555; margin-bottom: 10px; line-height: 1.5; }
        .item-meta strong { color: #333; }
        .item-content-label { font-weight: bold; margin-top:10px; display:block; }
        .item-content { background: #eef2f5; padding: 10px; border-radius: 3px; margin-top: 5px; margin-bottom: 15px; white-space: pre-wrap; max-height: 200px; overflow-y: auto; border: 1px solid #d0d8de; }
        .admin-actions { margin-top:15px; padding-top:15px; border-top:1px solid #eee; }
        .admin-actions p { margin-bottom: 8px; font-weight: bold; }
        .admin-actions label { margin-right: 15px; display: inline-block; margin-bottom: 5px; cursor:pointer; }
        .admin-actions input[type="radio"], .admin-actions input[type="checkbox"] { margin-right: 5px; }
        .admin-notes textarea { width: 100%; box-sizing: border-box; min-height: 70px; margin-top: 5px; padding: 8px; border: 1px solid #ccc; border-radius: 3px; }
        .form-actions button, .form-actions a { padding: 10px 15px; text-decoration: none; border-radius: 4px; font-size: 1em; }
        .form-actions button { background-color: #3498db; color: white; border: none; cursor: pointer; }
        .form-actions button:hover { background-color: #2980b9; }
        .form-actions a { background-color: #7f8c8d; color: white; margin-left: 10px; }
        .form-actions a:hover { background-color: #6c7a89; }
        .navbar { /* ensure navbar has styles if not already globally applied dark */ background-color: #2c3e50; }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    <div class="processing-container">
        <h1><?php echo htmlspecialchars($page_title); ?></h1>

        <?php if (empty($items_to_process)): ?>
            <p>No valid items found for processing, or they might have already been processed.</p>
            <p><a href="admin_dashboard.php">Back to Dashboard</a></p>
        <?php else: ?>
            <form action="<?php echo $form_action_target; ?>" method="POST">
                <input type="hidden" name="processing_action_type" value="<?php echo htmlspecialchars($action_type); ?>">
                <!-- TODO: Add CSRF token input here -->

                <?php foreach ($items_to_process as $item): ?>
                    <div class="item-to-process">
                        <input type="hidden" name="items[<?php echo $item['id']; ?>][id]" value="<?php echo $item['id']; ?>">
                        <h4>
                            Achievement: <?php echo htmlspecialchars($item['ach_display_name_at_request'] ?? $item['ach_display_name_at_submission']); ?>
                        </h4>
                        <div class="item-meta">
                            <strong>AppID:</strong> <?php echo $item['appid']; ?>,
                            <strong>API Name:</strong> <?php echo htmlspecialchars($item['achievement_apiname']); ?><br>
                            <strong>Submitted by:</strong> <?php echo htmlspecialchars($item['username_at_request'] ?? $item['username_at_submission']); ?>
                            (SteamID: <?php echo htmlspecialchars($item['user_steam_id']); ?>)<br>
                            <?php if ($action_type === 'process_requests'): ?>
                                <strong>Requested At:</strong> <?php echo date("M j, Y, g:i a", strtotime($item['requested_at'])); ?><br>
                                <?php if (!empty($item['request_message'])): ?>
                                    <span class="item-content-label">User Message:</span>
                                    <div class="item-content"><?php echo nl2br(htmlspecialchars($item['request_message'])); ?></div>
                                <?php endif; ?>
                            <?php elseif ($action_type === 'process_notes'): ?>
                                <strong>Submitted At:</strong> <?php echo date("M j, Y, g:i a", strtotime($item['submitted_at'])); ?><br>
                                <span class="item-content-label">User Note Text:</span>
                                <div class="item-content"><?php echo nl2br(htmlspecialchars($item['note_text'])); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="admin-actions">
                            <?php if ($action_type === 'process_requests'): ?>
                                <p>Action for this Request:</p>
                                <label><input type="radio" name="items[<?php echo $item['id']; ?>][decision]" value="resolved_by_admin_desc" required> Write/Update Admin Desc & Resolve</label><br>
                                <label><input type="radio" name="items[<?php echo $item['id']; ?>][decision]" value="resolved_other"> Mark Resolved (Other Reason)</label><br>
                                <label><input type="radio" name="items[<?php echo $item['id']; ?>][decision]" value="ignore"> Keep Pending (Ignore for now)</label>
                                <!-- Hidden fields for redirect if "Write Admin Desc" is chosen -->
                                <input type="hidden" name="items[<?php echo $item['id']; ?>][appid]" value="<?php echo $item['appid']; ?>">
                                <input type="hidden" name="items[<?php echo $item['id']; ?>][apiname]" value="<?php echo htmlspecialchars($item['achievement_apiname']); ?>">
                                <input type="hidden" name="items[<?php echo $item['id']; ?>][ach_name]" value="<?php echo htmlspecialchars($item['ach_display_name_at_request'] ?? $item['ach_display_name_at_submission']); ?>">
                            <?php elseif ($action_type === 'process_notes'): ?>
                                <p>Action for this User Note:</p>
                                <label><input type="radio" name="items[<?php echo $item['id']; ?>][decision]" value="approved" required> Approve Note</label><br>
                                <label><input type="radio" name="items[<?php echo $item['id']; ?>][decision]" value="rejected"> Reject Note</label><br>
                                <label><input type="radio" name="items[<?php echo $item['id']; ?>][decision]" value="ignore"> Keep Pending Review (Ignore for now)</label><br>
                                <label for="rejection_reason_<?php echo $item['id']; ?>" style="display:block; margin-top:5px;">Rejection Reason (if rejecting):</label>
                                <textarea id="rejection_reason_<?php echo $item['id']; ?>" name="items[<?php echo $item['id']; ?>][rejection_reason]" placeholder="Optional: Explain why the note was rejected."></textarea>
                            <?php endif; ?>
                            <br>
                            <label for="admin_comment_<?php echo $item['id']; ?>" style="display:block; margin-top:10px;">Admin Internal Notes (optional):</label>
                            <textarea id="admin_comment_<?php echo $item['id']; ?>" name="items[<?php echo $item['id']; ?>][admin_internal_notes]" placeholder="Internal notes for admin team."></textarea>
                        </div>
                    </div>
                <?php endforeach; ?>
                <hr style="margin: 20px 0;">
                <div class="form-actions">
                    <button type="submit">Finalize Actions for These Items</button>
                    <a href="admin_dashboard.php">Cancel & Back to Dashboard</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <?php include 'footer.php'; ?>
</body>
</html>