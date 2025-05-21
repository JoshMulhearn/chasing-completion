<?php
    session_start();


    require_once __DIR__ . '/steam-api-key.php';
    $steam_api_key = steam_api_key; //api key is taken from a config file (steam-api-key) as legally it is not supposed to be shared

    $username = $_SESSION['userData']['name']; 
    $avatar = $_SESSION['userData']['avatar'];

    //vheck if the user is logged in
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: Error.php"); // Or your login page
        exit();
    }

    //rnsure userData and SteamID exist in the session
    if (!isset($_SESSION['userData']['steam_id'])) {
        error_log("Error in game_achievements.php: SteamID not found in session.");
        header("Location: Error.php?code=steamid_missing"); // Or your login page
        exit();
    }
    $steamID64 = $_SESSION['userData']['steam_id'];
    $username = $_SESSION['userData']['name'] ?? 'Player';

    //get the AppID from the URL query parameter
    if (!isset($_GET['appid']) || !is_numeric($_GET['appid'])) { //check if it exists and if its a number
        //appID is missing or not numeric show error
        die("Error: Game AppID is missing or invalid.");
    }
    $appId = filter_var($_GET['appid'], FILTER_VALIDATE_INT); //appId is treated as an integer

    //get the players achievements
    $achievementsData = null; //this is going to hold the list of achievements
    $gameName = 'Game'; //default game name which is updated by api
    $apiErrorMessage = '';
    $gameCoverImageUrl = "https://placehold.co/300x450/2A475E/E0E0E0?text=Game+Cover"; // Placeholder
    $completedAchievementsCount = 0; //implement for leaderboard!
    $totalAchievementsForGame = 0; //this will be the total number of achievements for the game (total achievmeent count already does this no?)
    $completionPercentage = 0; //implement for leaderboard!

    if (isset($_SESSION['userData']['owned_games']['games']) && is_array($_SESSION['userData']['owned_games']['games'])) 
    {
     
        foreach ($_SESSION['userData']['owned_games']['games'] as $ownedGame) 
        {
           
            if (isset($ownedGame['appid'], $ownedGame['name']) && $ownedGame['appid'] == $appId) 
            {
                $gameName = htmlspecialchars($ownedGame['name']);
                $gameCoverImageUrl = "https://cdn.akamai.steamstatic.com/steam/apps/{$appId}/library_600x900.jpg";
               
                break;
            }
        }
    }

    if (!empty($steam_api_key)) //check api key is available
    {
        //need to handle differently to process-openId as there are cases where a game has no achievements and this would cause errors
        $achievements_url = "https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/?key={$steam_api_key}&steamid={$steamID64}&appid={$appId}&l=english"; //achievements url created from api
        $raw_response = @file_get_contents($achievements_url);

        if ($raw_response === false) //file_get_contents failed
        {
            $last_php_error = error_get_last(); //gets the last error and assigns it to variable. Api key was showing in error message on screen (not allowed)
            $apiErrorMessage = "Could not retrieve achievements from Steam. The game may not have achievements, or there was a connection issue.";
        }
        else //file_get_contents was successful 
        {
            $achievements_response = json_decode($raw_response, true); //json_decode like in process-openId

            if ($achievements_response === null) //json decoding failed
            {
                $apiErrorMessage = "Recieved invalid resposne format from Steam API";
            } 
            elseif (isset($achievements_response['playerstats']['success']) && $achievements_response['playerstats']['success'] === true)
            //api call was successful
            {
                
                $achievementsData = $achievements_response['playerstats']['achievements'] ?? []; //the achievements
                
                $totalAchievementsCount = count($achievementsData); //calculate the total number of achievements
                //achievementsData is empty and achievements response is not set
                if (empty($achievementsData) && !isset($achievements_response['playerstats']['error']))
                {
                    $apiErrorMessage = "There are no achievements for this game.";
                }
            }
            else 
            {
                //unexpected issue
                $apiErrorMessage = "Unexpected issue";
            }
        }
    }
    else 
    {
        $apiErrorMessage = "Steam API key not configured";
    }

    //error handling 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Genos:ital,wght@0,100..900;1,100..900&family=Orbitron:wght@400..900&family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/style.css"> 
    <title><?php echo htmlspecialchars($gameName); ?> - Achievements</title> <!--games name is put in title of page with htmlspecialchars to ensure security-->
