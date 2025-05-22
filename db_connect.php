<?php
    
    $db_link = mysqli_connect('127.0.0.1', 'root', '', 'chasing_completion_db');

    if($db_link === false){
        error_log("ERROR: Could not connect to MySQL. " . mysqli_connect_error());
        //$db_link remains false, and dashboard.php will see it as false
    } else {
        mysqli_set_charset($db_link, "utf8mb4");
        error_log("DB Connected successfully in db_connect.php"); 
    }
?>