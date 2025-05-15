<?php
    session_start();
    if(!$_SESSION['logged_in']){
        header("Location: Error.php");
        exit();
    }
    //i dont know what this is. Continue watching video at https://www.youtube.com/watch?v=7IzEqAK_PLg
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
    <h1>Welcome you are logged in</h1>

    <?php include 'footer.php'?>
</body>
<script src="javascript/bar-menu.js"></script>
</html>