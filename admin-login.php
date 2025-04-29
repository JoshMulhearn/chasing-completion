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
    <title>Chasing Completion - Admin Login</title>
</head>
<body class="admin-body">
    <?php include 'navbar.php'?>
    <div class="admin-login-container">
            <h2>Admin Login</h2>
        <div class="admin-login-box">

            <form>
                <div class="input-field">
                    <label>Username</label>
                    <input type="text">   
                </div>

                <div class="input-field">
                    <label>Password</label>
                    <input type="text">   
                </div>

                <button type="submit">Login</button>
            </form>
        </div>
    </div>
    <?php include 'footer.php'?>
</body>
<script src="javascript/bar-menu.js"></script>
</html>