
<?php


    //function to get game schema, with caching
    function getAndCacheGameSchema($appId, $steam_api_key, $cacheDir = 'cache/', $cacheDuration = 86400) // 86400 seconds = 24 hours
    { 
        $cacheFile = rtrim($cacheDir, '/') . '/schema_' . $appId . '.json';
        $gameSchemaAchievements = [];

        if (!is_dir($cacheDir)) 
        {
            @mkdir($cacheDir, 0775, true); //try to create cache directory if it doesn't exist
        }

        //try to load from cache. checks if the cache exists and is within the cacheduration of 24 hours and is readable
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheDuration) && is_readable($cacheFile)) 
        {
            $cachedContent = @file_get_contents($cacheFile); //reads the cache file
            if ($cachedContent !== false)  //if reading was successful
            {
                $cachedData = @json_decode($cachedContent, true); //decode like in openId
                if (is_array($cachedData))  //if the decoding worked and is array
                {
                    error_log("SCHEMA: Loaded schema for appID {$appId} from cache."); //misleading as this is not an error. logs that the cache was successfully loaded
                    return $cachedData; //returns cache data
                } 
                else 
                {
                    error_log("SCHEMA_CACHE_CORRUPT: Cache file for appID {$appId} is not valid JSON."); //log if data is corruptt
                }
            } 
            else 
            {
                error_log("SCHEMA_CACHE_READ_FAIL: Could not read cache file for appID {$appId}."); //log if read failed
            }
        }

        error_log("SCHEMA: Cache miss or expired for appID {$appId}. Fetching from API."); //fetching cache from api
        $schema_url = "https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/?key={$steam_api_key}&appid={$appId}&l=english"; //get game schema api call
        $schema_raw_response = @file_get_contents($schema_url); //read schema response

        if ($schema_raw_response === false)  //if schema response fails
        {
            $last_php_error = error_get_last(); //get last error
            error_log("SCHEMA_API_CALL_FAIL: Could not fetch schema for appID {$appId}. URL: {$schema_url}. PHP Error: " . ($last_php_error['message'] ?? 'N/A'));
            return [];
        }

        $schema_response = @json_decode($schema_raw_response, true); //decode schema response

        if ($schema_response === null || !isset($schema_response['game']['availableGameStats']['achievements'])) //checks if decoding failed or no achievement data
        {
            error_log("SCHEMA_API_DECODE_FAIL or NO_ACHIEVEMENTS_IN_SCHEMA: Invalid response or no achievements in schema for appID {$appId}. Raw (first 500char): " . substr($schema_raw_response, 0, 500));
            if (is_writable($cacheDir) || is_writable($cacheFile)) //check if an empty cache can be written
            { 
                @file_put_contents($cacheFile, json_encode([])); //put the result in the cache
            }
            return [];
        }

        $achievementsFromSchema = $schema_response['game']['availableGameStats']['achievements']; //get achievements array from api response
        foreach ($achievementsFromSchema as $schemaAch)  //re-index array to have the game name as the key
        {
            if (isset($schemaAch['name']))  //'name' is the apiname
            {
                $gameSchemaAchievements[$schemaAch['name']] = $schemaAch;
            }
        }

        if (is_writable($cacheDir) || (file_exists($cacheFile) && is_writable($cacheFile)) || (!file_exists($cacheFile) && is_writable(dirname($cacheFile)))) 
        {
            if (@file_put_contents($cacheFile, json_encode($gameSchemaAchievements)) === false) //tries to write data to cache file
            {
                error_log("SCHEMA_CACHE_WRITE_FAIL: Failed to write schema for appID {$appId} to cache file: {$cacheFile}. Check permissions."); //failed
            } 
            else 
            {
                error_log("SCHEMA: Saved schema for appID {$appId} to cache."); //success
            }
        } 
        else 
        {
            error_log("SCHEMA_CACHE_NOT_WRITABLE: Cache directory '{$cacheDir}' or file '{$cacheFile}' is not writable."); //cache is not writeable
        }
        
        return $gameSchemaAchievements;
    }

    session_start();

    //username and avatar for navbar
    $username = $_SESSION['userData']['name']; 
    $avatar = $_SESSION['userData']['avatar'];
    //steam-api-key
    require_once __DIR__ . '/steam-api-key.php';
    if (!defined('steam_api_key') || empty(steam_api_key)) 
    {
        die("Error: Steam API key is not defined or empty. Please check steam-api-key.php");
    }
    $steam_api_key = steam_api_key;

    //session data
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) 
    {
        header("Location: Error.php?code=not_logged_in");
        exit();
    }
    if (!isset($_SESSION['userData']['steam_id'], $_SESSION['userData']['name'])) 
    {
        error_log("Error in game_achievements.php: UserData (steam_id or name) not fully set in session.");
        header("Location: Error.php?code=session_data_incomplete");
        exit();
    }
    $steamID64 = $_SESSION['userData']['steam_id'];
    $username_session = $_SESSION['userData']['name'];
    $avatar_session = $_SESSION['userData']['avatar'] ?? 'default_avatar.png';


   
    if (!isset($_GET['appid']) || !is_numeric($_GET['appid'])) 
    {
        die("Error: Game AppID is missing or invalid in the URL.");
    }
    $appId = filter_var($_GET['appid'], FILTER_VALIDATE_INT); //appid validated as an integer
    if ($appId === false || $appId <= 0) 
    {
        die("Error: Game AppID is invalid.");
    }

    //all the variables
    $playerAchievementsData = null; //a players achievement status
    $gameSchemaData = [];         //all the global achievment data including icon
    $gameName = 'Game';           //this gets overwritten by the actual game name
    $apiErrorMessage = '';
    $gameCoverImageUrl = "https://placehold.co/300x450/1B2838/E0E0E0?text=Game+Cover&fontsize=20"; //this also gets overwritten by the actual game cover image
    $gameDeveloper = 'N/A'; //these three would need extra web scraping and will probably be removed
    $gamePublisher = 'N/A';
    $gameGenre = 'N/A';
    $completedAchievementsCount = 0; //how many games a user has completed
    $totalAchievementsForGame = 0; //the total number of achievements for the game
    $completionPercentage = 0; //the users completion percentage

    //gets the owmed game info
    if (isset($_SESSION['userData']['owned_games']['games']) && is_array($_SESSION['userData']['owned_games']['games'])) 
    {
        foreach ($_SESSION['userData']['owned_games']['games'] as $ownedGame) {
            if (isset($ownedGame['appid'], $ownedGame['name']) && $ownedGame['appid'] == $appId) 
            {
                $gameName = htmlspecialchars($ownedGame['name']); //the games name is overwritten
                $gameCoverImageUrl = "https://cdn.akamai.steamstatic.com/steam/apps/{$appId}/library_600x900.jpg"; //the cover image is overwritten
                // Note: Developer, Publisher, Genre are typically not in 'owned_games' from GetOwnedGames API.
                // This info usually comes from store details API or GetSchemaForGame if available there.
                break;
            }
        }
    }

    //fetch the game schema
    $gameSchemaData = getAndCacheGameSchema($appId, $steam_api_key);
    if (!empty($gameSchemaData)) //if schema was successfully loaded
    {
        $totalAchievementsForGame = count($gameSchemaData); //total achievements set based on schema 

    } 
    else 
    {
        error_log("Warning: Could not load game schema for appID {$appId}. Display will use limited data.");
    }

    //get the players achievement status
    $playerAchievements_url = "https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/?key={$steam_api_key}&steamid={$steamID64}&appid={$appId}&l=english";
    $player_raw_response = @file_get_contents($playerAchievements_url);

    if ($player_raw_response === false) //if failed
    {
        $last_php_error = error_get_last();
        $apiErrorMessage = "Could not retrieve your achievements from Steam. Your profile might be private, game has no stats, or a connection issue occurred.";
        error_log("PLAYER_ACH_API_CALL_FAIL: appid={$appId}, steamid={$steamID64}. PHP Error: " . ($last_php_error['message'] ?? 'N/A'));
    } 
    else 
    {
        $player_ach_response = json_decode($player_raw_response, true); //decode response

        if ($player_ach_response === null) //if decode failed
        {
            $apiErrorMessage = "Received invalid response format from Steam for your achievements.";
            error_log("PLAYER_ACH_API_DECODE_FAIL: appid={$appId}, steamid={$steamID64}. Raw: " . substr($player_raw_response, 0, 500));
        } 
        elseif (isset($player_ach_response['playerstats']['success']) && $player_ach_response['playerstats']['success'] === true) //if decode was successful
        {
            $playerAchievementsData = $player_ach_response['playerstats']['achievements'] ?? []; //assign player achievements or empty array if there are none

            if (isset($player_ach_response['playerstats']['gameName']) && ($gameName === 'Game' || $gameName === ''))  //if the name was not recieved try get it from this response
            {
                $gameName = htmlspecialchars($player_ach_response['playerstats']['gameName']);
            }

            if (!empty($playerAchievementsData)) //if user has achievement data for this game
            {
                foreach ($playerAchievementsData as $playerAch) //for each of the achievements
                {
                    if (isset($playerAch['achieved']) && $playerAch['achieved'] == 1) //if the achievement has been achieved its value is 1 and it is added to the count
                    {
                        $completedAchievementsCount++;
                    }
                }
                //error message for schema not loading
                if ($totalAchievementsForGame === 0) {
                    $apiErrorMessage = "Schema load unsuccessful";
                }
            } 
            elseif (!isset($player_ach_response['playerstats']['error'])) 
            {
                //success true, but achievements array is empty, and no specific error from API
                if ($totalAchievementsForGame > 0) {
                    //if game has achievements from schema, player just has none reported by this API for them.
                } 
                else 
                {
                    $apiErrorMessage = "This game reports no achievements for you, or the game itself has no achievements.";
                }
            }
            if (isset($player_ach_response['playerstats']['error']) && empty($playerAchievementsData) ) 
            {
                $apiErrorMessage = "Steam API Error: " . htmlspecialchars($player_ach_response['playerstats']['error']);
            }

        } 
        else //playerstats success is false or not set
        { 
            $apiErrorMessage = "Steam API reported an issue retrieving your achievements.";
            if (isset($player_ach_response['playerstats']['error'])) 
            {
                $apiErrorMessage .= " Message: " . htmlspecialchars($player_ach_response['playerstats']['error']);
            }
            error_log("PLAYER_ACH_API_NO_SUCCESS: appid={$appId}, steamid={$steamID64}. Response: " . substr($player_raw_response, 0, 500));
        }
    }

    //completion percentage
    if ($totalAchievementsForGame > 0) { //avoid division by 0
        $completionPercentage = round(($completedAchievementsCount / $totalAchievementsForGame) * 100); //amount of games the user has completed divided by the total number of achievements and times by 100
    }

    //leaderboard data placeholder (fetch from database)
    $leaderboardEntries = [
        // ['username' => 'Player1', 'completion' => 95],
        // ['username' => 'SomeLongUserNameToTestOverflow', 'completion' => 88],
        // ['username' => 'Player3', 'completion' => 80],
    ];

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

