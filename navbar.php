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
        <h1>Chasing</h1>
        <h1>Completion</h1>
    </div>
    <ul>
        <a href="#">Link</a>
        <a href="#">Link</a>
        <a href="#">Link</a>
        <a href="#">Link</a>
    </ul>
    <div class="profile-btn-container">
        <button class="profile-btn">
         
        </button>
        <img class="profile-btn-img" src="images/profile-placeholder-1.svg" alt="profile"> <!--change to logged in avatar and also add functionality-->
    </div>
</nav>

<div class="off-screen-menu">
    <ul>
        <li><a href="<?php echo $home_link;?>">Home</a></li> <!--Home link is either index.php or dashboard.php depending on wether a user is logged in-->
        <?php //maybe a tidier way to do this but reusing isset because it works 
            if(isset($_SESSION['logged_in']))
            {
                echo "<li><a href='#'</a></li>";
            }
        ?>
        <li><a href="#">About</a></li>
        <li><a href="#">Privacy Policy</a></li>
        <li><a href="#">Terms and Conditions</a></li>
        <?php 
            if(isset($_SESSION['logged_in']))
            {
                echo "<li><a href='logout.php'</a>Logout</li>";
            }
        ?>
    </ul>
</div>