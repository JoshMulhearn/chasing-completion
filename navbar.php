<?php     
    if (session_status() == PHP_SESSION_NONE) 
    //since navbar is included in every page and a session may have already been started, 
    // this code only starts a session when one hasnt already been created 
    {
        session_start();
    }

    //default home link is index.php
    $home_link = "index.php";

    if(isset($_SESSION['logged_in']))
    {
        //home link changes to dashboard.php when user is logged in
        $home_link = "dashboard.php";
    }


?>

<nav class="navbar">
    
    
    <img class="bar-menu" src="images/hamburger-icon.svg" alt="bar-menu">
    
    <div class="logo-headers">
        <div class="logo-img">
            <img src="Images/trophy_logo.png" alt="logo">
        </div>
        <div class="logo-text">
            <h1>Chasing</h1>
            <h1>Completion</h1>
        </div>

    </div>
    <ul>
        <a href="games.php">Games</a>
        <a href="profile.php">Profile</a>
        <a href="achievements.php">Achievements</a>
        <a href="user-stats.php">User Stats</a>
    </ul>

    <?php
        if(isset($_SESSION['logged_in']) && isset($_SESSION['userData']['avatar']))
        {
            echo '<img class="profile-img" src="' . $avatar . '" alt="profile">';
        }
        else
        {
            echo '<img class="profile-img" src="images/profile-placeholder-1.svg" alt="profile">';
        }
    ?>


</nav>

<div class="off-screen-menu">
    <ul>
        
        <li><a href="<?php echo $home_link;?>">Home</a></li> <!--Home link is either index.php or dashboard.php depending on wether a user is logged in-->

        <?php 
            if(isset($_SESSION['logged_in']))
            {

                echo '<li><a href="games.php">Games</a></li>';
                echo '<li><a href="profile.php">Profile</a></li>';
                echo '<li><a href="achievements.php">Achievements</a></li>';
                echo '<li><a href="user-stats.php">User Stats</a></li>';
                echo "<li><a href='logout.php'</a>Logout</li>";
            }
        ?>
    </ul>
</div>