</head>
<body>
    <?php include 'navbar.php'?>
    
    <div class="page-header-container"> <!--button for going back to games list and the name of the game-->
        <a href="games.php" class="back-button">Back</a>
        <h2 class="page-title">Achievements for <?php echo $gameName; ?></h2>
    </div>
    
    <?php if (!empty($apiErrorMessage) && (empty($playerAchievementsData) && empty($gameSchemaData)) ): // Show general API error only if really no data can be shown ?>
        <p class="api-error-message"><?php echo $apiErrorMessage; ?></p>
    <?php endif; ?>

    <div class="achievements-page-layout">
        <div class="game-info-column">
            
            <img src="<?php echo htmlspecialchars($gameCoverImageUrl); ?>" alt="<?php echo $gameName; ?> Cover Art" class="game-cover" 
                 onerror="this.onerror=null; this.src='https://placehold.co/300x450/1B2838/66C0F4?text=Cover+Missing&fontsize=18';">
            <h2><?php echo $gameName; ?></h2>
            <p><strong>AppID:</strong> <?php echo $appId; ?></p>
            <p><strong>Developer:</strong> <?php echo $gameDeveloper; ?></p>
            <p><strong>Publisher:</strong> <?php echo $gamePublisher; ?></p>
            <p><strong>Genre:</strong> <?php echo $gameGenre; ?></p>

            <div class="game-completion-stats">
                <p><strong>Your Progress:</strong></p>
                <p><?php echo $completedAchievementsCount; ?> / <?php echo $totalAchievementsForGame > 0 ? $totalAchievementsForGame : 'N/A'; ?> Achievements</p>
                <p><?php echo "Completion: " . $completionPercentage . "%"; ?></p>
            </div>
        </div>

        <div class="achievements-column">
            <h2 class="ach-colum-title">Achievements</h2>
             <?php if (!empty($apiErrorMessage) && (!empty($playerAchievementsData) || !empty($gameSchemaData)) ): // Show contextual error if data is partially loaded but there was still an API issue reported ?>
                <p class="api-error-message"><?php echo $apiErrorMessage; ?></p>
            <?php endif; ?>

            <?php 
                $achievementsToIterate = []; //initialise empty array
                if (!empty($gameSchemaData)) //if game scheme data isnt empty
                {
                    $achievementsToIterate = $gameSchemaData; //prefer to use schema data if available
                } 
                elseif (!empty($playerAchievementsData)) 
                {
                    //fallback if no schema data use the players data
                    //re-key by apiname for consistency
                    foreach($playerAchievementsData as $pAch) {
                        if(isset($pAch['apiname'])) {
                            $achievementsToIterate[$pAch['apiname']] = $pAch;
                        }
                    }
                }
            ?>

            <?php if (!empty($achievementsToIterate)): ?> <!--the $achievementsToIterate variable is filled with the appropriate data-->
                <ul class="achievements-list"> 
                    <?php foreach ($achievementsToIterate as $apiName => $baseAchData): //for every achievement?> 
                        <?php
                            //player's specific status for this achievement, if it has been unlocked or not
                            $playerAchievedStatus = null; //variables to be overwritten if data is availablke
                            $playerUnlockTime = null;
                            if (!empty($playerAchievementsData)) 
                            {
                                foreach ($playerAchievementsData as $playerSpecificAch) //for every achievement
                                {
                                    if (isset($playerSpecificAch['apiname']) && $playerSpecificAch['apiname'] === $apiName) 
                                    {
                                        $playerAchievedStatus = (isset($playerSpecificAch['achieved']) && $playerSpecificAch['achieved'] == 1); //if achieved flag is set to 1 in player data it is achieved
                                        if ($playerAchievedStatus && isset($playerSpecificAch['unlocktime']) && $playerSpecificAch['unlocktime'] > 0) //unlock time exists and is greater than 0
                                        {
                                            $playerUnlockTime = date('F j, Y, g:i a', $playerSpecificAch['unlocktime']); //display unlock time formatted to be readable
                                        }
                                        break; 
                                    }
                                }
                            }
                            
                            $isAchieved = ($playerAchievedStatus === true); //if the achieved status is true this variable is set

                            
                            $achName = htmlspecialchars($baseAchData['displayName'] ?? ($baseAchData['name'] ?? $apiName)); //achievement display name. or sets to default value or the apiname
                            $achDesc = htmlspecialchars($baseAchData['description'] ?? 'No description available.'); //gets achievement description
                            
                            $iconUrl = '';
                            //sometimes games have different achievement icons for when an achievement is unlocked or not
                            if ($isAchieved && !empty($baseAchData['icon']))  //if achievement is achieved it is normal icon
                            { 
                                $iconUrl = htmlspecialchars($baseAchData['icon']); 
                            } 
                            elseif (!$isAchieved && !empty($baseAchData['icongray'])) //else if not achieved gray icon
                            { 
                                $iconUrl = htmlspecialchars($baseAchData['icongray']); 
                            } 
                            elseif (!empty($baseAchData['icon'])) //if the achievement doesnt have different types just default to the base icon
                            { 
                                $iconUrl = htmlspecialchars($baseAchData['icon']);
                            }
                        ?>

                        <li class="achievement-item <?php echo $isAchieved ? 'achieved' : ($playerAchievedStatus === null && !empty($gameSchemaData) ? 'unknown-status' : 'not-achieved') ; ?>">
                            <?php if (!empty($iconUrl)): ?>
                                <img src="<?php echo $iconUrl; ?>" alt="<?php echo $achName; ?>" class="achievement-icon" 
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="achievement-icon-placeholder" style="display:none;">ICON ERR</div> <?php else: ?>
                                <div class="achievement-icon-placeholder">NO ICON</div>
                            <?php endif; ?>
                            <div class="achievement-details">
                                <h4><?php echo $achName; ?></h4>
                                <p><?php echo $achDesc; ?></p>
                                <?php if ($playerUnlockTime): ?>
                                    <p class="unlock-time">Unlocked: <?php echo $playerUnlockTime; ?></p>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif (empty($apiErrorMessage)): // Only show this if no API error AND no data to iterate ?>
                <p style="text-align:center; padding: 20px;">This game has no achievements to display, or they could not be loaded.</p>
            <?php endif; ?>
        </div>

        <div class="right-sidebar-column">
            <div class="game-leaderboard-panel">
                <h2>Game Leaderboard</h2>
                <?php if (!empty($leaderboardEntries)): ?>
                    <ol class="leaderboard-list">
                        <?php foreach ($leaderboardEntries as $index => $entry): ?>
                            <li class="leaderboard-item">
                                <span class="rank">#<?php echo $index + 1; ?></span>
                                <span class="username" title="<?php echo htmlspecialchars($entry['username']); ?>"><?php echo htmlspecialchars($entry['username']); ?></span>
                                <span class="completion"><?php echo $entry['completion']; ?>%</span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <div class="leaderboard-list">
                        <p>Leaderboard data is not yet available.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="admin-actions-panel">
                <div class="admin-btns">
                    <a href="#">Request Description</a>
                    <a href="#">Write Description</a>
                </div>
            </div>
        </div>
    </div>

    
    <?php include 'footer.php'?>
</body>

    <script src="javascript/bar-menu.js"></script> 
</html>
