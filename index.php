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
    <h1 class="index-title">Track Your Achievements</h1>

    <div class="box-container">
        <p>
            Login in to steam and get started tracking your achievements,
            seeing your progress against others on our leaderboard,
            view your completionist stats, and more!
        </p>

        <a href="init-openId.php">
            <button>
                <div class="steam-login-btn-items">
                    <img src="Images/steam-icon.png">
                    <h3>Login With Steam</h3>
                </div>
            </button>
        </a>
    </div>

    <?php include 'footer.php'?>
</body>
<script src="javascript/bar-menu.js"></script>
</html>
