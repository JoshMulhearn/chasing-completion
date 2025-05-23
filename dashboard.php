<?php
    session_start();

    if(!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']){
        header("Location: Error.php?code=not_logged_in");
        exit(); 
    }

    if (!isset($_SESSION['userData'])) {
        error_log("Error in games.php: \$_SESSION['userData'] is not set.");
        header("Location: Error.php?code=session_data_missing");
        exit();
    }

    $username = $_SESSION['userData']['name']; 
    $avatar = $_SESSION['userData']['avatar']; 
    
    $current_username = isset($_SESSION['userData']['name']) ? htmlspecialchars($_SESSION['userData']['name']) : 'Guest'; 
    $current_avatar_url = isset($_SESSION['userData']['avatar']) ? htmlspecialchars($_SESSION['userData']['avatar']) : 'Images/default_avatar.png';
    $current_steamID64 = isset($_SESSION['userData']['steam_id']) ? $_SESSION['userData']['steam_id'] : null;

    require_once __DIR__ . '/steam-api-key.php';//api key
    $steam_api_key = defined('steam_api_key') ? steam_api_key : null;
    
    require_once __DIR__ . '/db_connect.php';//database connection

    
    function getAndCacheGameSchema($appId, $current_steam_api_key, $cacheDir = 'cache/', $cacheDuration = 86400) { 
        if (!$current_steam_api_key) return [];
        $cacheFile = rtrim($cacheDir, '/') . '/schema_' . $appId . '.json';
        if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheDuration) && is_readable($cacheFile)) {
            $cachedContent = @file_get_contents($cacheFile);
            if ($cachedContent !== false) {
                $cachedData = @json_decode($cachedContent, true);
                if (is_array($cachedData)) { return $cachedData; }
            }
        }
        $schema_url = "https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/?key={$current_steam_api_key}&appid={$appId}&l=english";
        $schema_raw_response = @file_get_contents($schema_url);
        if ($schema_raw_response === false) { return []; }

        $schema_response = @json_decode($schema_raw_response, true);
        if ($schema_response === null || !isset($schema_response['game']['availableGameStats']['achievements'])) {
            @file_put_contents($cacheFile, json_encode([]));
            return [];
        }
        $gameSchemaAchievements = [];
        foreach ($schema_response['game']['availableGameStats']['achievements'] as $schemaAch) {
            if (isset($schemaAch['name'])) { $gameSchemaAchievements[$schemaAch['name']] = $schemaAch; }
        }
        @file_put_contents($cacheFile, json_encode($gameSchemaAchievements));
        return $gameSchemaAchievements;
    }

    function calculateUserStats($user_steamID64, $api_key, &$out_games_100_count, &$out_total_ach_earned_count) {
        $out_games_100_count = 0;
        $out_total_ach_earned_count = 0;

        if (!$user_steamID64 || !$api_key || !isset($_SESSION['userData']['owned_games']['games']) || !is_array($_SESSION['userData']['owned_games']['games'])) {
            error_log("calculateUserStats: Missing required data (SteamID, API Key, or Owned Games).");
            return;
        }

        foreach ($_SESSION['userData']['owned_games']['games'] as $ownedGame) { //calculate user achievements total for each game. this is what takes a while to load
            if (!isset($ownedGame['appid'])) continue;
            $appId = $ownedGame['appid'];
            $gameSchema = getAndCacheGameSchema($appId, $api_key);
            $totalAchievementsInSchema = count($gameSchema);
            $achievedCountForThisGame = 0;

            if ($totalAchievementsInSchema > 0) {
                $playerAchievements_url = "https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/?key={$api_key}&steamid={$user_steamID64}&appid={$appId}&l=english";
                $player_raw_response = @file_get_contents($playerAchievements_url);
                if ($player_raw_response !== false) {
                    $player_ach_response = json_decode($player_raw_response, true);
                    if (isset($player_ach_response['playerstats']['success']) && $player_ach_response['playerstats']['success'] === true && isset($player_ach_response['playerstats']['achievements'])) {
                        foreach ($player_ach_response['playerstats']['achievements'] as $playerAch) {
                            if (isset($playerAch['achieved']) && $playerAch['achieved'] == 1) {
                                $achievedCountForThisGame++;
                            }
                        }
                    }
                } 
                usleep(50000); //50ms delay
            }
            $out_total_ach_earned_count += $achievedCountForThisGame;
            if ($totalAchievementsInSchema > 0 && $achievedCountForThisGame === $totalAchievementsInSchema) {
                $out_games_100_count++;
            }
        }
    }

    function updateUserStatsInDB($steam_id, $username, $avatar_url, $games_100, $total_ach_earned, $link) {
        if (!$link || $link->connect_error) {
             error_log("updateUserStatsInDB: Database connection error.");
             return false;
        }
        //sql to update the users info to ensure games completed number updates if it goes up
        $sql = "INSERT INTO users (steam_id, username, avatar_url, games_completed_100_percent, total_achievements_earned) 
                VALUES (?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                username = VALUES(username), 
                avatar_url = VALUES(avatar_url), 
                games_completed_100_percent = VALUES(games_completed_100_percent), 
                total_achievements_earned = VALUES(total_achievements_earned), 
                last_updated = CURRENT_TIMESTAMP";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "sssis", $steam_id, $username, $avatar_url, $games_100, $total_ach_earned);
            if (mysqli_stmt_execute($stmt)) {
                error_log("DB Update: Successfully updated/inserted stats for user {$steam_id}.");
                mysqli_stmt_close($stmt);
                return true;
            } else {
                error_log("DB Update Error: Failed to execute statement for user {$steam_id}. " . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        } else {
            error_log("DB Update Error: Failed to prepare statement. " . mysqli_error($link));
        }
        return false;
    }

    function getLeaderboardData($link, $limit = 6) {
        $leaderboard = [];
        if (!$link || $link->connect_error) {
            error_log("getLeaderboardData: Database connection error.");
            return $leaderboard;
        }
        //order by games_completed_100_percent first, then by total_achievements_earned for tie-breaking
        $sql = "SELECT steam_id, username, avatar_url, games_completed_100_percent 
                FROM users 
                ORDER BY games_completed_100_percent DESC, total_achievements_earned DESC 
                LIMIT ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $limit);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                $rank = 1;
                while ($row = mysqli_fetch_assoc($result)) {
                    $row['rank'] = $rank++;
                    $leaderboard[] = $row;
                }
                mysqli_free_result($result);
            } else {
                 error_log("getLeaderboardData Error: Failed to execute statement. " . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        } else {
            error_log("getLeaderboardData Error: Failed to prepare statement. " . mysqli_error($link));
        }
        return $leaderboard;
    }

    //stats logic
    $games_completed_count_display = 0;
    $achievements_earned_display = 0;
    $STATS_CACHE_DURATION = 3600 * 6; //cache user stats in session for 6 hours

    if (isset($_SESSION['dashboard_stats']['games_100_count'], $_SESSION['dashboard_stats']['total_ach_earned'], $_SESSION['dashboard_stats']['stats_last_updated']) &&
        (time() - $_SESSION['dashboard_stats']['stats_last_updated'] < $STATS_CACHE_DURATION)) {
        
        $games_completed_count_display = $_SESSION['dashboard_stats']['games_100_count'];
        $achievements_earned_display = $_SESSION['dashboard_stats']['total_ach_earned'];
        error_log("Dashboard: Loaded user stats from SESSION cache for user {$current_steamID64}.");
    } else {
        error_log("Dashboard: SESSION cache for user stats expired or not set for user {$current_steamID64}. Recalculating...");
        calculateUserStats($current_steamID64, $steam_api_key, $games_completed_count_display, $achievements_earned_display);
        
        if (!isset($_SESSION['dashboard_stats'])) { $_SESSION['dashboard_stats'] = []; }
        $_SESSION['dashboard_stats']['games_100_count'] = $games_completed_count_display;
        $_SESSION['dashboard_stats']['total_ach_earned'] = $achievements_earned_display;
        $_SESSION['dashboard_stats']['stats_last_updated'] = time();
        error_log("Dashboard: Recalculated and SESSION cached stats for user {$current_steamID64}. Games 100%: {$games_completed_count_display}, Total Ach: {$achievements_earned_display}.");

        //update database after recalculating stats
        if ($db_link && $current_steamID64) {
            updateUserStatsInDB($current_steamID64, $current_username, $current_avatar_url, $games_completed_count_display, $achievements_earned_display, $db_link);
        }
    }

    //fetch Leaderboard Data
    $leaderboardEntries = [];
    if ($db_link) {
        $leaderboardEntries = getLeaderboardData($db_link, 6); // Get top 6 for display
    } else {
        error_log("Dashboard: Not fetching leaderboard due to DB connection issue.");
    }
    
    //close DB connection if it was opened
    if ($db_link) {
        mysqli_close($db_link);
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
    <title>Chasing Completion - Dashboard</title>
</head>
<body>
    <?php include 'navbar.php'?>

    <div class="dashboard-page-container">
        <div class="dashboard-main-content">
            <div class="dashboard-left-column">

                <div class="user-card">
                    <img class="user-avatar" src="<?php echo $current_avatar_url; ?>" alt="<?php echo $current_username; ?>'s Avatar">
                    <div class="user-details">
                        <h2>Logged in as <?php echo $current_username; ?></h2>
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