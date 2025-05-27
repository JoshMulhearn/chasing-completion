<?php
// fetch_dashboard_stats.php

ini_set('display_errors', 1); // Show errors for debugging (set to 0 in production)
error_reporting(E_ALL);    // Report all errors
// Set a longer timeout for this script if necessary, as API calls can be long
set_time_limit(300); // 5 minutes, adjust as needed

session_start();

header('Content-Type: application/json'); // Important: Send JSON response

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    error_log("fetch_dashboard_stats.php: Attempt to access while not logged in.");
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

if (!isset($_SESSION['userData']) || !isset($_SESSION['userData']['steam_id'])) {
    error_log("fetch_dashboard_stats.php: User data or SteamID not found in session.");
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

// --- FUNCTION DEFINITIONS ---
// (These were originally in dashboard.php or could be in separate include files)

function getAndCacheGameSchema($appId, $api_key_param, $cacheDir = 'cache/', $cacheDuration = 86400) {
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
    if (!is_writable($cacheDir)) { // Check after attempting to create
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
    $context_options = ['http' => ['timeout' => 10, 'ignore_errors' => true]]; // ignore_errors to get HTTP status codes if needed
    $context = stream_context_create($context_options);
    $schema_raw_response = @file_get_contents($schema_url, false, $context);

    if ($schema_raw_response === false) {
        error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: API call failed for AppID {$appId}. Error: ".(error_get_last()['message'] ?? 'Unknown'));
        return [];
    }
    // You might want to check $http_response_header here for actual HTTP status codes from Steam

    $schema_response = @json_decode($schema_raw_response, true);
    if ($schema_response === null || !isset($schema_response['game']['availableGameStats']['achievements'])) {
        // It's common for games to have no achievements or for the API to return an empty set.
        // Only log as an error if the JSON itself is malformed.
        if (json_last_error() !== JSON_ERROR_NONE && $schema_raw_response !== "" && $schema_raw_response !== "{}") {
             error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: Invalid JSON response for AppID {$appId}. Response: ". substr($schema_raw_response, 0, 200));
        } else {
            // Log non-critically if it's just empty or no achievements
            // error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: No achievements in schema for AppID {$appId} or empty response. Caching empty.");
        }
        @file_put_contents($cacheFile, json_encode([])); // Cache empty array if no achievements
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

function calculateUserStats(
    $user_steamID64_param,
    $api_key_param,
    &$out_games_100_count,
    &$out_total_ach_earned_count,
    &$out_completed_100_games_list,
    &$out_games_with_achievements_list
) {
    $out_games_100_count = 0;
    $out_total_ach_earned_count = 0;
    $out_completed_100_games_list = [];
    $out_games_with_achievements_list = [];

    if (!$user_steamID64_param || !$api_key_param) {
        error_log("fetch_dashboard_stats.php - calculateUserStats: Missing SteamID or API Key. User: {$user_steamID64_param}");
        return;
    }
    if (!isset($_SESSION['userData']['owned_games']['games']) || !is_array($_SESSION['userData']['owned_games']['games'])) {
        error_log("fetch_dashboard_stats.php - calculateUserStats: Owned games data not found or not an array for User: {$user_steamID64_param}");
        return;
    }

    error_log("fetch_dashboard_stats.php - calculateUserStats: Starting for User: {$user_steamID64_param}. Games in session: ".count($_SESSION['userData']['owned_games']['games']));

    foreach ($_SESSION['userData']['owned_games']['games'] as $ownedGame) {
        if (!isset($ownedGame['appid'])) continue;
        $appId = $ownedGame['appid'];
        $gameName = $ownedGame['name'] ?? 'Unknown Game';

        $gameSchema = getAndCacheGameSchema($appId, $api_key_param); // Calls the function defined above
        $totalAchievementsInSchema = count($gameSchema);
        $achievedCountForThisGame = 0;

        if (!empty($gameSchema)) { // Game has achievements defined in its schema
            $out_games_with_achievements_list[] = $ownedGame; // Add to list for games.php

            // Now check player's progress for this game
            // $totalAchievementsInSchema > 0 is implied by !empty($gameSchema) if schema structure is consistent
            $playerAchievements_url = "https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/?key={$api_key_param}&steamid={$user_steamID64_param}&appid={$appId}&l=english";
            $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
            $player_raw_response = @file_get_contents($playerAchievements_url, false, $context);

            if ($player_raw_response !== false) {
                $player_ach_response = json_decode($player_raw_response, true);
                // Check if playerstats and achievements exist and success is true
                if (isset($player_ach_response['playerstats']['success']) &&
                    $player_ach_response['playerstats']['success'] === true &&
                    isset($player_ach_response['playerstats']['achievements']) &&
                    is_array($player_ach_response['playerstats']['achievements'])) {
                    foreach ($player_ach_response['playerstats']['achievements'] as $playerAch) {
                        if (isset($playerAch['achieved']) && $playerAch['achieved'] == 1) {
                            $achievedCountForThisGame++;
                        }
                    }
                } elseif (isset($player_ach_response['playerstats']['success']) && $player_ach_response['playerstats']['success'] === false) {
                    // Log if API explicitly states failure for player achievements (e.g., profile private for this game)
                    // error_log("fetch_dashboard_stats.php - calculateUserStats: Player achievements API success false for AppID {$appId}, User {$user_steamID64_param}. Error: " . ($player_ach_response['playerstats']['error'] ?? 'Unknown API error'));
                }
            } else {
                error_log("fetch_dashboard_stats.php - calculateUserStats: Failed player achievement API call for AppID {$appId}, User {$user_steamID64_param}. Error: ".(error_get_last()['message'] ?? 'Unknown HTTP error'));
            }
            usleep(50000); // Be nice to Steam API
        }
        // else: Game has no achievements in schema, $totalAchievementsInSchema is 0. $achievedCountForThisGame remains 0.

        $out_total_ach_earned_count += $achievedCountForThisGame;

        // Check for 100% completion only if game has achievements
        if ($totalAchievementsInSchema > 0 && $achievedCountForThisGame === $totalAchievementsInSchema) {
            $out_games_100_count++;
            $out_completed_100_games_list[] = ['appid' => $appId, 'name' => $gameName];
        }
    }
    error_log("fetch_dashboard_stats.php - calculateUserStats: Finished for User: {$user_steamID64_param}. 100% Games: {$out_games_100_count}, Total Ach: {$out_total_ach_earned_count}. Showcase games: " . count($out_completed_100_games_list) . ". Games with achievements: " . count($out_games_with_achievements_list));
}

function updateUserStatsInDB($steam_id_param, $username_param, $avatar_url_param, $games_100, $total_ach_earned, $db_connection) {
    if (!$db_connection || (is_object($db_connection) && $db_connection->connect_error)) {
         error_log("fetch_dashboard_stats.php - updateUserStatsInDB: DB connection error or link not valid. User: {$steam_id_param}");
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
            error_log("fetch_dashboard_stats.php - DB Update Error: Failed to execute statement for user {$steam_id_param}. " . mysqli_stmt_error($stmt));
        }
        mysqli_stmt_close($stmt);
    } else {
        error_log("fetch_dashboard_stats.php - DB Update Error: Failed to prepare statement for user {$steam_id_param}. " . mysqli_error($db_connection));
    }
    return false;
}
// --- END OF FUNCTION DEFINITIONS ---


$games_completed_count_calculated = 0;
$achievements_earned_count_calculated = 0;
$completed_100_showcase_list_for_session = [];
$games_with_achievements_for_session = [];

$STATS_CACHE_DURATION = 3600 * 1; // 1 hour

// Check session cache first
if (isset($_SESSION['dashboard_stats']['games_100_count'],
          $_SESSION['dashboard_stats']['total_ach_earned'],
          $_SESSION['dashboard_stats']['completed_100_showcase_games'], // Ensure all expected keys are checked
          $_SESSION['dashboard_stats']['games_with_achievements'],
          $_SESSION['dashboard_stats']['stats_last_updated']) &&
    (time() - $_SESSION['dashboard_stats']['stats_last_updated'] < $STATS_CACHE_DURATION)) {

    // If cache is fresh, use it and exit
    error_log("fetch_dashboard_stats.php: Returning SESSION cached stats for user {$steamID64}.");
    echo json_encode([
        'success' => true,
        'games_100_count' => $_SESSION['dashboard_stats']['games_100_count'],
        'total_ach_earned' => $_SESSION['dashboard_stats']['total_ach_earned'],
        // 'showcase_list_count' => count($_SESSION['dashboard_stats']['completed_100_showcase_games']), // Optional: for debugging
        // 'games_with_ach_count' => count($_SESSION['dashboard_stats']['games_with_achievements']), // Optional: for debugging
        'source' => 'session_cache'
    ]);
    // Close DB connection if it was opened by db_connect.php but not used further by this path
    if ($db_link && is_object($db_link) && get_class($db_link) === 'mysqli') {
        mysqli_close($db_link);
    }
    exit();
}

// If cache is stale or not set, recalculate
error_log("fetch_dashboard_stats.php: SESSION cache expired or not set for user {$steamID64}. Recalculating...");

if ($steamID64 && $steam_api_key) {
    calculateUserStats(
        $steamID64,
        $steam_api_key,
        $games_completed_count_calculated,
        $achievements_earned_count_calculated,
        $completed_100_showcase_list_for_session,
        $games_with_achievements_for_session
    );

    // Update the session with newly calculated data
    if (!isset($_SESSION['dashboard_stats'])) { $_SESSION['dashboard_stats'] = []; } // Should ideally always be set by now
    $_SESSION['dashboard_stats']['games_100_count'] = $games_completed_count_calculated;
    $_SESSION['dashboard_stats']['total_ach_earned'] = $achievements_earned_count_calculated;
    $_SESSION['dashboard_stats']['completed_100_showcase_games'] = $completed_100_showcase_list_for_session;
    $_SESSION['dashboard_stats']['games_with_achievements'] = $games_with_achievements_for_session;
    $_SESSION['dashboard_stats']['stats_last_updated'] = time();

    error_log("fetch_dashboard_stats.php: Recalculated and SESSION cached stats for user {$steamID64}. Showcase: " . count($completed_100_showcase_list_for_session) . ", Games w/Ach: " . count($games_with_achievements_for_session));

    // Update database
    if ($db_link) {
        updateUserStatsInDB($steamID64, $username, $avatar, $games_completed_count_calculated, $achievements_earned_count_calculated, $db_link);
    } else {
        error_log("fetch_dashboard_stats.php: DB connection not available for DB update. User: {$steamID64}");
    }

    echo json_encode([
        'success' => true,
        'games_100_count' => $games_completed_count_calculated,
        'total_ach_earned' => $achievements_earned_count_calculated,
        'source' => 'recalculated'
    ]);

} else {
    // This case should ideally not be hit if login and initial checks are fine.
    error_log("fetch_dashboard_stats.php: Critical error - Cannot recalculate stats. Missing SteamID or API Key. User: {$steamID64}");
    echo json_encode([
        'success' => false,
        'error' => 'Could not recalculate stats due to missing server configuration or user data.',
        'games_100_count' => $_SESSION['dashboard_stats']['games_100_count'] ?? 0, // Fallback to old or 0
        'total_ach_earned' => $_SESSION['dashboard_stats']['total_ach_earned'] ?? 0 // Fallback to old or 0
    ]);
}

// Close DB connection if it was opened
if ($db_link && is_object($db_link) && get_class($db_link) === 'mysqli') {
    mysqli_close($db_link);
}

exit();
?>