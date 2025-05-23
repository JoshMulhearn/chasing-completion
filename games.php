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

    require_once __DIR__ . '/steam-api-key.php';
    if (!defined('steam_api_key') || empty(steam_api_key)) 
    {
        die("Error: Steam API key is not defined or empty. Please check steam-api-key.php");
    }
    $steam_api_key = steam_api_key;

    function getAndCacheGameSchema($appId, $steam_api_key, $cacheDir = 'cache/', $cacheDuration = 86400) 
    { 
        $cacheFile = rtrim($cacheDir, '/') . '/schema_' . $appId . '.json';
        $gameSchemaAchievements = [];
        if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheDuration) && is_readable($cacheFile)) {
            $cachedContent = @file_get_contents($cacheFile); 
            if ($cachedContent !== false) {
                $cachedData = @json_decode($cachedContent, true); 
                if (is_array($cachedData)) {
                    error_log("SCHEMA (games.php): Loaded schema for appID {$appId} from cache."); 
                    return $cachedData; 
                } else { error_log("SCHEMA_CACHE_CORRUPT (games.php): Cache file for appID {$appId} is not valid JSON."); }
            } else { error_log("SCHEMA_CACHE_READ_FAIL (games.php): Could not read cache file for appID {$appId}."); }
        }

        error_log("SCHEMA (games.php): Cache miss or expired for appID {$appId}. Fetching from API."); 
        $schema_url = "https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/?key={$steam_api_key}&appid={$appId}&l=english"; 
        $schema_raw_response = @file_get_contents($schema_url); 

        if ($schema_raw_response === false) {
            $last_php_error = error_get_last(); 
            error_log("SCHEMA_API_CALL_FAIL (games.php): Could not fetch schema for appID {$appId}. URL: {$schema_url}. PHP Error: " . ($last_php_error['message'] ?? 'N/A'));
            return [];
        }

        $schema_response = @json_decode($schema_raw_response, true); 
        if ($schema_response === null || !isset($schema_response['game']['availableGameStats']['achievements'])) {
            error_log("SCHEMA_API_DECODE_FAIL or NO_ACHIEVEMENTS_IN_SCHEMA (games.php): Invalid response or no achievements for appID {$appId}. Raw: " . substr($schema_raw_response, 0, 500));
            if (is_writable($cacheDir) || (file_exists($cacheFile) && is_writable($cacheFile)) || (!file_exists($cacheFile) && is_writable(dirname($cacheFile)))) { 
                @file_put_contents($cacheFile, json_encode([])); 
                error_log("SCHEMA (games.php): Saved empty schema for appID {$appId} to cache (no achievements found).");
            }
            return [];
        }
        $achievementsFromSchema = $schema_response['game']['availableGameStats']['achievements']; 
        foreach ($achievementsFromSchema as $schemaAch) {
            if (isset($schemaAch['name'])) { $gameSchemaAchievements[$schemaAch['name']] = $schemaAch; }
        }
        if (is_writable($cacheDir) || (file_exists($cacheFile) && is_writable($cacheFile)) || (!file_exists($cacheFile) && is_writable(dirname($cacheFile)))) {
            if (@file_put_contents($cacheFile, json_encode($gameSchemaAchievements)) === false) {
                error_log("SCHEMA_CACHE_WRITE_FAIL (games.php): Failed to write schema for appID {$appId} to cache: {$cacheFile}."); 
            } else { error_log("SCHEMA (games.php): Saved schema for appID {$appId} to cache."); }
        } else { error_log("SCHEMA_CACHE_NOT_WRITABLE (games.php): Cache directory '{$cacheDir}' or file '{$cacheFile}' is not writable."); }
        return $gameSchemaAchievements;
    }

    $ownedGamesData = $_SESSION['userData']['owned_games'] ?? ['game_count' => 0, 'games' => [], 'message' => 'Game data not found in session.'];
    $gamesToDisplay = [];

    if (isset($ownedGamesData['games']) && is_array($ownedGamesData['games'])) {
        foreach ($ownedGamesData['games'] as $game) {
            $gameAppId = $game['appid'] ?? null;
            if ($gameAppId) {
                $schemaData = getAndCacheGameSchema($gameAppId, $steam_api_key, 'cache/', 86400); 
                if (!empty($schemaData)) { 
                    $gamesToDisplay[] = $game; // Store the whole $game object
                }
            }
        }
    }
    $gameCountToDisplay = count($gamesToDisplay);
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
    <title>Chasing Completion - Your Games</title>
