<?php
    // This should be at the very top, before any output
    session_start();

    // Include your (NOW PDO) database connection
    require_once __DIR__ . '/db_connect.php'; // Make sure this path is correct and $pdo is available

    // ... (your existing getAndCacheGameSchema function - no changes needed there) ...
    function getAndCacheGameSchema($appId, $steam_api_key, $cacheDir = 'cache/', $cacheDuration = 86400)
    {
        // ... (your existing function code) ...
        // (Make sure it returns an array or empty array)
        $cacheFile = rtrim($cacheDir, '/') . '/schema_' . $appId . '.json';
        $gameSchemaAchievements = [];

        if (!is_dir($cacheDir))
        {
            @mkdir($cacheDir, 0775, true);
        }

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheDuration) && is_readable($cacheFile))
        {
            $cachedContent = @file_get_contents($cacheFile);
            if ($cachedContent !== false)
            {
                $cachedData = @json_decode($cachedContent, true);
                if (is_array($cachedData))
                {
                    // error_log("SCHEMA: Loaded schema for appID {$appId} from cache.");
                    return $cachedData;
                }
                else
                {
                    error_log("SCHEMA_CACHE_CORRUPT: Cache file for appID {$appId} is not valid JSON.");
                }
            }
            else
            {
                error_log("SCHEMA_CACHE_READ_FAIL: Could not read cache file for appID {$appId}.");
            }
        }

        // error_log("SCHEMA: Cache miss or expired for appID {$appId}. Fetching from API.");
        $schema_url = "https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/?key={$steam_api_key}&appid={$appId}&l=english";
        $schema_raw_response = @file_get_contents($schema_url);

        if ($schema_raw_response === false)
        {
            $last_php_error = error_get_last();
            error_log("SCHEMA_API_CALL_FAIL: Could not fetch schema for appID {$appId}. URL: {$schema_url}. PHP Error: " . ($last_php_error['message'] ?? 'N/A'));
            return [];
        }

        $schema_response = @json_decode($schema_raw_response, true);

        if ($schema_response === null || !isset($schema_response['game']['availableGameStats']['achievements']))
        {
            error_log("SCHEMA_API_DECODE_FAIL or NO_ACHIEVEMENTS_IN_SCHEMA: Invalid response or no achievements for appID {$appId}. Raw: " . substr($schema_raw_response, 0, 200));
            if (is_writable($cacheDir) || (file_exists($cacheFile) && is_writable($cacheFile)) || (!file_exists($cacheFile) && is_writable(dirname($cacheFile)))) {
                @file_put_contents($cacheFile, json_encode([]));
            }
            return [];
        }

        $achievementsFromSchema = $schema_response['game']['availableGameStats']['achievements'];
        foreach ($achievementsFromSchema as $schemaAch)
        {
            if (isset($schemaAch['name']))
            {
                $gameSchemaAchievements[$schemaAch['name']] = $schemaAch;
            }
        }
        if (is_writable($cacheDir) || (file_exists($cacheFile) && is_writable($cacheFile)) || (!file_exists($cacheFile) && is_writable(dirname($cacheFile)))) {
            if (@file_put_contents($cacheFile, json_encode($gameSchemaAchievements)) === false)
            {
                error_log("SCHEMA_CACHE_WRITE_FAIL: Failed to write schema for appID {$appId} to cache: {$cacheFile}. Check permissions.");
            }
            // else { error_log("SCHEMA: Saved schema for appID {$appId} to cache."); }
        }
        else { error_log("SCHEMA_CACHE_NOT_WRITABLE: Cache directory or file for appID {$appId} is not writable."); }
        return $gameSchemaAchievements;
    }


    // Check if an admin is logged in
    $is_admin_logged_in = (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true);
    // $admin_username = $is_admin_logged_in ? ($_SESSION['admin_username'] ?? 'Admin') : null; // Already defined if needed

    // Regular Steam user data for navbar
    $username = $_SESSION['userData']['name'] ?? null;
    $avatar = $_SESSION['userData']['avatar'] ?? null;

    require_once __DIR__ . '/steam-api-key.php';
    if (!defined('steam_api_key') || empty(steam_api_key)) {
        error_log("FATAL: Steam API key is not defined or empty.");
        die("Error: Steam API key configuration issue.");
    }
    $steam_api_key = steam_api_key;

    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: Error.php?code=not_logged_in");
        exit();
    }
    if (!isset($_SESSION['userData']['steam_id'], $_SESSION['userData']['name'])) {
        error_log("Error in game_achievements.php: UserData (steam_id or name) not fully set.");
        header("Location: Error.php?code=session_data_incomplete");
        exit();
    }
    $steamID64 = $_SESSION['userData']['steam_id']; // Current viewing user

    if (!isset($_GET['appid']) || !is_numeric($_GET['appid'])) {
        error_log("Error: Game AppID missing or not numeric. GET: " . print_r($_GET, true));
        die("Error: Game AppID is missing or invalid.");
    }
    $appId = filter_var($_GET['appid'], FILTER_VALIDATE_INT);
    if ($appId === false || $appId <= 0) {
        error_log("Error: Game AppID invalid. Original: " . $_GET['appid'] . ", Filtered: " . $appId);
        die("Error: Game AppID is invalid.");
    }

    // ... (your existing variable initializations for $gameName, $gameCoverImageUrl etc.) ...
    $playerAchievementsData = null;
    $gameSchemaData = [];
    $gameName = 'Game';
    $apiErrorMessage = '';
    $gameCoverImageUrl = "https://placehold.co/300x450/1B2838/E0E0E0?text=Game+Cover&fontsize=20";
    $completedAchievementsCount = 0;
    $totalAchievementsForGame = 0;
    $completionPercentage = 0;

    // ... (your existing logic to get $gameName, $gameCoverImageUrl from session) ...
     if (isset($_SESSION['userData']['owned_games']['games']) && is_array($_SESSION['userData']['owned_games']['games'])) {
        foreach ($_SESSION['userData']['owned_games']['games'] as $ownedGame) {
            if (isset($ownedGame['appid'], $ownedGame['name']) && $ownedGame['appid'] == $appId) {
                $gameName = htmlspecialchars($ownedGame['name']);
                $gameCoverImageUrl = "https://cdn.akamai.steamstatic.com/steam/apps/{$appId}/library_600x900.jpg";
                break;
            }
        }
    }

    $gameSchemaData = getAndCacheGameSchema($appId, $steam_api_key);
    if (!empty($gameSchemaData)) {
        $totalAchievementsForGame = count($gameSchemaData);
    } else {
        error_log("Warning: Could not load game schema for appID {$appId}.");
    }

    // ... (your existing logic to get $playerAchievementsData and $completedAchievementsCount) ...
    $playerAchievements_url = "https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/?key={$steam_api_key}&steamid={$steamID64}&appid={$appId}&l=english";
    $player_raw_response = @file_get_contents($playerAchievements_url);
    if ($player_raw_response === false) {
        $last_php_error = error_get_last();
        $apiErrorMessage = "Could not retrieve your achievements. Profile might be private or connection issue.";
        error_log("PLAYER_ACH_API_CALL_FAIL: appid={$appId}, steamid={$steamID64}. PHP Error: " . ($last_php_error['message'] ?? 'N/A'));
    } else {
        $player_ach_response = json_decode($player_raw_response, true);
        if ($player_ach_response === null) {
            $apiErrorMessage = "Invalid response for your achievements.";
            error_log("PLAYER_ACH_API_DECODE_FAIL: appid={$appId}, steamid={$steamID64}.");
        } elseif (isset($player_ach_response['playerstats']['success']) && $player_ach_response['playerstats']['success'] === true) {
            $playerAchievementsData = $player_ach_response['playerstats']['achievements'] ?? [];
            if (isset($player_ach_response['playerstats']['gameName']) && ($gameName === 'Game' || empty($gameName)) ) {
                $gameName = htmlspecialchars($player_ach_response['playerstats']['gameName']);
            }
            if (!empty($playerAchievementsData)) {
                foreach ($playerAchievementsData as $playerAch) {
                    if (isset($playerAch['achieved']) && $playerAch['achieved'] == 1) {
                        $completedAchievementsCount++;
                    }
                }
            } // ... (rest of your existing error handling for player achievements)
        } else {
             $apiErrorMessage = "Steam API issue retrieving your achievements.";
            if (isset($player_ach_response['playerstats']['error'])) {
                $apiErrorMessage .= " Message: " . htmlspecialchars($player_ach_response['playerstats']['error']);
            }
        }
    }
    if ($totalAchievementsForGame > 0) {
        $completionPercentage = round(($completedAchievementsCount / $totalAchievementsForGame) * 100);
    }

    // For user interaction buttons
    $is_steam_user_logged_in = (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['userData']['steam_id']));
    $user_can_interact = $is_steam_user_logged_in && !$is_admin_logged_in; // Regular users, not admins

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Achievements - <?php echo htmlspecialchars($gameName); ?> - Chasing Completion</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Genos:ital,wght@0,100..900;1,100..900&family=Orbitron:wght@400..900&family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="page-header-container">
        <a href="games.php" class="back-button">Back to Games</a>
        <h2 class="page-title">Achievements for <?php echo htmlspecialchars($gameName); ?></h2>
    </div>

    <?php if (!empty($apiErrorMessage) && (empty($playerAchievementsData) && empty($gameSchemaData)) ): ?>
        <p class="api-error-message"><?php echo htmlspecialchars($apiErrorMessage); ?></p>
    <?php endif; ?>

    <div class="achievements-page-layout">
        <div class="game-info-column">
            <img src="<?php echo htmlspecialchars($gameCoverImageUrl); ?>" alt="<?php echo htmlspecialchars($gameName); ?> Cover Art" class="game-cover"
                 onerror="this.onerror=null; this.src='https://placehold.co/300x450/1B2838/66C0F4?text=Cover+Missing&fontsize=18';">
            <h2><?php echo htmlspecialchars($gameName); ?></h2>
            <p><strong>AppID:</strong> <?php echo $appId; ?></p>
            <div class="game-completion-stats">
                <p><strong>Your Progress:</strong></p>
                <p><?php echo $completedAchievementsCount; ?> / <?php echo $totalAchievementsForGame > 0 ? $totalAchievementsForGame : 'N/A'; ?> Achievements</p>
                <p><?php echo "Completion: " . $completionPercentage . "%"; ?></p>
            </div>
        </div>

        <div class="achievements-column">
            <h2 class="ach-colum-title">Achievements List</h2>
            <?php if (!empty($apiErrorMessage) && (!empty($playerAchievementsData) || !empty($gameSchemaData)) && !(empty($playerAchievementsData) && empty($gameSchemaData)) ): ?>
                <p class="api-error-message" style="margin-bottom: 15px;"><?php echo htmlspecialchars($apiErrorMessage); ?></p>
            <?php endif; ?>

            <?php
                $achievementsToIterate = [];
                if (!empty($gameSchemaData)) {
                    $achievementsToIterate = $gameSchemaData;
                } elseif (!empty($playerAchievementsData)) {
                    foreach($playerAchievementsData as $pAch) {
                        if(isset($pAch['apiname'])) {
                            $achievementsToIterate[$pAch['apiname']] = [
                                'name' => $pAch['apiname'],
                                'displayName' => $pAch['name'] ?? $pAch['apiname'],
                                'description' => $pAch['description'] ?? 'No description.',
                                'hidden' => $pAch['hidden'] ?? 0,
                            ];
                        }
                    }
                }
            ?>

            <?php if (!empty($achievementsToIterate)): ?>
                <ul class="achievements-list">
                    <?php foreach ($achievementsToIterate as $apiName => $baseAchData): ?>
                        <?php
                            // ... (your existing logic for $playerAchievedStatus, $playerUnlockTime, $isAchieved, $achName, $achDesc, $iconUrl)
                            $playerAchievedStatus = null;
                            $playerUnlockTime = null;
                            if (!empty($playerAchievementsData)) {
                                foreach ($playerAchievementsData as $playerSpecificAch) {
                                    if (isset($playerSpecificAch['apiname']) && $playerSpecificAch['apiname'] === $apiName) {
                                        $playerAchievedStatus = (isset($playerSpecificAch['achieved']) && $playerSpecificAch['achieved'] == 1);
                                        if ($playerAchievedStatus && isset($playerSpecificAch['unlocktime']) && $playerSpecificAch['unlocktime'] > 0) {
                                            $playerUnlockTime = date('F j, Y, g:i a', $playerSpecificAch['unlocktime']);
                                        }
                                        break;
                                    }
                                }
                            }
                            $isAchieved = ($playerAchievedStatus === true);
                            $achName = htmlspecialchars($baseAchData['displayName'] ?? ($baseAchData['name'] ?? $apiName));
                            $achDesc = htmlspecialchars($baseAchData['description'] ?? 'No description available.');
                            $iconUrl = '';
                            if ($isAchieved && !empty($baseAchData['icon'])) { $iconUrl = htmlspecialchars($baseAchData['icon']); }
                            elseif (!$isAchieved && !empty($baseAchData['icongray'])) { $iconUrl = htmlspecialchars($baseAchData['icongray']); }
                            elseif (!empty($baseAchData['icon'])) { $iconUrl = htmlspecialchars($baseAchData['icon']); }


                            // Fetch Official Admin Description
                            $admin_description_content = null;
                            $admin_display_text = "No admin description for this achievement.";
                            $admin_link_text = "Add Admin Desc";
                            if (isset($pdo)) { // Check if $pdo is set from db_connect.php
                                try {
                                    $stmt_admin_desc = $pdo->prepare("SELECT description FROM achievement_admin_descriptions WHERE appid = ? AND achievement_apiname = ?");
                                    $stmt_admin_desc->execute([$appId, $apiName]);
                                    $fetched_admin_desc = $stmt_admin_desc->fetchColumn();
                                    if ($fetched_admin_desc !== false && $fetched_admin_desc !== null && trim($fetched_admin_desc) !== '') {
                                        $admin_description_content = $fetched_admin_desc;
                                        $admin_display_text = "Admin: " . htmlspecialchars($admin_description_content);
                                        $admin_link_text = "Edit Admin Desc";
                                    }
                                } catch (PDOException $e) {
                                    error_log("ADMIN_DESC_FETCH_FAIL for {$appId}, {$apiName}: " . $e->getMessage());
                                    $admin_display_text = "Error loading admin description.";
                                }
                            }

                            // Fetch Approved User Notes
                            $approved_user_notes = [];
                            if (isset($pdo)) {
                                try {
                                    $stmt_user_notes = $pdo->prepare("SELECT username_at_submission, note_text, submitted_at FROM user_achievement_notes WHERE appid = ? AND achievement_apiname = ? AND status = 'approved' ORDER BY submitted_at DESC LIMIT 5"); // Limit for performance
                                    $stmt_user_notes->execute([$appId, $apiName]);
                                    $approved_user_notes = $stmt_user_notes->fetchAll();
                                } catch (PDOException $e) {
                                    error_log("Error fetching approved user notes: " . $e->getMessage());
                                }
                            }
                        ?>
                        <li class="achievement-item <?php echo $isAchieved ? 'achieved' : 'not-achieved'; ?>">
                            <?php if (!empty($iconUrl)): ?>
                                <img src="<?php echo $iconUrl; ?>" alt="<?php echo $achName; ?> Icon" class="achievement-icon"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="achievement-icon-placeholder" style="display:none;">ICON ERR</div>
                            <?php else: ?>
                                <div class="achievement-icon-placeholder">NO ICON</div>
                            <?php endif; ?>
                            <div class="achievement-details">
                                <h4><?php echo $achName; ?></h4>
                                <p><?php echo $achDesc; ?></p>
                                <?php if ($playerUnlockTime): ?>
                                    <p class="unlock-time">Unlocked: <?php echo $playerUnlockTime; ?></p>
                                <?php endif; ?>

                                <?php if ($is_admin_logged_in): ?>
                                    <div class="admin-achievement-desc">
                                        <p><?php echo $admin_display_text; ?></p>
                                        <a href="edit_achievement_description.php?appid=<?php echo $appId; ?>&apiname=<?php echo urlencode($apiName); ?>&ach_name=<?php echo urlencode($achName); ?>" class="admin-desc-link">
                                            <?php echo $admin_link_text; ?>
                                        </a>
                                    </div>
                                <?php elseif ($admin_description_content !== null): ?>
                                     <div class="admin-achievement-desc">
                                        <p style="font-weight:bold; margin-bottom:2px;">Admin Note:</p>
                                        <p style="font-style:normal;"><?php echo nl2br(htmlspecialchars($admin_description_content)); ?></p>
                                     </div>
                                <?php endif; ?>

                                <?php if (!empty($approved_user_notes)): ?>
                                    <div class="approved-user-notes-section">
                                        <h5 style="margin-bottom:5px; font-size:0.95em; color:#333;">User Notes & Guides:</h5>
                                        <?php foreach($approved_user_notes as $user_note): ?>
                                            <div class="user-note-item">
                                                <p style="font-weight:bold; margin-bottom:3px; font-size:0.9em;">
                                                    By: <?php echo htmlspecialchars($user_note['username_at_submission']); ?>
                                                   <span style="font-size:0.8em; color:#777; font-weight:normal;">(<?php echo date("M j, Y", strtotime($user_note['submitted_at'])); ?>)</span>
                                                </p>
                                                <div style="white-space:pre-wrap; font-size:0.9em;"><?php echo nl2br(htmlspecialchars($user_note['note_text'])); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($user_can_interact): ?>
                                <div class="user-achievement-actions">
                                    <button class="user-action-btn request-help-btn"
                                            data-appid="<?php echo $appId; ?>"
                                            data-apiname="<?php echo htmlspecialchars($apiName); ?>"
                                            data-achname="<?php echo htmlspecialchars($achName); ?>">
                                        Request Help
                                    </button>
                                    <button class="user-action-btn write-note-btn"
                                            data-appid="<?php echo $appId; ?>"
                                            data-apiname="<?php echo htmlspecialchars($apiName); ?>"
                                            data-achname="<?php echo htmlspecialchars($achName); ?>">
                                        Write Note
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif (empty($apiErrorMessage)): ?>
                <p style="text-align:center; padding: 20px;">This game has no achievements, or they could not be loaded.</p>
            <?php endif; ?>
        </div>

        <div class="right-sidebar-column">
            <!-- Leaderboard Panel (Your existing code) -->
            <div class="game-leaderboard-panel">
                <h2>Game Leaderboard</h2>
                <div class="leaderboard-list">
                    <p>Leaderboard data is not yet available.</p>
                </div>
            </div>
            <!-- Admin Actions Panel for GAME WIDE descriptions (Your existing code, now conditional) -->
            <?php if ($is_admin_logged_in): ?>
            <div class="admin-actions-panel">
                <h3>Game-Wide Admin Actions</h3>
                <div class="admin-btns">
                    <a href="manage_game_details.php?appid=<?php echo $appId; ?>&action=request_guide">Request Game Guide</a>
                    <a href="manage_game_details.php?appid=<?php echo $appId; ?>&action=write_guide">Write Game Guide</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include 'footer.php'; ?>
    <script src="javascript/bar-menu.js"></script>
    <script>
    // JavaScript for user actions (Request Help / Write Note)
    document.addEventListener('DOMContentLoaded', function() {
        const requestHelpButtons = document.querySelectorAll('.request-help-btn');
        const writeNoteButtons = document.querySelectorAll('.write-note-btn');

        requestHelpButtons.forEach(button => {
            button.addEventListener('click', function() {
                const appid = this.dataset.appid;
                const apiname = this.dataset.apiname;
                const achname = this.dataset.achname;
                const userMessage = prompt("Optional: Add a brief message for your help request for '" + achname + "':", "");

                // User pressed Cancel or left it empty (we allow empty for just a flag request)
                if (userMessage === null) return;

                submitUserAction('request_help', { appid, apiname, achname, message: userMessage });
            });
        });

        writeNoteButtons.forEach(button => {
            button.addEventListener('click', function() {
                const appid = this.dataset.appid;
                const apiname = this.dataset.apiname;
                const achname = this.dataset.achname;
                const userNote = prompt(`Write your note/guide for "${achname}":`);

                if (userNote && userNote.trim() !== "") {
                    submitUserAction('write_note', { appid, apiname, achname, note: userNote });
                } else if (userNote !== null) { // User pressed OK but left it empty
                    alert("Note cannot be empty.");
                }
            });
        });

        function submitUserAction(actionType, data) {
            const formData = new FormData();
            formData.append('action', actionType);
            for (const key in data) {
                formData.append(key, data[key]);
            }
            // TODO: Add CSRF token here if you implement them
            // formData.append('csrf_token', 'your_csrf_token_value');

            fetch('process_user_achievement_action.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) { // Check for non-2xx status codes
                    return response.json().catch(() => null).then(errorData => { // Try to parse JSON error
                        throw new Error( (errorData && errorData.message) || `Server responded with ${response.status}`);
                    });
                }
                return response.json();
            })
            .then(result => {
                if (result.success) {
                    alert(result.message || "Action submitted successfully!");
                } else {
                    alert("Error: " + (result.message || "Could not process your request."));
                }
            })
            .catch(error => {
                console.error('Error submitting action:', error);
                alert("An unexpected error occurred: " + error.message);
            });
        }
    });
    </script>
</body>
</html>