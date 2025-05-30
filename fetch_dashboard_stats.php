<?php
// fetch_dashboard_stats.php

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

session_start();

header('Content-Type: application/json');

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

// This will define $pdo if successful, or die if db_connect.php has a die() on failure.
require_once __DIR__ . '/db_connect.php';

// --- FUNCTION DEFINITIONS ---
// getAndCacheGameSchema and calculateUserStats do not use the DB directly,
// so their PDO conversion is not about changing their internal DB calls (they have none)
// but ensuring the script that CALLS functions needing DB (like updateUserStatsInDB)
// has the PDO object.

function getAndCacheGameSchema($appId, $api_key_param, $cacheDir = 'cache/', $cacheDuration = 86400) {
    // ... (This function remains the same as it doesn't use the database connection variable)
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
    $context_options = ['http' => ['timeout' => 10, 'ignore_errors' => true]];
    $context = stream_context_create($context_options);
    $schema_raw_response = @file_get_contents($schema_url, false, $context);

    if ($schema_raw_response === false) {
        error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: API call failed for AppID {$appId}. Error: ".(error_get_last()['message'] ?? 'Unknown'));
        return [];
    }

    $schema_response = @json_decode($schema_raw_response, true);
    if ($schema_response === null || !isset($schema_response['game']['availableGameStats']['achievements'])) {
        if (json_last_error() !== JSON_ERROR_NONE && $schema_raw_response !== "" && $schema_raw_response !== "{}") {
             error_log("fetch_dashboard_stats.php - getAndCacheGameSchema: Invalid JSON response for AppID {$appId}. Response: ". substr($schema_raw_response, 0, 200));
        }
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

function calculateUserStats(
    $user_steamID64_param,
    $api_key_param,
    &$out_games_100_count,
    &$out_total_ach_earned_count,
    &$out_completed_100_games_list,
    &$out_games_with_achievements_list
) {
    // ... (This function remains the same as it doesn't use the database connection variable)
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

        $gameSchema = getAndCacheGameSchema($appId, $api_key_param);
        $totalAchievementsInSchema = count($gameSchema);
        $achievedCountForThisGame = 0;

        if (!empty($gameSchema)) {
            $out_games_with_achievements_list[] = $ownedGame;

            $playerAchievements_url = "https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/?key={$api_key_param}&steamid={$user_steamID64_param}&appid={$appId}&l=english";
            $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
            $player_raw_response = @file_get_contents($playerAchievements_url, false, $context);

            if ($player_raw_response !== false) {
                $player_ach_response = json_decode($player_raw_response, true);
                if (isset($player_ach_response['playerstats']['success']) &&
                    $player_ach_response['playerstats']['success'] === true &&
                    isset($player_ach_response['playerstats']['achievements']) &&
                    is_array($player_ach_response['playerstats']['achievements'])) {
                    foreach ($player_ach_response['playerstats']['achievements'] as $playerAch) {
                        if (isset($playerAch['achieved']) && $playerAch['achieved'] == 1) {
                            $achievedCountForThisGame++;
                        }
                    }
                }
            } else {
                error_log("fetch_dashboard_stats.php - calculateUserStats: Failed player achievement API call for AppID {$appId}, User {$user_steamID64_param}. Error: ".(error_get_last()['message'] ?? 'Unknown HTTP error'));
            }
            usleep(50000);
        }

        $out_total_ach_earned_count += $achievedCountForThisGame;

        if ($totalAchievementsInSchema > 0 && $achievedCountForThisGame === $totalAchievementsInSchema) {
            $out_games_100_count++;
            $out_completed_100_games_list[] = ['appid' => $appId, 'name' => $gameName];
        }
    }
    error_log("fetch_dashboard_stats.php - calculateUserStats: Finished for User: {$user_steamID64_param}. 100% Games: {$out_games_100_count}, Total Ach: {$out_total_ach_earned_count}. Showcase games: " . count($out_completed_100_games_list) . ". Games with achievements: " . count($out_games_with_achievements_list));
}

// MODIFIED: Now expects a PDO connection object
function updateUserStatsInDB($steam_id_param, $username_param, $avatar_url_param, $games_100, $total_ach_earned, $pdo_connection) {
    if (!$pdo_connection || !($pdo_connection instanceof PDO)) {
         error_log("fetch_dashboard_stats.php - updateUserStatsInDB: PDO connection not valid or not provided. User: {$steam_id_param}");
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
            // This might not be reached if ATTR_ERRMODE is EXCEPTION
            error_log("fetch_dashboard_stats.php - DB Update Error: Failed to execute PDO statement for user {$steam_id_param}. Info: " . implode(", ", $stmt->errorInfo()));
            return false;
        }
    } catch (PDOException $e) {
        error_log("fetch_dashboard_stats.php - DB Update PDOException for user {$steam_id_param}: " . $e->getMessage());
        return false;
    }
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
          $_SESSION['dashboard_stats']['completed_100_showcase_games'],
          $_SESSION['dashboard_stats']['games_with_achievements'],
          $_SESSION['dashboard_stats']['stats_last_updated']) &&
    (time() - $_SESSION['dashboard_stats']['stats_last_updated'] < $STATS_CACHE_DURATION)) {

    error_log("fetch_dashboard_stats.php: Returning SESSION cached stats for user {$steamID64}.");
    echo json_encode([
        'success' => true,
        'games_100_count' => $_SESSION['dashboard_stats']['games_100_count'],
        'total_ach_earned' => $_SESSION['dashboard_stats']['total_ach_earned'],
        'source' => 'session_cache'
    ]);
    // $pdo will be nulled at the end of script if set, or if db_connect.php failed, it might not be set.
    exit();
}

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

    if (!isset($_SESSION['dashboard_stats'])) { $_SESSION['dashboard_stats'] = []; }
    $_SESSION['dashboard_stats']['games_100_count'] = $games_completed_count_calculated;
    $_SESSION['dashboard_stats']['total_ach_earned'] = $achievements_earned_count_calculated;
    $_SESSION['dashboard_stats']['completed_100_showcase_games'] = $completed_100_showcase_list_for_session;
    $_SESSION['dashboard_stats']['games_with_achievements'] = $games_with_achievements_for_session;
    $_SESSION['dashboard_stats']['stats_last_updated'] = time();

    error_log("fetch_dashboard_stats.php: Recalculated and SESSION cached stats for user {$steamID64}. Showcase: " . count($completed_100_showcase_list_for_session) . ", Games w/Ach: " . count($games_with_achievements_for_session));

    // Update database using PDO
    // $pdo should be defined by db_connect.php
    if (isset($pdo) && $pdo instanceof PDO) {
        updateUserStatsInDB($steamID64, $username, $avatar, $games_completed_count_calculated, $achievements_earned_count_calculated, $pdo);
    } else {
        error_log("fetch_dashboard_stats.php: PDO connection object (\$pdo) not available for DB update. User: {$steamID64}");
    }

    echo json_encode([
        'success' => true,
        'games_100_count' => $games_completed_count_calculated,
        'total_ach_earned' => $achievements_earned_count_calculated,
        'source' => 'recalculated'
    ]);

} else {
    error_log("fetch_dashboard_stats.php: Critical error - Cannot recalculate stats. Missing SteamID or API Key. User: {$steamID64}");
    echo json_encode([
        'success' => false,
        'error' => 'Could not recalculate stats due to missing server configuration or user data.',
        'games_100_count' => $_SESSION['dashboard_stats']['games_100_count'] ?? 0,
        'total_ach_earned' => $_SESSION['dashboard_stats']['total_ach_earned'] ?? 0
    ]);
}

// The PDO connection is typically closed when the $pdo object is destroyed (e.g., end of script).
// You can explicitly set $pdo = null; if you want to close it sooner.
if (isset($pdo)) {
    $pdo = null;
    // error_log("fetch_dashboard_stats.php: PDO connection nulled at script end.");
}

exit();
?>