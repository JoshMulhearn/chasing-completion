<?php
    session_start(); 

    //if a user is not logged in they get redirected to the error page
    //this page lets them know that they do not have access as they are not logged in
    if(!$_SESSION['logged_in']){
        header("Location: Error.php");
        exit(); 
    }

    //make sure userData exists in the session
    if (!isset($_SESSION['userData'])) {
        //if the session got corrupted or process-openId didn't complete successfully then this will run
        //log the error and redirect to error.php
        error_log("Error in games.php: \$_SESSION['userData'] is not set.");
        header("Location: Error.php?code=session_data_missing");
        exit();
    }

    //access variables from the session
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
                     "<input type='text' placeholder='Search..'>" . //search bar isnt really needed in this if statement but you wont be able to search for games if there is no valid game data
                     "<p>Genre</p>" .
                     "<p>Theme</p>" .
                 "</div>" .
             "</div>"; //genre and theme are currently placeholders

        //start the unordered list for games
        echo "<ul class='games-list-container'>";

            //loop through every game
            foreach ($ownedGamesData['games'] as $game) { //for every game the user owns

                //get relevant game data
                $gameAppId = $game['appid'] ?? 'N/A'; //app id
                $gameName = $game['name'] ?? 'Unknown Game'; //game name
                $playtimeForever = $game['playtime_forever'] ?? 0; //their total playtime
                $playtimeHours = round($playtimeForever / 60, 1); //in hours with one decimal place

                //sanitize all dynamic data before including in HTML
                //htmlspecialchars is for security and makes sure no odd characters in the game data can be used to mess with the website,
                //these characters are displayed as plain text
                $safeGameName = htmlspecialchars($gameName); 
                $safeGameAppId = htmlspecialchars($gameAppId);
                $safePlaytimeHours = htmlspecialchars($playtimeHours);

                //game image is empty as it is filled using the following if statement
                $gameImageUrl = "";
                $achievementsPageUrl = "#"; //default link if no appid

                if ($gameAppId !== 'N/A') { //if the games app id exisits execute the following code
                    $gameImageUrl = "https://cdn.akamai.steamstatic.com/steam/apps/{$gameAppId}/library_600x900.jpg"; //images for the specific game are loaded from an Akamai server
                    $achievementsPageUrl = "game_achievements.php?appid=" . urlencode($gameAppId); //the url for the specific games achievement page is generated
                }
                
                $safeAchievementsPageUrl = htmlspecialchars($achievementsPageUrl); //htmlspecialchars used again on the achievement page url to ensure security
                $safeGameImageUrl = htmlspecialchars($gameImageUrl); //same here for image url

                $imageHtml = "";
                if ($gameImageUrl) { //if there is an image for the game
                    $imageHtml = "<img src='" . $safeGameImageUrl . "' " . //the image source is the previouily sanitised image url
                                    "alt='" . $safeGameName . " Library Capsule' " . //the alt for the image is "(game name) library capsule"
                                    "class='game-library-image'>"; //class for css is game-library-image

                } else {
                    $imageHtml = "<span class='img-error'>N/A</span>"; //if image isnt found display N/A
                }


                //the list of games. Remember this is in a <ul>
                $listItem = "
                    <li class='game-list-item'>" .
                        //the link to the games achievement page is the image itself
                        "<a href='" . $safeAchievementsPageUrl . "' class='game-library-image-link' title='View achievements for " . $safeGameName . "'>" . $imageHtml . "</a>" .
                        "<div class='game-details'>" .
                            "<span>" . $safeGameName . "</span>" .
                            "<span>AppID: " . $safeGameAppId . "</span>" .
                            "<span>Playtime: " . $safePlaytimeHours . " hours</span>" .
                        "</div>" .
                    "</li>";
                
                echo $listItem;
            }
        
        echo "</ul>"; //close the unordered list

    } elseif (!empty($ownedGamesData['message'])) {
        echo "<p>" . htmlspecialchars($ownedGamesData['message']) . "</p>";
    } else {
        echo "<p>You do not seem to own any games, or your game library is private.</p>";
    }
?>

    <?php include 'footer.php'; ?>

    <script src="javascript/bar-menu.js"></script>
</body>
</html>