</head>
<body>
    <?php 
        include 'navbar.php'; // Assuming your navbar.php is in the same directory
    ?>
    <!--decided not to use this-->
    <!--<?php //if (!empty($apiErrorMessage)): ?>
        <p class="api-error-message"><?//php echo $apiErrorMessage; ?></p>
    <?php// endif; ?>   
    -->
    <div class="achievements-page-layout">
        <div class="game-info">
            <img src="<?php echo htmlspecialchars($gameCoverImageUrl); ?>" alt="<?php echo $gameName; ?> Cover Art" class="game-cover" onerror="this.onerror=null; this.src='https://cdn.akamai.steamstatic.com/steam/apps/{$gameAppId}/library_600x900.jpg';">
            <p><?php echo $gameName?>  |  Title</p>
            <?php 
                if (isset($achievements_response['playerstats']['success']) && $achievements_response['playerstats']['success'] === true)
                {
                    echo "<p>" . $totalAchievementsCount . "  |  Achievements</p>";
                    echo "<p>" . $completionPercentage . "%" . "  |  Your Progress</p>";
                }
                else
                {
                    echo "<p>N/A</p>";
                    echo "<p>N/A</p>";

                }
            ?>

        </div>

        <div class="achievements">
            <ul>
                <?php foreach ($achievementsData as $achievement): ?>
                        <?php
                            $apiNameForIcon = $achievement['apiname'] ?? null; 
                            $achName = htmlspecialchars($achievement['displayName'] ?? $achievement['name'] ?? 'Unnamed Achievement'); // Steam uses displayName or name
                            $achDesc = htmlspecialchars($achievement['description'] ?? 'No description available.');
                            $isAchieved = (isset($achievement['achieved']) && $achievement['achieved'] == 1);
                            $iconUrl = '';
                            $unlockTime = $isAchieved && isset($achievement['unlocktime']) ? date('F j, Y, g:i a', $achievement['unlocktime']) : null;

                            if($apiNameForIcon) 
                            {
                                $iconUrl = "https://cdn.cloudflare.steamstatic.com/steamcommunity/public/images/apps/{$appId}/{$apiNameForIcon}.jpg";
                                $iconUrl = htmlspecialchars($iconUrl); 
                            }
                        ?>
                            <li class="achievement-item <?php echo $isAchieved ? 'achieved' : 'not-achieved'; ?>">
                                <?php if (!empty($iconUrl)): ?>
                                    <img src="<?php echo $iconUrl; ?>" alt="<?php echo $achName; ?> Icon" class="achievement-icon" 
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"> <div class="achievement-icon-placeholder" style="width: 64px; height: 64px; background-color: #111; display:none; align-items:center; justify-content:center; font-size:10px; color:#555; border-radius: 3px; margin-right: 15px;">NO ICON</div>
                                <?php else: ?>
                                    <div class="achievement-icon-placeholder" style="width: 64px; height: 64px; background-color: #111; display:flex; align-items:center; justify-content:center; font-size:10px; color:#555; border-radius: 3px; margin-right: 15px;">NO ICON</div>
                                <?php endif; ?>
                                <div class="achievement-details">
                                    <h4><?php echo $achName; ?></h4>
                                    <p><?php echo $achDesc; ?></p>
                                    <?php if ($unlockTime): ?>
                                        <p class="unlock-time">Unlocked: <?php echo $unlockTime; ?></p>
                                    <?php endif; ?>
                                </div>
                            </li>
                    <?php endforeach; ?>
            </ul>
        </div>

        <div class="game-leaderboard">

        </div>

        <div class="admin-requests">
        </div>
    </div>
    




    <?php 
        include 'footer.php';
    ?>
    </body>
    <script src="javascript/bar-menu.js"></script>
</html>
