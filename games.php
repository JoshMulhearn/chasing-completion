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
                echo "
                <div class='search-and-filter-container'>
                    <h2 class='your_games_count'>" . $ownedGamesData['game_count'] . " Games</h2>
                    <div class='search-container'>
                        <input type='text' placeholder='Search..'>
                        <p>Genre</p>
                        <p>Theme</p>
                    </div>
                </div>"; //genre and theme are currently placeholders


                //loop through every game
                foreach ($ownedGamesData['games'] as $game) {

                    //get relevant game data
                    $gameAppId = $game['appid'] ?? 'N/A';
                    $gameName = $game['name'] ?? 'Unknown Game';
                    $playtimeForever = $game['playtime_forever'] ?? 0;
                    $playtimeHours = round($playtimeForever / 60, 1);

                    //game image is empty as it is filled using the following if statement
                    $gameImageUrl = "";
                    $achievementsPageUrl = "#"; // Default link if no appid

                    // Use the standardized Steam CDN URL for the library capsule image
                    // This relies only on the appid
                    if ($gameAppId !== 'N/A') {
                        $gameImageUrl = "https://cdn.akamai.steamstatic.com/steam/apps/{$gameAppId}/library_600x900.jpg";
                        // Construct the URL for the game's achievement page, passing the appid
                        $achievementsPageUrl = "game_achievements.php?appid=" . urlencode($gameAppId);
                    }

                    
                    //image is wrapped in a tag because clicking it will link to that games achievements
                    echo "<a href='" . htmlspecialchars($achievementsPageUrl) . "'title='View achievements for " . htmlspecialchars($gameName) . "'>";
                    if ($gameImageUrl) { 
                        echo "<img src='" . htmlspecialchars($gameImageUrl) . "' 
                                   alt='" . htmlspecialchars($gameName) . " Library Capsule' 
                                   class='game-library-image'
                                   onerror=\"this.style.display='none'; this.nextSibling.style.display='inline-block'; console.error('Failed to load game image: " . htmlspecialchars($gameImageUrl) . " for AppID: " . htmlspecialchars($gameAppId) . "');\">";
                        // This span is a placeholder if the image fails to load, but it's inside the link
                        echo "<span class='img-error-placeholder' style='display:none;'>IMG</span>"; 
                    } else {
                        // This block executes if $gameImageUrl couldn't be formed (e.g., no AppID)
                        // The placeholder itself is part of the link
                        echo "<span class='img-error-placeholder'>N/A</span>";
                    }
                    echo "</a>"; 

                    echo "<div class='game-details'>"; 
                    echo "<span class='font-medium text-lg'>" . htmlspecialchars($gameName) . "</span>"; 
                    echo "<span class='text-sm text-gray-500'>AppID: " . htmlspecialchars($gameAppId) . "</span>";
                    echo "<span class='text-sm text-gray-600 mt-1'>Playtime: " . htmlspecialchars($playtimeHours) . " hours</span>";
                    echo "</div>";
                    echo "</li>";
                }
                echo "</ul>";
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