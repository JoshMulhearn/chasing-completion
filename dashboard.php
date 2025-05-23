<?php
    // AT THE VERY TOP!
    ini_set('display_errors', 1); // Show errors for debugging
    error_reporting(E_ALL);    // Report all errors

    session_start();

    if(!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']){
        header("Location: Error.php?code=not_logged_in");
        exit(); 
    }

    if (!isset($_SESSION['userData'])) {
        error_log("Error in dashboard.php: \$_SESSION['userData'] is not set.");
        header("Location: Error.php?code=session_data_missing");
        exit();
    }

    // Use these consistently
    $username = isset($_SESSION['userData']['name']) ? htmlspecialchars($_SESSION['userData']['name']) : 'Guest'; 
    $avatar = isset($_SESSION['userData']['avatar']) ? htmlspecialchars($_SESSION['userData']['avatar']) : 'Images/default_avatar.png'; 
    $steamID64 = isset($_SESSION['userData']['steam_id']) ? $_SESSION['userData']['steam_id'] : null;

    require_once __DIR__ . '/steam-api-key.php';//api key
    $steam_api_key = defined('steam_api_key') ? steam_api_key : null;
    
    // The $db_link variable will be defined (or set to false on failure) by db_connect.php
    require_once __DIR__ . '/db_connect.php';//database connection

    
    // Function definitions (make sure $steam_api_key is used correctly inside)
    function getAndCacheGameSchema($appId, $api_key_param, $cacheDir = 'cache/', $cacheDuration = 86400) { // Renamed param for clarity
        if (!$api_key_param) {
            error_log("getAndCacheGameSchema: API key not provided for AppID {$appId}.");
            return [];
        }
        $cacheFile = rtrim($cacheDir, '/') . '/schema_' . $appId . '.json';
        if (!is_dir($cacheDir)) { 
            if(!@mkdir($cacheDir, 0775, true)) {
                error_log("getAndCacheGameSchema: FAILED TO CREATE CACHE DIR {$cacheDir}");
            }
        }
        if (!is_writable($cacheDir)) { // Check after attempting to create
             error_log("getAndCacheGameSchema: CACHE DIR NOT WRITABLE {$cacheDir}");
        }


        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheDuration) && is_readable($cacheFile)) {
            $cachedContent = @file_get_contents($cacheFile);
            if ($cachedContent !== false) {
                $cachedData = @json_decode($cachedContent, true);
                if (is_array($cachedData)) { return $cachedData; }
                else { error_log("getAndCacheGameSchema: Corrupt JSON for AppID {$appId} in {$cacheFile}");}
            } else { error_log("getAndCacheGameSchema: Failed to read cache for AppID {$appId} from {$cacheFile}");}
        }
        // Use the passed API key parameter
        $schema_url = "https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/?key={$api_key_param}&appid={$appId}&l=english";
        $context = stream_context_create(['http' => ['timeout' => 10]]); // Add timeout
        $schema_raw_response = @file_get_contents($schema_url, false, $context);
        if ($schema_raw_response === false) { 
            error_log("getAndCacheGameSchema: API call failed for AppID {$appId}. Error: ".(error_get_last()['message'] ?? 'Unknown'));
            return []; 
        }

        $schema_response = @json_decode($schema_raw_response, true);
        if ($schema_response === null || !isset($schema_response['game']['availableGameStats']['achievements'])) {
            error_log("getAndCacheGameSchema: Invalid response or no achievements for AppID {$appId}. Caching empty.");
            @file_put_contents($cacheFile, json_encode([]));
            return [];
        }
        $gameSchemaAchievements = [];
        foreach ($schema_response['game']['availableGameStats']['achievements'] as $schemaAch) {
            if (isset($schemaAch['name'])) { $gameSchemaAchievements[$schemaAch['name']] = $schemaAch; }
        }
        if (!@file_put_contents($cacheFile, json_encode($gameSchemaAchievements))) {
             error_log("getAndCacheGameSchema: FAILED TO WRITE cache for AppID {$appId} to {$cacheFile}");
        }
        return $gameSchemaAchievements;
    }

    function calculateUserStats($user_steamID64_param, $api_key_param, &$out_games_100_count, &$out_total_ach_earned_count) {
        $out_games_100_count = 0;
        $out_total_ach_earned_count = 0;

        if (!$user_steamID64_param || !$api_key_param || !isset($_SESSION['userData']['owned_games']['games']) || !is_array($_SESSION['userData']['owned_games']['games'])) {
            error_log("calculateUserStats: Missing required data (SteamID, API Key, or Owned Games). User: {$user_steamID64_param}");
            return;
        }
        error_log("calculateUserStats: Starting for User: {$user_steamID64_param}. Games in session: ".count($_SESSION['userData']['owned_games']['games']));

        foreach ($_SESSION['userData']['owned_games']['games'] as $ownedGame) {
            if (!isset($ownedGame['appid'])) continue;
            $appId = $ownedGame['appid'];
            // Pass the correct API key to getAndCacheGameSchema
            $gameSchema = getAndCacheGameSchema($appId, $api_key_param); 
            $totalAchievementsInSchema = count($gameSchema);
            $achievedCountForThisGame = 0;

            if ($totalAchievementsInSchema > 0) {
                $playerAchievements_url = "https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/?key={$api_key_param}&steamid={$user_steamID64_param}&appid={$appId}&l=english";
                $context = stream_context_create(['http' => ['timeout' => 10]]); // Add timeout
                $player_raw_response = @file_get_contents($playerAchievements_url, false, $context);
                if ($player_raw_response !== false) {
                    $player_ach_response = json_decode($player_raw_response, true);
                    if (isset($player_ach_response['playerstats']['success']) && $player_ach_response['playerstats']['success'] === true && isset($player_ach_response['playerstats']['achievements'])) {
                        foreach ($player_ach_response['playerstats']['achievements'] as $playerAch) {
                            if (isset($playerAch['achieved']) && $playerAch['achieved'] == 1) {
                                $achievedCountForThisGame++;
                            }
                        }
                    }
                } else { error_log("calculateUserStats: Failed player ach fetch for AppID {$appId}, User {$user_steamID64_param}. Error: ".(error_get_last()['message'] ?? 'Unknown'));}
                usleep(50000);
            }
            $out_total_ach_earned_count += $achievedCountForThisGame;
            if ($totalAchievementsInSchema > 0 && $achievedCountForThisGame === $totalAchievementsInSchema) {
                $out_games_100_count++;
            }
        }
         error_log("calculateUserStats: Finished for User: {$user_steamID64_param}. 100% Games: {$out_games_100_count}, Total Ach: {$out_total_ach_earned_count}");
    }

    function updateUserStatsInDB($steam_id_param, $username_param, $avatar_url_param, $games_100, $total_ach_earned, $db_connection) { // Renamed $link
        if (!$db_connection || (is_object($db_connection) && $db_connection->connect_error)) { // Check for object and connect_error
             error_log("updateUserStatsInDB: Database connection error or link not valid. User: {$steam_id_param}");
             return false;
        }
        $sql = "INSERT INTO users (steam_id, username, avatar_url, games_completed_100_percent, total_achievements_earned) 
                VALUES (?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                username = VALUES(username), 
                avatar_url = VALUES(avatar_url), 
                games_completed_100_percent = VALUES(games_completed_100_percent), 
                total_achievements_earned = VALUES(total_achievements_earned), 
                last_updated = CURRENT_TIMESTAMP";
        
        if ($stmt = mysqli_prepare($db_connection, $sql)) {
            mysqli_stmt_bind_param($stmt, "sssis", $steam_id_param, $username_param, $avatar_url_param, $games_100, $total_ach_earned);
            if (mysqli_stmt_execute($stmt)) {
                // error_log("DB Update: Successfully updated/inserted stats for user {$steam_id_param}."); // Less verbose for successful
                mysqli_stmt_close($stmt);
                return true;
            } else {
                error_log("DB Update Error: Failed to execute statement for user {$steam_id_param}. " . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        } else {
            error_log("DB Update Error: Failed to prepare statement for user {$steam_id_param}. " . mysqli_error($db_connection));
        }
        return false;
    }

    function getLeaderboardData($db_connection, $limit = 6) { // Renamed $link
        $leaderboard = [];
        if (!$db_connection || (is_object($db_connection) && $db_connection->connect_error)) { // Check for object and connect_error
            error_log("getLeaderboardData: Database connection error or link not valid.");
            return $leaderboard;
        }
        $sql = "SELECT steam_id, username, avatar_url, games_completed_100_percent 
                FROM users 
                ORDER BY games_completed_100_percent DESC, total_achievements_earned DESC 
                LIMIT ?";
        if ($stmt = mysqli_prepare($db_connection, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $limit);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                $rank = 1;
                while ($row = mysqli_fetch_assoc($result)) {
                    $row['rank'] = $rank++; // Add rank to the row
                    $leaderboard[] = $row;
                }
                mysqli_free_result($result);
            } else {
                 error_log("getLeaderboardData Error: Failed to execute statement. " . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        } else {
            error_log("getLeaderboardData Error: Failed to prepare statement. " . mysqli_error($db_connection));
        }
        return $leaderboard;
    }

    //stats logic
    $games_completed_count_display = 0;
    $achievements_earned_display = 0;
    $STATS_CACHE_DURATION = 3600 * 1; // Cache for 1 hour for testing, increase later

    if (isset($_SESSION['dashboard_stats']['games_100_count'], $_SESSION['dashboard_stats']['total_ach_earned'], $_SESSION['dashboard_stats']['stats_last_updated']) &&
        (time() - $_SESSION['dashboard_stats']['stats_last_updated'] < $STATS_CACHE_DURATION)) {
        
        $games_completed_count_display = $_SESSION['dashboard_stats']['games_100_count'];
        $achievements_earned_display = $_SESSION['dashboard_stats']['total_ach_earned'];
        error_log("Dashboard: Loaded user stats from SESSION cache for user {$steamID64}.");
    } else {
        error_log("Dashboard: SESSION cache for user stats expired or not set for user {$steamID64}. Recalculating...");
        if ($steamID64 && $steam_api_key) { // Ensure these are set before calculation
            calculateUserStats($steamID64, $steam_api_key, $games_completed_count_display, $achievements_earned_display);
            
            if (!isset($_SESSION['dashboard_stats'])) { $_SESSION['dashboard_stats'] = []; }
            $_SESSION['dashboard_stats']['games_100_count'] = $games_completed_count_display;
            $_SESSION['dashboard_stats']['total_ach_earned'] = $achievements_earned_display;
            $_SESSION['dashboard_stats']['stats_last_updated'] = time();
            error_log("Dashboard: Recalculated and SESSION cached stats for user {$steamID64}. Games 100%: {$games_completed_count_display}, Total Ach: {$achievements_earned_display}.");

            // Update database after recalculating stats
            // Use the consistent variable names for current user's data
            if ($db_link && $steamID64) { // $db_link comes from db_connect.php
                updateUserStatsInDB($steamID64, $username, $avatar, $games_completed_count_display, $achievements_earned_display, $db_link);
            } else {
                error_log("Dashboard: DB connection not available or SteamID missing for DB update. User: {$steamID64}");
            }
        } else {
             error_log("Dashboard: Cannot recalculate stats. Missing SteamID or API Key. User: {$steamID64}");
             // Fallback to previous session or 0 if nothing available
             $games_completed_count_display = $_SESSION['dashboard_stats']['games_100_count'] ?? 0;
             $achievements_earned_display = $_SESSION['dashboard_stats']['total_ach_earned'] ?? 0;
        }
    }

    //fetch Leaderboard Data
    $leaderboardEntries = [];
    if ($db_link) { // Check if $db_link is truthy (i.e., connection succeeded)
        $leaderboardEntries = getLeaderboardData($db_link, 6);
        error_log("Dashboard: Fetched " . count($leaderboardEntries) . " leaderboard entries.");
    } else {
        error_log("Dashboard: Not fetching leaderboard. DB connection failed or $db_link is not set.");
    }
    
    //close DB connection if it was opened and is a valid resource
    if ($db_link && is_object($db_link) && get_class($db_link) === 'mysqli') {
        mysqli_close($db_link);
        // error_log("Dashboard: DB connection closed."); // Can be a bit noisy
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/style.css">
    <title>Chasing Completion - Dashboard</title>
</head>
<body>
    <?php include 'navbar.php'?>

    <div class="dashboard-page-container">
        <div class="dashboard-main-content">
            <div class="dashboard-left-column">

                <div class="user-card">
                    <img class="user-avatar" src="<?php echo $avatar; ?>" alt="<?php echo $username; ?>'s Avatar">
                    <div class="user-details">
                        <h2>Logged in as <?php echo $username; ?></h2>
                        <p>Games Completed: <?php echo $games_completed_count_display; ?></p>
                        <p>Achievements Earned: <?php echo $achievements_earned_display; ?></p>
                    </div>
                </div>

                <div class="dashboard-navigation"> 
                    <a href="games.php" class="nav-button">
                        <img src="Images/controller.png" alt="Games"> 
                        <h3>Games</h3>
                    </a>
                    <a href="profile.php" class="nav-button"> 
                        <img src="Images/profile-dash.png" alt="Profile">
                        <h3>Profile</h3>
                    </a>
                    <a href="user-stats.php" class="nav-button">
                        <img src="Images/stats-dash.png" alt="Stats"> 
                        <h3>Stats</h3>
                    </a>
                </div>

                <div class="web-info">
                    <h2>Website Info</h2>
                    <p>Website development finished!</p>
                </div>
            </div>
            

            <div class="dashboard-right-column">
                <div class="leaderboard-panel">
                    <h2>Leaderboard</h2>
                    <ul class="leaderboard-list">
                        <?php if (!empty($leaderboardEntries)): ?>
                            <?php foreach($leaderboardEntries as $entry): ?>
                            <li class="leaderboard-item">
                                <p class="leaderboard-rank">#<?php echo $entry['rank']; ?></p>
                                <img class="leaderboard-avatar-small" src="<?php echo htmlspecialchars($entry['avatar_url'] ?? 'Images/leaderboard_avatar_placeholder.png'); ?>" alt="<?php echo htmlspecialchars($entry['username']); ?>'s Avatar">
                                <div class="leaderboard-user-info">
                                    <p class="leaderboard-username"><?php echo htmlspecialchars($entry['username']); ?></p>
                                    <p class="leaderboard-metric">Games Completed: <?php echo $entry['games_completed_100_percent']; ?></p>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="leaderboard-empty">Leaderboard data is currently unavailable.</p>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <?php include 'footer.php'?>
    <script src="javascript/bar-menu.js"></script>
</body>
</html>