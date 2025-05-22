<?php
    session_start();

    //if a user is not logged in they get redirected to the error page
    //this page lets them know that they do not have access as they are not logged in
    if(!$_SESSION['logged_in']){
        header("Location: Error.php");
        exit(); 
    }
    
    //variables from process-openId
    $username = $_SESSION['userData']['name']; 
    $avatar = $_SESSION['userData']['avatar'];
    $steamID64 = isset($_SESSION['userData']['steam_id']) ? $_SESSION['userData']['steam_id'] : null;
    
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
    <title>Chasing Completion</title>
</head>
<body>
    <?php include 'navbar.php'?>

    <div class="dashboard-page-layout">
        <div class="user-card">
            <img class="user-avatar" src="<?php echo $avatar; ?>" alt="<?php echo $username; ?>'s Avatar">
            <h2>Logged in as <?php echo $username; ?></h2>
        </div>

        <div class="dashboard-links">
            <a href="games.php">Games</a>
            <a href="profile.php">Profile</a>
            <a href="user-stats.php">Stats</a>
        </div>

        <div class="dash-leaderboard">
            <h2>Leaderboard</h2>
            <ol class="leaderboard-list">
                <li class="leaderboard-item">
                    <span class="rank">#<?php echo $index + 1; ?></span>
                    <span class="username" title="<?php echo htmlspecialchars($entry['username']); ?>"><?php echo htmlspecialchars($entry['username']); ?></span>
                    <span class="completion"><?php echo $entry['completion']; ?>%</span>
                </li>
            </ol>
        </div>
    </div>

    <?php include 'footer.php'?>
</body>
<script src="javascript/bar-menu.js"></script>
</html>