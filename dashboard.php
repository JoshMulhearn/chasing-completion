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
    <div class="dashboard-main-container">
        <div class="dashboard-column-1">
            <div class="dashboard-user-card">
                <img class="dash-profile-img" src='<?php echo $avatar;?>'/>

                <div class="dashboard-user-info">
                    <h2>Logged in as <?php echo $username;?></h2>
                    <h2>placeholder</h2>
                    <h2>placeholder</h2>
                </div>
            </div>

            <div class="dashboard-options">
                <div class="dash-buttons">
                    <button>
                        <img src="Images/steam-icon.png" alt="games-btn">
                        <h2>Games</h2>
                    </button>
                    <button>
                        <img src="Images/steam-icon.png" alt="profile-btn">
                        <h2>Profile</h2>
                    </button>
                    <button>
                        <img src="Images/steam-icon.png" alt="achievements-btn">
                        <h2>Achievements</h2>
                    </button>
                    <button>
                        <img src="Images/steam-icon.png" alt="stats-btn">
                        <h2>Stats</h2>
                    </button>
                </div>
            </div>
        </div>
        <div class="dashboard-column-2">
            <div class="dash-leaderboard">
                <h2>Leaderboard</h2>
                <div class="leaderboard-contents">
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                    <p>placeholder</P>
                </div>
            </div>
        </div>
    </div>
    <?php include 'footer.php'?>
</body>
<script src="javascript/bar-menu.js"></script>
</html>