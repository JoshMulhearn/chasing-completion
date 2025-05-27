<?php
    session_start();
    // ini_set('display_errors', 1); // Keep for debugging if needed
    // error_reporting(E_ALL);

    if(!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']){
        header("Location: Error.php?code=not_logged_in_profile"); // More specific error
        exit();
    }
    if (!isset($_SESSION['userData'])) {
        header("Location: Error.php?code=session_data_missing_profile"); // More specific error
        exit();
    }

    // User details
    $username = isset($_SESSION['userData']['name']) ? htmlspecialchars($_SESSION['userData']['name']) : 'Guest';
    $avatar = isset($_SESSION['userData']['avatar']) ? htmlspecialchars($_SESSION['userData']['avatar']) : 'Images/default_avatar.png';

    // Overall stats from dashboard's session cache
    $games_completed_100_count_overall = 0;
    $achievements_earned_display = 0;
    $completed_100_showcase_list = []; // Initialize as empty array

    if (isset($_SESSION['dashboard_stats'])) {
        $games_completed_100_count_overall = $_SESSION['dashboard_stats']['games_100_count'] ?? 0;
        $achievements_earned_display = $_SESSION['dashboard_stats']['total_ach_earned'] ?? 0;
        // THIS IS THE KEY: Read the pre-compiled list for the showcase
        $completed_100_showcase_list = $_SESSION['dashboard_stats']['completed_100_showcase_games'] ?? [];
        error_log("PROFILE.PHP: Loaded showcase list from session. Count: " . count($completed_100_showcase_list));
    } else {
        error_log("PROFILE.PHP: \$_SESSION['dashboard_stats'] not found. Stats will be default/empty. User should visit dashboard first.");
        // Optionally, you could add a message on the profile page if dashboard_stats isn't set,
        // guiding the user to visit the dashboard to populate these stats.
    }


    // --- "Steam User For" ---
    $timecreated = $_SESSION['userData']['timecreated'] ?? null;
    $member_for_string = "N/A";
    if ($timecreated) {
        try {
            $creation_date = new DateTime("@{$timecreated}"); $now = new DateTime(); $interval = $now->diff($creation_date);
            $parts = [];
            if ($interval->y > 0) $parts[] = $interval->y . " year" . ($interval->y > 1 ? "s" : "");
            if ($interval->m > 0) $parts[] = $interval->m . " month" . ($interval->m > 1 ? "s" : "");
            if ($interval->d > 0 && ($interval->y == 0 && $interval->m == 0)) $parts[] = $interval->d . " day" . ($interval->d > 1 ? "s" : "");
            elseif ($interval->y == 0 && $interval->m == 0 && $interval->d == 0) $parts[] = "Less than a day"; // Edge case for very new accounts
            if (empty($parts) && $timecreated) $parts[] = "Joined recently"; // Fallback if interval is 0 for all parts but timecreated exists
            else if (empty($parts)) $parts[] = "N/A"; // If timecreated was null and somehow parts remained empty
            $member_for_string = implode(', ', $parts);
        } catch (Exception $e) { $member_for_string = "Error"; error_log("PROFILE.PHP: DateTime error for member_for: " . $e->getMessage());}
    }

    // --- "Total Hours on Record" ---
    $total_playtime_minutes = 0;
    $all_owned_games_from_session = $_SESSION['userData']['owned_games']['games'] ?? [];
    foreach ($all_owned_games_from_session as $ownedGame) {
        if (isset($ownedGame['playtime_forever']) && is_numeric($ownedGame['playtime_forever'])) {
            $total_playtime_minutes += (int)$ownedGame['playtime_forever'];
        }
    }
    $total_playtime_hours_display = round($total_playtime_minutes / 60, 1);

    // --- Recently Played Games ---
    $recently_played_games = [];
    if (!empty($all_owned_games_from_session)) {
        $games_with_recent_playtime = array_filter($all_owned_games_from_session, function ($game) {
            return isset($game['playtime_2weeks']) && $game['playtime_2weeks'] > 0;
        });
        uasort($games_with_recent_playtime, function ($a, $b) {
            return ($b['playtime_2weeks'] ?? 0) <=> ($a['playtime_2weeks'] ?? 0);
        });
        $recently_played_games = array_slice($games_with_recent_playtime, 0, 6); // Show up to 6
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
    <link rel="stylesheet" href="css/style.css"> <!-- Make sure you have styles for .game-covers-grid and .game-cover-item -->
    <!---->
    <title><?php echo $username; ?>'s Profile - Chasing Completion</title>

</head>
<body>
    <?php include 'navbar.php'?>
    <div class="profile-page-container">

        <div class="profile-user-card">
            <img class="profile-user-avatar" src="<?php echo $avatar; ?>" alt="<?php echo $username; ?>'s Avatar">
            <div class="profile-user-details">
                <h2><?php echo $username; ?></h2>
                <p>Steam User For: <?php echo $member_for_string; ?></p>
                <p>Hours on Record: <?php echo $total_playtime_hours_display; ?> hrs</p>
                <p>Games 100% Completed: <?php echo $games_completed_100_count_overall; ?></p>
                <p>Total Achievements Earned: <?php echo $achievements_earned_display; ?></p>
            </div>
        </div>
        <div class="profile-section">
            <h3>100% Completed Games Showcase</h3>
            <?php if (!empty($completed_100_showcase_list)): ?>
                <div class="game-covers-grid">
                    <?php foreach($completed_100_showcase_list as $game): // $game is an array like ['appid' => ..., 'name' => ...] ?>
                        <div class="game-cover-item">
                             <a href="game-achievements.php?appid=<?php echo htmlspecialchars($game['appid']); ?>" title="View achievements for <?php echo htmlspecialchars($game['name']); ?>">
                                <img src="https://cdn.akamai.steamstatic.com/steam/apps/<?php echo htmlspecialchars($game['appid']); ?>/library_600x900.jpg"
                                     alt="<?php echo htmlspecialchars($game['name']); ?>"
                                     onerror="this.onerror=null; this.src='Images/no-game-cover.png';">

                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif (isset($_SESSION['dashboard_stats'])): // dashboard_stats is set, but the showcase list is empty ?>
                <p class="no-data-message">You haven't 100% completed any games with achievements yet!</p>
            <?php else: // dashboard_stats isn't even set ?>
                <p class="no-data-message">Game completion data is still being processed. Please visit your <a href="dashboard.php">Dashboard</a> or check back shortly.</p>
                <p class="no-data-message"><small>(This list updates when your main dashboard stats are refreshed.)</small></p>
            <?php endif; ?>
        </div>
        <div class="profile-section">
            <h3>Recently Played</h3>
            <?php if (!empty($recently_played_games)): ?>
                <div class="game-covers-grid">
                    <?php foreach($recently_played_games as $game): ?>
                        <div class="game-cover-item">
                            <a href="game-achievements.php?appid=<?php echo $game['appid']; ?>" title="View achievements for <?php echo htmlspecialchars($game['name'] ?? ''); ?>">
                                <img src="https://cdn.akamai.steamstatic.com/steam/apps/<?php echo $game['appid']; ?>/library_600x900.jpg"
                                     alt="<?php echo htmlspecialchars($game['name'] ?? 'Game Cover'); ?>"
                                     onerror="this.onerror=null; this.src='Images/no-game-cover.png';">

                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="no-data-message">No recently played games found in the last 2 weeks.</p>
            <?php endif; ?>
        </div>



    </div>

    <?php include 'footer.php'?>
    <script src="javascript/bar-menu.js"></script>
</body>
</html>