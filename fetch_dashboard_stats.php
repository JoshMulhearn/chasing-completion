<?php
// fetch_dashboard_stats.php

ini_set('display_errors', 1); // Show errors for debugging
error_reporting(E_ALL);    // Report all errors
// Set a longer timeout for this script if necessary, as API calls can be long
set_time_limit(300); // 5 minutes, adjust as needed

session_start();

header('Content-Type: application/json'); // Important: Send JSON response

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

if (!isset($_SESSION['userData']) || !isset($_SESSION['userData']['steam_id'])) {
    echo json_encode(['success' => false, 'error' => 'User data not found in session']);
    exit();
}

$username = isset($_SESSION['userData']['name']) ? htmlspecialchars($_SESSION['userData']['name']) : 'Guest';
$avatar = isset($_SESSION['userData']['avatar']) ? htmlspecialchars($_SESSION['userData']['avatar']) : 'Images/default_avatar.png';
$steamID64 = $_SESSION['userData']['steam_id'];

require_once __DIR__ . '/steam-api-key.php';
$steam_api_key = defined('steam_api_key') ? steam_api_key : null;

if (!$steam_api_key) {
    error_log("fetch_dashboard_stats.php: Steam API key not available.");
    echo json_encode(['success' => false, 'error' => 'Server configuration error (API key).']);
    exit();
}

require_once __DIR__ . '/db_connect.php'; // $db_link defined here

// --- PASTE YOUR FUNCTION DEFINITIONS HERE or include them ---
// getAndCacheGameSchema, calculateUserStats, updateUserStatsInDB
// (Make sure they use $steam_api_key and $db_link correctly if passed as params or used as globals)

// Example: Assuming functions are defined or included
// If you moved them to an include file:
// require_once __DIR__ . '/includes/steam_functions.php';
// require_once __DIR__ . '/includes/db_functions.php';

// --- COPIED/ADAPTED FROM dashboard.php ---
// (Ensure these functions are defined above or included)
function getAndCacheGameSchema($appId, $api_key_param, $cacheDir = 'cache/', $cacheDuration = 86400) {
    // ... (your existing getAndCacheGameSchema function code)
    // Ensure error_log messages clearly state they are from this script
    // e.g., error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: ...")
    if (!$api_key_param) {
        error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: API key not provided for AppID {$appId}.");
        return [];
    }
    $cacheFile = rtrim($cacheDir, '/') . '/schema_' . $appId . '.json';
    if (!is_dir($cacheDir)) {
        if(!@mkdir($cacheDir, 0775, true)) {
            error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: FAILED TO CREATE CACHE DIR {$cacheDir}");
        }
    }
    if (!is_writable($cacheDir)) {
         error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: CACHE DIR NOT WRITABLE {$cacheDir}");
    }


    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheDuration) && is_readable($cacheFile)) {
        $cachedContent = @file_get_contents($cacheFile);
        if ($cachedContent !== false) {
            $cachedData = @json_decode($cachedContent, true);
            if (is_array($cachedData)) { return $cachedData; }
            else { error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: Corrupt JSON for AppID {$appId} in {$cacheFile}");}
        } else { error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: Failed to read cache for AppID {$appId} from {$cacheFile}");}
    }
    $schema_url = "https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/?key={$api_key_param}&appid={$appId}&l=english";
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $schema_raw_response = @file_get_contents($schema_url, false, $context);
    if ($schema_raw_response === false) {
        error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: API call failed for AppID {$appId}. Error: ".(error_get_last()['message'] ?? 'Unknown'));
        return [];
    }

    $schema_response = @json_decode($schema_raw_response, true);
    if ($schema_response === null || !isset($schema_response['game']['availableGameStats']['achievements'])) {
        error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: Invalid response or no achievements for AppID {$appId}. Caching empty.");
        @file_put_contents($cacheFile, json_encode([]));
        return [];
    }
    $gameSchemaAchievements = [];
    foreach ($schema_response['game']['availableGameStats']['achievements'] as $schemaAch) {
        if (isset($schemaAch['name'])) { $gameSchemaAchievements[$schemaAch['name']] = $schemaAch; }
    }
    if (!@file_put_contents($cacheFile, json_encode($gameSchemaAchievements))) {
         error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: FAILED TO WRITE cache for AppID {$appId} to {$cacheFile}");
    }
    return $gameSchemaAchievements;
}

// This function needs modification to also return the list of 100% completed games for profile.php showcase
function calculateUserStats($user_steamID64_param, $api_key_param, &$out_games_100_count, &$out_total_ach_earned_count, &$out_completed_100_games_list) {
    $out_games_100_count = 0;
    $out_total_ach_earned_count = 0;
    $out_completed_100_games_list = []; // Initialize the list

    if (!$user_steamID64_param || !$api_key_param || !isset($_SESSION['userData']['owned_games']['games']) || !is_array($_SESSION['userData']['owned_games']['games'])) {
        error_log("fetch_dashboard_stats.php - calculateUserStats: Missing required data for User: {$user_steamID64_param}");
        return;
    }
    error_log("fetch_dashboard_stats.php - calculateUserStats: Starting for User: {$user_steamID64_param}. Games: ".count($_SESSION['userData']['owned_games']['games']));

    foreach ($_SESSION['userData']['owned_games']['games'] as $ownedGame) {
        if (!isset($ownedGame['appid'])) continue;
        $appId = $ownedGame['appid'];
        $gameName = $ownedGame['name'] ?? 'Unknown Game'; // Get game name
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
            } else { error_log("fetch_dashboard_stats.php - calculateUserStats: Failed player ach fetch for AppID {$appId}, User {$user_steamID64_param}. Error: ".(error_get_last()['message'] ?? 'Unknown'));}
            usleep(50000); // Keep the delay to be nice to Steam API
        }
        $out_total_ach_earned_count += $achievedCountForThisGame;
        if ($totalAchievementsInSchema > 0 && $achievedCountForThisGame === $totalAchievementsInSchema) {
            $out_games_100_count++;
            // Add to the list for the showcase on profile.php
            // Limit the number of games for the showcase to avoid overly large session data
            if (count($out_completed_100_games_list) < 12) { // Example limit: 12 games
                 $out_completed_100_games_list[] = ['appid' => $appId, 'name' => $gameName];
            }
        }
    }
    error_log("fetch_dashboard_stats.php - calculateUserStats: Finished for User: {$user_steamID64_param}. 100% Games: {$out_games_100_count}, Total Ach: {$out_total_ach_earned_count}. Showcase games: " . count($out_completed_100_games_list));
}


