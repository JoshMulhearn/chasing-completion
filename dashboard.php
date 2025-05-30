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

    // The $pdo variable will be defined by db_connect.php
    // If db_connect.php dies on error, this script won't continue.
    // If it doesn't die, $pdo should be a PDO object.
    require_once __DIR__ . '/db_connect.php';


    // V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V
    // FUNCTION DEFINITIONS (Now using PDO)
    // V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V V

    function getLeaderboardData($pdo_connection, $limit = 6) {
        $leaderboard = [];
        if (!$pdo_connection || !($pdo_connection instanceof PDO)) {
            error_log("getLeaderboardData: PDO connection not valid or not provided.");
            return $leaderboard;
        }
        $sql = "SELECT steam_id, username, avatar_url, games_completed_100_percent
                FROM users
                ORDER BY games_completed_100_percent DESC, total_achievements_earned DESC
                LIMIT :limitVal"; // Use a named placeholder
        try {
            $stmt = $pdo_connection->prepare($sql);
            $stmt->bindParam(':limitVal', $limit, PDO::PARAM_INT); // Bind the integer limit
            if ($stmt->execute()) {
                $rank = 1;
                // PDO::FETCH_ASSOC is default if set in $options in db_connect.php
                while ($row = $stmt->fetch()) {
                    $row['rank'] = $rank++; // Add rank to the row
                    $leaderboard[] = $row;
                }
            } else {
                 // With PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, execute() throws an exception on failure.
                 // This else block might not be reached for query execution errors.
                 error_log("getLeaderboardData Error: Failed to execute statement. Info: " . implode(", ", $stmt->errorInfo()));
            }
        } catch (PDOException $e) {
            error_log("getLeaderboardData PDOException: " . $e->getMessage());
        }
        return $leaderboard;
    }

    // The following functions' primary execution will be in fetch_dashboard_stats.php.
    // They are included here because they were originally part of dashboard.php.

    function getAndCacheGameSchema($appId, $api_key_param, $cacheDir = 'cache/', $cacheDuration = 86400) {
        // This function does not use the database, no changes needed for PDO conversion.
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

    function calculateUserStats($user_steamID64_param, $api_key_param, &$out_games_100_count, &$out_total_ach_earned_count, &$out_completed_100_games_list) {
        // This function does not use the database, no changes needed for PDO conversion.
        $out_games_100_count = 0;
        $out_total_ach_earned_count = 0;
        $out_completed_100_games_list = [];

        if (!$user_steamID64_param || !$api_key_param || !isset($_SESSION['userData']['owned_games']['games']) || !is_array($_SESSION['userData']['owned_games']['games'])) {
            error_log("dashboard.php - calculateUserStats: Missing required data for User: {$user_steamID64_param}");
            return;
        }
        error_log("dashboard.php - calculateUserStats: Starting for User: {$user_steamID64_param}. Games: ".count($_SESSION['userData']['owned_games']['games']));

        foreach ($_SESSION['userData']['owned_games']['games'] as $ownedGame) {
            if (!isset($ownedGame['appid'])) continue;
            $appId = $ownedGame['appid'];
            $gameName = $ownedGame['name'] ?? 'Unknown Game';
            $gameSchema = getAndCacheGameSchema($appId, $api_key_param);
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
                if (count($out_completed_100_games_list) < 12) {
                     $out_completed_100_games_list[] = ['appid' => $appId, 'name' => $gameName];
                }
            }
        }
         error_log("dashboard.php - calculateUserStats: Finished for User: {$user_steamID64_param}. 100% Games: {$out_games_100_count}, Total Ach: {$out_total_ach_earned_count}");
    }

    function updateUserStatsInDB($steam_id_param, $username_param, $avatar_url_param, $games_100, $total_ach_earned, $pdo_connection) {
        if (!$pdo_connection || !($pdo_connection instanceof PDO)) {
             error_log("dashboard.php - updateUserStatsInDB: PDO connection not valid or not provided. User: {$steam_id_param}");
             return false;
        }
        $sql = "INSERT INTO users (steam_id, username, avatar_url, games_completed_100_percent, total_achievements_earned)
                VALUES (:steam_id, :username, :avatar_url, :games_100, :total_ach_earned)
                ON DUPLICATE KEY UPDATE
                username = VALUES(username),
                avatar_url = VALUES(avatar_url),
                games_completed_100_percent = VALUES(games_completed_100_percent),
                total_achievements_earned = VALUES(total_achievements_earned),
                last_updated = CURRENT_TIMESTAMP";
        try {
            $stmt = $pdo_connection->prepare($sql);
            // Bind parameters directly in execute array for cleaner code
            $params = [
                ':steam_id' => $steam_id_param,
                ':username' => $username_param,
                ':avatar_url' => $avatar_url_param,
                ':games_100' => $games_100,
                ':total_ach_earned' => $total_ach_earned
            ];
            if ($stmt->execute($params)) {
                return true;
            } else {
                // With PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, execute() throws an exception on failure.
                error_log("dashboard.php - DB Update Error: Failed to execute statement for user {$steam_id_param}. Info: " . implode(", ", $stmt->errorInfo()));
                return false;
            }
        } catch (PDOException $e) {
            error_log("dashboard.php - DB Update PDOException for user {$steam_id_param}: " . $e->getMessage());
            return false;
        }
    }
    // ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^
    // END OF FUNCTION DEFINITIONS
    // ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^ ^


    // Get pre-existing stats from session for initial display if available and fresh
    $STATS_CACHE_DURATION = 3600 * 1; // 1 hour
    $initial_games_completed = "Loading...";
    $initial_achievements_earned = "Loading...";
    $needs_ajax_update = true;

    if (isset($_SESSION['dashboard_stats']['games_100_count'],
              $_SESSION['dashboard_stats']['total_ach_earned'],
              $_SESSION['dashboard_stats']['stats_last_updated']) &&
        (time() - $_SESSION['dashboard_stats']['stats_last_updated'] < $STATS_CACHE_DURATION)) {

        $initial_games_completed = $_SESSION['dashboard_stats']['games_100_count'];
        $initial_achievements_earned = $_SESSION['dashboard_stats']['total_ach_earned'];
        error_log("Dashboard: Displaying initial stats from FRESH SESSION cache for user {$steamID64}. AJAX will still check/update.");
    } else {
        error_log("Dashboard: Initial stats from session are STALE or MISSING for user {$steamID64}. Will use AJAX to fetch/calculate.");
    }


    //fetch Leaderboard Data
    $leaderboardEntries = [];
    // $pdo is defined by db_connect.php. Check if it's a valid PDO object.
    if (isset($pdo) && $pdo instanceof PDO) {
        $leaderboardEntries = getLeaderboardData($pdo, 6); // Pass the $pdo object
        error_log("Dashboard: Attempted to fetch " . count($leaderboardEntries) . " leaderboard entries for initial load using PDO.");
    } else {
        error_log("Dashboard: Not fetching leaderboard for initial load. PDO connection object (\$pdo) is not available or not valid.");
    }

    // It's good practice to null the PDO object when done with it for this script,
    // especially if not at the very end. PHP would garbage collect it at script end anyway.
    // If fetch_dashboard_stats.php also needs $pdo, it should establish its own via db_connect.php.
    if (isset($pdo)) {
        $pdo = null;
        // error_log("Dashboard: PDO connection nulled after initial page load operations.");
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
                    <img class="user-avatar" src="<?php echo $avatar; ?>" alt="<?php echo $username; ?>'s Avatar">
                    <div class="user-details">
                        <h2>Logged in as <?php echo $username; ?></h2>
                        <p>Games Completed: <span id="gamesCompletedCount"><?php echo htmlspecialchars($initial_games_completed); ?></span></p>
                        <p>Achievements Earned: <span id="achievementsEarnedCount"><?php echo htmlspecialchars($initial_achievements_earned); ?></span></p>
                        <small id="statsStatus"></small>
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

            if (statsStatusElem) statsStatusElem.textContent = 'Checking/refreshing stats...';

            fetch('fetch_dashboard_stats.php')
                .then(response => {
                    if (!response.ok) {
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
                        }, 5000);
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