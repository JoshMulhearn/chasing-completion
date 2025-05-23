
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="1; URL=dashboard.php" />
    <!--FONTS-->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Genos:ital,wght@0,100..900;1,100..900&family=Orbitron:wght@400..900&family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <!---->
    <!--STYLE SHEETS-->
    <link rel="stylesheet" href="css/style.css">
    <!---->
    <title>Chasing Completion</title>
</head>
<body>
    <div id="loading-screen">
        <div class="loading-spinner"></div>
        <p>Site is loading...</p>
        <p>This may take a while</p>
    </div>



    <script>
        window.addEventListener('load', function()) {
            const loadingScreen = document.getElementById('loading-screen');
            const pageContent = document.getElementById('page-content');
            if (loadingScreen) {
                loadingScreen.style.display = 'none';
            }
            if (pageContent) {
                pageContent.style.visibility = 'visible'; 
            }
        }
    </script>
</body>
    <script src="javascript/bar-menu.js"></script>
</html>