</head>
<body>
    <div id="loading-screen">
        <div class="loading-spinner"></div>
        <p>Loading your games library...</p>
    </div>

    <?php include 'navbar.php';?>

    <div id="page-content" style="visibility: hidden;">

    <?php
        // Only show search/filter and game list if there's potential for games
        if (isset($ownedGamesData['game_count']) && $ownedGamesData['game_count'] > 0 ) {
            echo "<div class='search-and-filter-container'>" .
                     "<h2 class='your_games_count'>" . htmlspecialchars($gameCountToDisplay) . " Games (with achievements)</h2>" .
                     "<div class='search-container'>" .
                         "<input type='text' id='searchInput' placeholder='Search by name...'>" . 
                         "<label for='sortOptions'>Sort By: </label>" .
                         "<select id='sortOptions'>" .
                             "<option value='default'>Default</option>" .
                             "<option value='name-asc'>Name (A-Z)</option>" .
                             "<option value='name-desc'>Name (Z-A)</option>" .
                             "<option value='playtime-desc'>Playtime (Most)</option>" .
                             "<option value='playtime-asc'>Playtime (Least)</option>" .
                             "<option value='recent-desc'>Recently Played (Most)</option>" .
                         "</select>" .
                     "</div>" .
                 "</div>";

            if ($gameCountToDisplay > 0) {
                echo "<ul class='games-list-container' id='gamesList'>";
                    foreach ($gamesToDisplay as $game) { 
                        $gameAppId = $game['appid']; 
                        $gameName = $game['name'] ?? 'Unknown Game'; 
                        $playtimeForever = $game['playtime_forever'] ?? 0;
                        $playtime2Weeks = $game['playtime_2weeks'] ?? 0; // Steam API provides this if played in last 2 weeks

                        $safeGameName = htmlspecialchars($gameName); 
                        $gameImageUrl = "https://cdn.akamai.steamstatic.com/steam/apps/{$gameAppId}/library_600x900.jpg";
                        $achievementsPageUrl = "game_achievements.php?appid=" . urlencode($gameAppId); 
                        $safeAchievementsPageUrl = htmlspecialchars($achievementsPageUrl);
                        $safeGameImageUrl = htmlspecialchars($gameImageUrl);
                        $fallbackImageUrl = 'Images/no-game-cover.png'; 

                        // ADD DATA ATTRIBUTES TO THE LIST ITEM (<li>)
                        echo "<li class='game-list-item' 
                                 data-name='" . $safeGameName . "' 
                                 data-appid='" . $gameAppId . "'
                                 data-playtime-forever='" . $playtimeForever . "'
                                 data-playtime-2weeks='" . $playtime2Weeks . "'>" .
                                "<a href='" . $safeAchievementsPageUrl . "' class='game-library-image-link' title='View achievements for " . $safeGameName . "'>" .
                                    "<img src='" . $safeGameImageUrl . "' " .
                                         "alt='" . $safeGameName . " Library Capsule' " .
                                         "class='game-library-image' " .
                                         "onerror=\"this.onerror=null; this.src='" . $fallbackImageUrl . "'; this.classList.add('game-library-image-missing');\">" .
                                "</a>" .
                             "</li>";
                    }
                echo "</ul>";
                echo "<p id='no-games-found'>No games match your search.</p>"; // Message for no search results
            } elseif ($ownedGamesData['game_count'] > 0 && $gameCountToDisplay === 0) {
                 // This case is if they own games, but NONE have achievements
                echo "<p style='text-align:center; padding: 20px;'>You own " . htmlspecialchars($ownedGamesData['game_count']) . " game(s), but none of them appear to have achievements that could be loaded at this time.</p>";
            }
        } elseif (!empty($ownedGamesData['message'])) {
            echo "<p style='text-align:center; padding: 20px;'>" . htmlspecialchars($ownedGamesData['message']) . "</p>";
        } else {
            echo "<p style='text-align:center; padding: 20px;'>You do not seem to own any games, or your game library is private.</p>";
        }
    ?>
    </div> 

    <?php include 'footer.php'; ?>

    <script src="javascript/bar-menu.js"></script>
    <script>
        window.addEventListener('load', function() {
            const loadingScreen = document.getElementById('loading-screen');
            const pageContent = document.getElementById('page-content');
            if (loadingScreen) {
                loadingScreen.style.display = 'none';
            }
            if (pageContent) {
                pageContent.style.visibility = 'visible'; 
            }

            const searchInput = document.getElementById('searchInput');
            const sortOptions = document.getElementById('sortOptions');
            const gamesList = document.getElementById('gamesList');
            const noGamesFoundMessage = document.getElementById('no-games-found');

            let originalGameItems = gamesList ? Array.from(gamesList.getElementsByClassName('game-list-item')) : [];

            // --- SEARCH FUNCTIONALITY ---
            if (searchInput && gamesList) {
                searchInput.addEventListener('keyup', function() {
                    const searchTerm = searchInput.value.toLowerCase();
                    let visibleGamesCount = 0;
                    originalGameItems.forEach(item => {
                        const gameName = item.getAttribute('data-name').toLowerCase();
                        if (gameName.includes(searchTerm)) {
                            item.style.display = ''; // Or 'flex', 'block' depending on your li styling
                            visibleGamesCount++;
                        } else {
                            item.style.display = 'none';
                        }
                    });
                    // Show/hide "no games found" message
                    if (noGamesFoundMessage) {
                        noGamesFoundMessage.style.display = visibleGamesCount === 0 && searchTerm !== '' ? 'block' : 'none';
                    }
                     // Update game count (optional)
                    const gameCountHeader = document.querySelector('.your_games_count');
                    if (gameCountHeader) {
                        // This is a bit simplistic, as it doesn't account for "with achievements" part if you want to be precise
                        // It will show "X Games (with achievements)" where X is the filtered count
                        // A better approach for dynamic count would be more complex, involving re-evaluating original list.
                        // For now, let's keep it simple or remove this dynamic update if it's confusing.
                        // gameCountHeader.textContent = `${visibleGamesCount} Games (with achievements)`;
                    }
                });
            }

            // --- SORT FUNCTIONALITY ---
            if (sortOptions && gamesList) {
                sortOptions.addEventListener('change', function() {
                    const sortBy = sortOptions.value;
                    // Get currently VISIBLE items for sorting, respecting the current search filter
                    let itemsToSort = Array.from(gamesList.getElementsByClassName('game-list-item')).filter(item => item.style.display !== 'none');

                    if (sortBy === 'default') {
                        // Re-append in original order (respecting search filter)
                        // This requires us to re-filter the originalGameItems
                        const searchTerm = searchInput ? searchInput.value.toLowerCase() : "";
                        itemsToSort = originalGameItems.filter(item => {
                            const gameName = item.getAttribute('data-name').toLowerCase();
                            return searchTerm === "" || gameName.includes(searchTerm);
                        });
                    } else {
                         itemsToSort.sort((a, b) => {
                            let valA, valB;
                            switch (sortBy) {
                                case 'name-asc':
                                    valA = a.getAttribute('data-name').toLowerCase();
                                    valB = b.getAttribute('data-name').toLowerCase();
                                    return valA.localeCompare(valB);
                                case 'name-desc':
                                    valA = a.getAttribute('data-name').toLowerCase();
                                    valB = b.getAttribute('data-name').toLowerCase();
                                    return valB.localeCompare(valA);
                                case 'playtime-desc':
                                    valA = parseInt(a.getAttribute('data-playtime-forever'));
                                    valB = parseInt(b.getAttribute('data-playtime-forever'));
                                    return valB - valA; // Descending
                                case 'playtime-asc':
                                    valA = parseInt(a.getAttribute('data-playtime-forever'));
                                    valB = parseInt(b.getAttribute('data-playtime-forever'));
                                    return valA - valB; // Ascending
                                case 'recent-desc': // Most recently played (highest playtime_2weeks)
                                    valA = parseInt(a.getAttribute('data-playtime-2weeks'));
                                    valB = parseInt(b.getAttribute('data-playtime-2weeks'));
                                    return valB - valA; // Descending
                                default:
                                    return 0;
                            }
                        });
                    }
                    // Re-append sorted items
                    itemsToSort.forEach(item => gamesList.appendChild(item));
                });
            }
        });
    </script>
</body>
</html>