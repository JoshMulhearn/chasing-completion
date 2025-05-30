<?php
session_start();
require_once __DIR__ . '/db_connect.php'; // Ensure this uses PDO and defines $pdo

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}
$admin_username = $_SESSION['admin_username'] ?? 'Admin';

// Fetch pending user requests
$pending_user_requests = [];
try {
   $stmt_requests = $pdo->query("SELECT id, username_at_request, appid, ach_display_name_at_request FROM achievement_requests WHERE status = 'pending' ORDER BY requested_at ASC LIMIT 20");
   $pending_user_requests = $stmt_requests->fetchAll();
} catch (PDOException $e) {
   error_log("Error fetching user requests for admin dashboard: " . $e->getMessage());
   // You might want to set a displayable error message here
}

// Fetch pending user notes
$pending_user_notes = [];
try {
   $stmt_notes = $pdo->query("SELECT id, username_at_submission, appid, ach_display_name_at_submission FROM user_achievement_notes WHERE status = 'pending_review' ORDER BY submitted_at ASC LIMIT 20");
   $pending_user_notes = $stmt_notes->fetchAll();
} catch (PDOException $e) {
   error_log("Error fetching user notes for admin dashboard: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!--FONTS-->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Genos:ital,wght@0,100..900;1,100..900&family=Orbitron:wght@400..900&family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <!---->
    <!--STYLE SHEETS-->
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/style.css">
    <!---->
    <title>Chasing Completion</title>
</head>
<body class="admin-dashboard-page">
    <?php include 'navbar.php'; ?>

    <div class="admin-dashboard-container">
        <h1 class="dashboard-main-title">Admin Page (main)</h1>
        <?php if(isset($_GET['success'])): ?>
            <p style="color:lightgreen; text-align:center; background: #2c3e50; padding:10px; border-radius:5px;">Operation successful!</p>
        <?php endif; ?>
        <?php if(isset($_GET['error'])): ?>
            <p style="color:salmon; text-align:center; background: #2c3e50; padding:10px; border-radius:5px;">An error occurred: <?php echo htmlspecialchars($_GET['error']); ?></p>
        <?php endif; ?>

        <div class="dashboard-panels-container">
            <!-- User Requests Panel -->
            <div class="dashboard-panel">
                <form id="requestsForm" method="POST" action="admin_process_selections.php">
                    <input type="hidden" name="action_type" value="process_requests">
                    <!-- TODO: Add CSRF token input here -->
                    <h3>User Help Requests</h3>
                    <ul class="item-list">
                        <?php if (!empty($pending_user_requests)): ?>
                            <?php foreach ($pending_user_requests as $request): ?>
                                <li class="item-row">
                                    <div class="item-user" title="<?php echo htmlspecialchars($request['username_at_request']); ?>"><?php echo htmlspecialchars($request['username_at_request']); ?></div>
                                    <div class="item-details" title="AppID: <?php echo $request['appid']; ?> - <?php echo htmlspecialchars($request['ach_display_name_at_request']); ?>">
                                        <?php echo htmlspecialchars($request['ach_display_name_at_request']); ?>
                                    </div>
                                    <div class="item-checkbox-container">
                                        <input type="checkbox" name="selected_ids[]" value="<?php echo $request['id']; ?>" class="item-checkbox">
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="empty-panel-message">No pending user requests.</li>
                        <?php endif; ?>
                    </ul>
                    <?php if (!empty($pending_user_requests)): ?>
                    <button type="submit" class="panel-button">Process Selected Requests</button>
                    <?php endif; ?>
                </form>
            </div>

            <!-- User Wrote Descriptions Panel -->
            <div class="dashboard-panel">
                <form id="notesForm" method="POST" action="admin_process_selections.php">
                    <input type="hidden" name="action_type" value="process_notes">
                    <!-- TODO: Add CSRF token input here -->
                    <h3>User Submitted Notes</h3>
                    <ul class="item-list">
                         <?php if (!empty($pending_user_notes)): ?>
                            <?php foreach ($pending_user_notes as $note): ?>
                                <li class="item-row">
                                    <div class="item-user" title="<?php echo htmlspecialchars($note['username_at_submission']); ?>"><?php echo htmlspecialchars($note['username_at_submission']); ?></div>
                                    <div class="item-details" title="AppID: <?php echo $note['appid']; ?> - <?php echo htmlspecialchars($note['ach_display_name_at_submission']); ?>">
                                        <?php echo htmlspecialchars($note['ach_display_name_at_submission']); ?>
                                    </div>
                                    <div class="item-checkbox-container">
                                        <input type="checkbox" name="selected_ids[]" value="<?php echo $note['id']; ?>" class="item-checkbox">
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="empty-panel-message">No user notes pending review.</li>
                        <?php endif; ?>
                    </ul>
                    <?php if (!empty($pending_user_notes)): ?>
                    <button type="submit" class="panel-button">Process Selected Notes</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <?php include 'footer.php'; ?>
    <script src="javascript/bar-menu.js"></script>
</body>
</html>