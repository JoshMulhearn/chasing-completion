<?php
    session_start();
    if(!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']){
        header("Location: Error.php?code=not_logged_in"); // Added error code
        exit();
    }

    // Ensure userData exists
    if (!isset($_SESSION['userData'])) {
        error_log("Error in profile.php: \$_SESSION['userData'] is not set.");
        header("Location: Error.php?code=session_data_missing");
        exit();
    }
    
    // Variables from session (basic user info)
    $username = isset($_SESSION['userData']['name']) ? htmlspecialchars($_SESSION['userData']['name']) : 'Guest'; 
    $avatar = isset($_SESSION['userData']['avatar']) ? htmlspecialchars($_SESSION['userData']['avatar']) : 'Images/default_avatar.png';

    // Get the cached stats from the session, populated by dashboard.php or get_dashboard_data.php
    $total_playtime_display = $_SESSION['dashboard_stats']['total_playtime'] ?? 0; 
    $games_completed_count_display = $_SESSION['dashboard_stats']['games_100_count'] ?? 0;
    $achievements_earned_display = $_SESSION['dashboard_stats']['total_ach_earned'] ?? 0;

    $timecreated = $_SESSION['userData']['timecreated'] ?? null; // Assuming you store this during login
    $member_for_string = "N/A";
    if ($timecreated) {
        $creation_date = new DateTime("@$timecreated"); // Create DateTime from UNIX timestamp
        $now = new DateTime();
        $interval = $now->diff($creation_date);
        
        $years = $interval->y;
        $months = $interval->m;
        $days = $interval->d;

        $parts = [];
        if ($years > 0) $parts[] = $years . " year" . ($years > 1 ? "s" : "");
        if ($months > 0) $parts[] = $months . " month" . ($months > 1 ? "s" : "");
        if ($days > 0 && $years == 0) $parts[] = $days . " day" . ($days > 1 ? "s" : ""); // Show days if less than a year
        if (empty($parts)) $parts[] = "Less than a day";
        
        $member_for_string = implode(', ', $parts);
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
    <link rel="stylesheet" href="css/style.css"> {/* Ensure your .user-card styles are here */}
    <!---->
    <title><?php echo $username; ?>'s Profile - Chasing Completion</title>
</head>
<body>
    <?php include 'navbar.php'?>
    <div class="page-container"> 
        
        <h1 class="page-title"><?php echo $username; ?>'s Profile</h1>

        <div class="user-card">
            <img class="user-avatar" src="<?php echo $avatar; ?>" alt="<?php echo $username; ?>'s Avatar">
            <div class="user-details">
                <h2><?php echo $username; ?></h2>
                <p>Steam User For: <?php echo $member_for_string; ?></p>
                <p>Hours on Record: <?php echo $total_playtime_display; ?> hrs</p>
                <p>Games 100% Completed: <?php echo $games_completed_count_display; ?></p>
                <p>Total Achievements Earned: <?php echo $achievements_earned_display; ?></p> 
                
            </div>
        </div>

        {/* Add other profile sections here, e.g., recent activity, favorite games, etc. */}

    </div>

    <?php include 'footer.php'?>
</body>
<script src="javascript/bar-menu.js"></script>
</html>