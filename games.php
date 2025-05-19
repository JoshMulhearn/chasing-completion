<?php
    session_start(); 

    //if a user is not logged in they get redirected to the error page
    //this page lets them know that they do not have access as they are not logged in
    if(!$_SESSION['logged_in']){
        header("Location: Error.php");
        exit(); 
    }

    // Ensure userData exists in the session
    if (!isset($_SESSION['userData'])) {
        // This case might happen if the session got corrupted or process-openId didn't complete successfully
        // Log this error and redirect
        error_log("Error in games.php: \$_SESSION['userData'] is not set.");
        header("Location: Error.php?code=session_data_missing");
        exit();
    }

    // Access variables from the session
    $username = $_SESSION['userData']['name']; 
    $avatar = $_SESSION['userData']['avatar'];     

    $ownedGamesData = $_SESSION['userData']['owned_games'] ?? ['game_count' => 0, 'games' => [], 'message' => 'Game data not found in session.'];

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
    <title>Chasing Completion - Your Games</title>
</head>
<body>
    <?php include 'navbar.php';?>

<?php
    //only show games list if there is valid game data
    if (isset($ownedGamesData['game_count']) && $ownedGamesData['game_count'] > 0 && !empty($ownedGamesData['games'])) {
        
        //search and filter container
        echo "<div class='search-and-filter-container'>" .
                 "<h2 class='your_games_count'>" . htmlspecialchars($ownedGamesData['game_count']) . " Games</h2>" . // Added htmlspecialchars
                 "<div class='search-container'>" .
                     "<input type='text' placeholder='Search..'>" .
                     "<p>Genre</p>" .
                     "<p>Theme</p>" .
                 "</div>" .
             "</div>"; //genre and theme are currently placeholders

        // Start the unordered list for games
        echo "<ul class='games-list-container'>"; // Assuming this was intended to be outside the search container but before the loop

        //loop through every game
        foreach ($ownedGamesData['games'] as $game) {

            //get relevant game data and sanitize it
            $gameAppId = $game['appid'] ?? 'N/A';
            $gameName = $game['name'] ?? 'Unknown Game';
            $playtimeForever = $game['playtime_forever'] ?? 0;
            $playtimeHours = round($playtimeForever / 60, 1);

            // Sanitize all dynamic data before including in HTML
            $safeGameName = htmlspecialchars($gameName);
            $safeGameAppId = htmlspecialchars($gameAppId);
            $safePlaytimeHours = htmlspecialchars($playtimeHours);

            //game image is empty as it is filled using the following if statement
            $gameImageUrl = "";
            $achievementsPageUrl = "#"; // Default link if no appid

            if ($gameAppId !== 'N/A') {
                $gameImageUrl = "https://cdn.akamai.steamstatic.com/steam/apps/{$gameAppId}/library_600x900.jpg";
                $achievementsPageUrl = "game_achievements.php?appid=" . urlencode($gameAppId); 
            }
            
            $safeAchievementsPageUrl = htmlspecialchars($achievementsPageUrl); // Sanitize the final URL for href
            $safeGameImageUrl = htmlspecialchars($gameImageUrl); // Sanitize image URL for src

            $imageHtml = "";
            if ($gameImageUrl) { 
                $imageHtml = "<img src='" . $safeGameImageUrl . "' " .
                                 "alt='" . $safeGameName . " Library Capsule' " .
                                 "class='game-library-image' " .
                                 "onerror=\"this.style.display='none'; this.nextSibling.style.display='inline-block'; console.error('Failed to load game image: " . $safeGameImageUrl . " for AppID: " . $safeGameAppId . "');\">" .
                             "<span class='img-error-placeholder' style='display:none;'>IMG</span>"; 
            } else {
                $imageHtml = "<span class='img-error-placeholder'>N/A</span>";
            }


            $listItem = "
                <li class='game-list-item'>" .
                    "<a href='" . $safeAchievementsPageUrl . "' class='game-library-image-link' title='View achievements for " . $safeGameName . "'>" .
                        $imageHtml .
                    "</a>" .
                    "<div class='game-details'>" .
                        "<span class='font-medium text-lg'>" . $safeGameName . "</span>" .
                        "<span class='text-sm text-gray-500'>AppID: " . $safeGameAppId . "</span>" .
                        "<span class='text-sm text-gray-600 mt-1'>Playtime: " . $safePlaytimeHours . " hours</span>" .
                    "</div>" .
                "</li>";
            
            echo $listItem;
        }
        
        echo "</ul>"; // Close the unordered list
    } elseif (!empty($ownedGamesData['message'])) {
        echo "<p class='text-center text-gray-600 mt-6'>" . htmlspecialchars($ownedGamesData['message']) . "</p>";
    } else {
        echo "<p class='text-center text-gray-600 mt-6'>You do not seem to own any games, or your game library is private.</p>";
    }
?>

    <?php include 'footer.php'; ?>

    <script src="javascript/bar-menu.js"></script>
</body>
</html>