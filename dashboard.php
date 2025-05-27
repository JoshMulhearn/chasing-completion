<?php
    // dashboard.php

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


    // V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V
    // FUNCTION DEFINITIONS (Originally from dashboard.php)
    // V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V

    // This function is still needed by dashboard.php for its initial load.
    function getLeaderboardData($db_connection, $limit = 6) {
        $leaderboard = [];
        if (!$db_connection || (is_object($db_connection) && $db_connection->connect_error)) {
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

    // The following functions' primary execution will be in fetch_dashboard_stats.php.
    // They are included here because they were originally part of dashboard.php.
    // If you ensure fetch_dashboard_stats.php has its own copies or includes them,
    // you could technically remove them from here if dashboard.php *never* calls them directly.
    // However, for Option 1 (keeping them in dashboard.php if they were there), here they are.

    function getAndCacheGameSchema($appId, $api_key_param, $cacheDir = 'cache/', $cacheDuration = 86400) {
        if (!$api_key_param) {
            error_log("dashboard.php - getAndCacheGameSchema: API key not provided for AppID {$appId}.");
            return [];
        }
        $cacheFile = rtrim($cacheDir, '/') . '/schema_' . $appId . '.json';
        if (!is_dir($cacheDir)) {
            if(!@mkdir($cacheDir, 0775, true)) {
                error_log("dashboard.php - getAndCacheGameSchema: FAILED TO CREATE CACHE DIR {$cacheDir}");
            }
        }
        if (!is_writable($cacheDir)) {
             error_log("dashboard.php - getAndCacheGameSchema: CACHE DIR NOT WRITABLE {$cacheDir}");
        }

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheDuration) && is_readable($cacheFile)) {
            $cachedContent = @file_get_contents($cacheFile);
            if ($cachedContent !== false) {
                $cachedData = @json_decode($cachedContent, true);
                if (is_array($cachedData)) { return $cachedData; }
                else { error_log("dashboard.php - getAndCacheGameSchema: Corrupt JSON for AppID {$appId} in {$cacheFile}");}
            } else { error_log("dashboard.php - getAndCacheGameSchema: Failed to read cache for AppID {$appId} from {$cacheFile}");}
        }
        $schema_url = "https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/?key={$api_key_param}&appid={$appId}&l=english";
        $context = stream_context_create(['http' => ['timeout' => 10]]);
        $schema_raw_response = @file_get_contents($schema_url, false, $context);
        if ($schema_raw_response === false) {
            error_log("dashboard.php - getAndCacheGameSchema: API call failed for AppID {$appId}. Error: ".(error_get_last()['message'] ?? 'Unknown'));
            return [];
        }
        $schema_response = @json_decode($schema_raw_response, true);
        if ($schema_response === null || !isset($schema_response['game']['availableGameStats']['achievements'])) {
            error_log("dashboard.php - getAndCacheGameSchema: Invalid response or no achievements for AppID {$appId}. Caching empty.");
            @file_put_contents($cacheFile, json_encode([]));
            return [];
        }
        $gameSchemaAchievements = [];
        foreach ($schema_response['game']['availableGameStats']['achievements'] as $schemaAch) {
            if (isset($schemaAch['name'])) { $gameSchemaAchievements[$schemaAch['name']] = $schemaAch; }
        }
        if (!@file_put_contents($cacheFile, json_encode($gameSchemaAchievements))) {
             error_log("dashboard.php - getAndCacheGameSchema: FAILED TO WRITE cache for AppID {$appId} to {$cacheFile}");
        }
        return $gameSchemaAchievements;
    }

    // Remember the modification to include $out_completed_100_games_list
    function calculateUserStats($user_steamID64_param, $api_key_param, &$out_games_100_count, &$out_total_ach_earned_count, &$out_completed_100_games_list) {
        $out_games_100_count = 0;
        $out_total_ach_earned_count = 0;
        $out_completed_100_games_list = []; // Initialize the list

        if (!$user_steamID64_param || !$api_key_param || !isset($_SESSION['userData']['owned_games']['games']) || !is_array($_SESSION['userData']['owned_games']['games'])) {
            error_log("dashboard.php - calculateUserStats: Missing required data for User: {$user_steamID64_param}");
            return;
        }
        error_log("dashboard.php - calculateUserStats: Starting for User: {$user_steamID64_param}. Games: ".count($_SESSION['userData']['owned_games']['games']));

        foreach ($_SESSION['userData']['owned_games']['games'] as $ownedGame) {
            if (!isset($ownedGame['appid'])) continue;
            $appId = $ownedGame['appid'];
            $gameName = $ownedGame['name'] ?? 'Unknown Game';
            $gameSchema = getAndCacheGameSchema($appId, $api_key_param); // Uses the getAndCacheGameSchema defined in this file
            $totalAchievementsInSchema = count($gameSchema);
            $achievedCountForThisGame = 0;

            if ($totalAchievementsInSchema > 0) {
                $playerAchievements_url = "https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/?key={$api_key_param}&steamid={$user_steamID64_param}&appid={$appId}&l=english";
                $context = stream_context_create(['http' => ['timeout' => 10]]);
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
                } else { error_log("dashboard.php - calculateUserStats: Failed player ach fetch for AppID {$appId}, User {$user_steamID64_param}. Error: ".(error_get_last()['message'] ?? 'Unknown'));}
                usleep(50000);
            }
            $out_total_ach_earned_count += $achievedCountForThisGame;
            if ($totalAchievementsInSchema > 0 && $achievedCountForThisGame === $totalAchievementsInSchema) {
                $out_games_100_count++;
                if (count($out_completed_100_games_list) < 12) { // Example limit
                     $out_completed_100_games_list[] = ['appid' => $appId, 'name' => $gameName];
                }
            }
        }
         error_log("dashboard.php - calculateUserStats: Finished for User: {$user_steamID64_param}. 100% Games: {$out_games_100_count}, Total Ach: {$out_total_ach_earned_count}");
    }

    function updateUserStatsInDB($steam_id_param, $username_param, $avatar_url_param, $games_100, $total_ach_earned, $db_connection) {
        if (!$db_connection || (is_object($db_connection) && $db_connection->connect_error)) {
             error_log("dashboard.php - updateUserStatsInDB: Database connection error or link not valid. User: {$steam_id_param}");
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
                mysqli_stmt_close($stmt);
                return true;
            } else {
                error_log("dashboard.php - DB Update Error: Failed to execute statement for user {$steam_id_param}. " . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        } else {
            error_log("dashboard.php - DB Update Error: Failed to prepare statement for user {$steam_id_param}. " . mysqli_error($db_connection));
        }
        return false;
    }
    // ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^
    // END OF FUNCTION DEFINITIONS
    // ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^


    // Get pre-existing stats from session for initial display if available and fresh
    $STATS_CACHE_DURATION = 3600 * 1; // 1 hour
    $initial_games_completed = "Loading...";
    $initial_achievements_earned = "Loading...";
    $needs_ajax_update = true; // This variable will be passed to JavaScript

    if (isset($_SESSION['dashboard_stats']['games_100_count'],
              $_SESSION['dashboard_stats']['total_ach_earned'],
              $_SESSION['dashboard_stats']['stats_last_updated']) && // 'completed_100_showcase_games' is not strictly needed for initial display numbers
        (time() - $_SESSION['dashboard_stats']['stats_last_updated'] < $STATS_CACHE_DURATION)) {

        $initial_games_completed = $_SESSION['dashboard_stats']['games_100_count'];
        $initial_achievements_earned = $_SESSION['dashboard_stats']['total_ach_earned'];
        // $needs_ajax_update = false; // Option: If cache is fresh, you could decide not to make the AJAX call
                                    // For now, we'll always suggest an AJAX call to ensure freshness or start calculation
        error_log("Dashboard: Displaying initial stats from FRESH SESSION cache for user {$steamID64}. AJAX will still check/update.");
    } else {
        error_log("Dashboard: Initial stats from session are STALE or MISSING for user {$steamID64}. Will use AJAX to fetch/calculate.");
    }


    //fetch Leaderboard Data - this is fast and done on initial load
    $leaderboardEntries = [];
    if ($db_link) { // Check if $db_link is truthy (i.e., connection succeeded)
        $leaderboardEntries = getLeaderboardData($db_link, 6); // This call is now valid
        error_log("Dashboard: Fetched " . count($leaderboardEntries) . " leaderboard entries for initial load.");
    } else {
        error_log("Dashboard: Not fetching leaderboard for initial load. DB connection failed or $db_link is not set.");
    }

    //close DB connection if it was opened and is a valid resource
    if ($db_link && is_object($db_link) && get_class($db_link) === 'mysqli') {
        mysqli_close($db_link);
        // error_log("Dashboard: DB connection closed after leaderboard fetch.");
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
                        <p>Games Completed: <span id="gamesCompletedCount"><?php echo htmlspecialchars($initial_games_completed); ?></span></p>
                        <p>Achievements Earned: <span id="achievementsEarnedCount"><?php echo htmlspecialchars($initial_achievements_earned); ?></span></p>
                        <small id="statsStatus"></small> <!-- For loading/status messages from JS -->
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
    <script>
    document.addEventListener('DOMContentLoaded', function() {

        const gamesCompletedElem = document.getElementById('gamesCompletedCount');
        const achievementsEarnedElem = document.getElementById('achievementsEarnedCount');
        const statsStatusElem = document.getElementById('statsStatus');

        // This value comes from PHP, indicating if an AJAX call is considered necessary
        // based on server-side cache check. We'll always run it here for simplicity
        // to either fetch fresh data or trigger calculation.
        // const needsAjaxUpdate = <?php //echo json_encode($needs_ajax_update); ?>;

        // For Option 1, we can assume we always want to try and fetch/refresh.
        // If fetch_dashboard_stats.php finds fresh session cache, it will return fast.
        if (statsStatusElem) statsStatusElem.textContent = 'Checking/refreshing stats...';

        fetch('fetch_dashboard_stats.php')
            .then(response => {
                if (!response.ok) {
                    // Try to get error message from response if it's JSON, otherwise use statusText
                    return response.json().catch(() => null).then(errorData => {
                        throw new Error('Network response was not ok: ' + response.statusText + (errorData && errorData.error ? ' - ' + errorData.error : ''));
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    if (gamesCompletedElem) gamesCompletedElem.textContent = data.games_100_count;
                    if (achievementsEarnedElem) achievementsEarnedElem.textContent = data.total_ach_earned;
                    if (statsStatusElem) {
                        statsStatusElem.textContent = data.source === 'recalculated' ? 'Stats recalculated & up to date.' : 'Stats up to date (from cache).';
                    }
                    setTimeout(() => {
                        if (statsStatusElem) statsStatusElem.textContent = '';
                    }, 5000); // Clear status message after 5 seconds
                } else {
                    console.error('Error fetching stats:', data.error);
                    if (statsStatusElem) statsStatusElem.textContent = 'Error updating stats: ' + (data.error || 'Unknown error');
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                if (statsStatusElem) statsStatusElem.textContent = 'Could not update stats. Please try refreshing. (' + error.message + ')';
            });
    });
    </script>
</body>
</html>