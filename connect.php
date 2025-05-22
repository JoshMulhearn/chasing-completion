<?php
    // db_connect.php
    define('DB_SERVER', 'localhost'); // or your db host
    define('DB_USERNAME', 'your_db_username');
    define('DB_PASSWORD', 'your_db_password');
    define('DB_NAME', 'your_db_name');

    $db_link = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

    if($db_link === false){
        error_log("ERROR: Could not connect to MySQL. " . mysqli_connect_error());
        // In a real app, you might handle this more gracefully than letting the script continue
    } else {
        mysqli_set_charset($db_link, "utf8mb4"); // Good practice
    }
?>