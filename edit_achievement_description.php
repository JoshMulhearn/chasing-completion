<?php
session_start();
require_once __DIR__ . '/db_connect.php'; // Your PDO connection

// ... (your existing admin auth check) ...
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}
$admin_id_session = $_SESSION['admin_id']; // Get admin ID

// New: Get source_request_id if present
$source_request_id = filter_input(INPUT_GET, 'source_request_id', FILTER_VALIDATE_INT);

// ... (your existing code to get appid, apiname, ach_name from GET and fetch current_description) ...
$appid = filter_input(INPUT_GET, 'appid', FILTER_VALIDATE_INT);
$apiname = trim(filter_input(INPUT_GET, 'apiname', FILTER_UNSAFE_RAW));
$ach_name_display = trim(filter_input(INPUT_GET, 'ach_name', FILTER_UNSAFE_RAW) ?? 'Achievement');

if (!$appid || empty($apiname)) {
    die("Error: Missing or invalid AppID or Achievement API Name.");
}
// Fetch current admin description logic... (your existing code)
$current_description = '';
try {
    $stmt_curr = $pdo->prepare("SELECT description FROM achievement_admin_descriptions WHERE appid = ? AND achievement_apiname = ?");
    $stmt_curr->execute([$appid, $apiname]);
    $current_description_db = $stmt_curr->fetchColumn();
    if ($current_description_db !== false) {
        $current_description = $current_description_db;
    }
} catch (PDOException $e) { /* ... */ }


$message = '';
if (isset($_GET['success_msg'])) $message = htmlspecialchars($_GET['success_msg']);


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // TODO: Implement CSRF token validation here
    $new_description = trim($_POST['admin_description'] ?? '');
    // $admin_username = $_SESSION['admin_username']; // Already have $admin_id_session

    try {
        $pdo->beginTransaction();

        // Upsert logic for achievement_admin_descriptions
        $stmt_check = $pdo->prepare("SELECT id FROM achievement_admin_descriptions WHERE appid = ? AND achievement_apiname = ?");
        $stmt_check->execute([$appid, $apiname]);
        $exists = $stmt_check->fetchColumn();

        if ($exists) {
            $stmt_update = $pdo->prepare("UPDATE achievement_admin_descriptions SET description = ?, admin_id_updated = ?, last_updated_at = CURRENT_TIMESTAMP WHERE appid = ? AND achievement_apiname = ?");
            $stmt_update->execute([$new_description, $admin_id_session, $appid, $apiname]);
        } else {
            $stmt_insert = $pdo->prepare("INSERT INTO achievement_admin_descriptions (appid, achievement_apiname, description, admin_id_updated) VALUES (?, ?, ?, ?)");
            $stmt_insert->execute([$appid, $apiname, $new_description, $admin_id_session]);
        }
        $message = "Admin description saved successfully!";
        $current_description = $new_description; // Update for display

        // NEW: If this edit was triggered by a user request, mark that request as resolved
        if ($source_request_id) {
            $stmt_resolve_req = $pdo->prepare("UPDATE achievement_requests SET status = 'resolved_by_admin_desc', admin_id_processed = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ? AND (status = 'pending' OR status = 'in_progress')");
            $stmt_resolve_req->execute([$admin_id_session, $source_request_id]);
            if ($stmt_resolve_req->rowCount() > 0) {
                $message .= " (User request #" . $source_request_id . " marked as resolved.)";
            }
        }

        $pdo->commit();
         // Redirect to prevent form resubmission and to clear POST data, showing the message
        header("Location: " . $_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') ? '&' : '?') . "success_msg=" . urlencode($message));
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error saving admin desc or resolving request: " . $e->getMessage());
        $message = "Error saving description: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Admin Description for <?php echo htmlspecialchars($ach_name_display); ?></title>
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Styles for edit_achievement_description.php */
        body { font-family: 'Roboto', sans-serif; background-color: #f4f7f6; color: #333; }
        .container { max-width: 800px; margin: 20px auto; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        textarea { width: 100%; box-sizing:border-box; min-height: 150px; padding: 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px; }
        .button, button { background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .button:hover, button:hover { background-color: #0056b3; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;}
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;}
        .navbar { background-color: #2c3e50; }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    <div class="container">
        <h2>Edit Admin Description for "<?php echo htmlspecialchars($ach_name_display); ?>"</h2>
        <p><strong>AppID:</strong> <?php echo htmlspecialchars($appid); ?>, <strong>API Name:</strong> <?php echo htmlspecialchars($apiname); ?></p>
        <?php if ($source_request_id): ?>
            <p style="font-style:italic; color:green;">This edit was initiated from user request #<?php echo $source_request_id; ?>.</p>
        <?php endif; ?>

        <?php if (!empty($message)): ?>
            <div class="message <?php echo (strpos(strtolower($message), 'error') === false) ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["REQUEST_URI"]); // Post to self to preserve GET params ?>">
            <!-- TODO: Add CSRF token input here -->
            <label for="admin_description">Admin Description/Guide:</label>
            <textarea id="admin_description" name="admin_description"><?php echo htmlspecialchars($current_description); ?></textarea>
            <button type="submit">Save Description</button>
            <a href="admin_dashboard.php" class="button" style="margin-left: 10px; background-color: #6c757d;">Back to Admin Dashboard</a>
        </form>
    </div>
    <?php include 'footer.php'; ?>
</body>
</html>