function updateUserStatsInDB($steam_id_param, $username_param, $avatar_url_param, $games_100, $total_ach_earned, $db_connection) {
    if (!$db_connection || (is_object($db_connection) && $db_connection->connect_error)) {
         error_log("fetch_dashboard_stats.php - updateUserStatsInDB: DB connection error. User: {$steam_id_param}");
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
            error_log("fetch_dashboard_stats.php - DB Update Error: Execute failed for user {$steam_id_param}. " . mysqli_stmt_error($stmt));
        }
        mysqli_stmt_close($stmt);
    } else {
        error_log("fetch_dashboard_stats.php - DB Update Error: Prepare failed for user {$steam_id_param}. " . mysqli_error($db_connection));
    }
    return false;
}
// --- END OF COPIED/ADAPTED FUNCTIONS ---


$games_completed_count = 0;
$achievements_earned_count = 0;
$completed_100_showcase_list = []; // For profile page

$STATS_CACHE_DURATION = 3600 * 1; // 1 hour

// Check session cache first
if (isset($_SESSION['dashboard_stats']['games_100_count'], $_SESSION['dashboard_stats']['total_ach_earned'], $_SESSION['dashboard_stats']['completed_100_showcase_games'], $_SESSION['dashboard_stats']['stats_last_updated']) &&
    (time() - $_SESSION['dashboard_stats']['stats_last_updated'] < $STATS_CACHE_DURATION)) {
    
    $games_completed_count = $_SESSION['dashboard_stats']['games_100_count'];
    $achievements_earned_count = $_SESSION['dashboard_stats']['total_ach_earned'];
    $completed_100_showcase_list = $_SESSION['dashboard_stats']['completed_100_showcase_games'];

    error_log("fetch_dashboard_stats.php: Returning SESSION cached stats for user {$steamID64}.");
    echo json_encode([
        'success' => true,
        'games_100_count' => $games_completed_count,
        'total_ach_earned' => $achievements_earned_count,
        'source' => 'session_cache'
    ]);
    exit();
}

// If cache is stale or not set, recalculate
error_log("fetch_dashboard_stats.php: SESSION cache expired or not set for user {$steamID64}. Recalculating...");
if ($steamID64 && $steam_api_key) {
    // Pass the new $completed_100_showcase_list by reference
    calculateUserStats($steamID64, $steam_api_key, $games_completed_count, $achievements_earned_count, $completed_100_showcase_list);
    
    if (!isset($_SESSION['dashboard_stats'])) { $_SESSION['dashboard_stats'] = []; }
    $_SESSION['dashboard_stats']['games_100_count'] = $games_completed_count;
    $_SESSION['dashboard_stats']['total_ach_earned'] = $achievements_earned_count;
    $_SESSION['dashboard_stats']['completed_100_showcase_games'] = $completed_100_showcase_list; // Store the list
    $_SESSION['dashboard_stats']['stats_last_updated'] = time();
    error_log("fetch_dashboard_stats.php: Recalculated and SESSION cached stats for user {$steamID64}.");

    if ($db_link) { // $db_link comes from db_connect.php
        updateUserStatsInDB($steamID64, $username, $avatar, $games_completed_count, $achievements_earned_count, $db_link);
    } else {
        error_log("fetch_dashboard_stats.php: DB connection not available for DB update. User: {$steamID64}");
    }

    echo json_encode([
        'success' => true,
        'games_100_count' => $games_completed_count,
        'total_ach_earned' => $achievements_earned_count,
        'source' => 'recalculated'
    ]);

} else {
    error_log("fetch_dashboard_stats.php: Cannot recalculate stats. Missing SteamID or API Key. User: {$steamID64}");
    echo json_encode([
        'success' => false,
        'error' => 'Could not recalculate stats due to missing server configuration or user data.',
        // Optionally return stale data if available, or zeros
        'games_100_count' => $_SESSION['dashboard_stats']['games_100_count'] ?? 0,
        'total_ach_earned' => $_SESSION['dashboard_stats']['total_ach_earned'] ?? 0
    ]);
}

// Close DB connection if it was opened
if ($db_link && is_object($db_link) && get_class($db_link) === 'mysqli') {
    mysqli_close($db_link);
}

exit();
?>
