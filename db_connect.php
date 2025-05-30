<?php

    $db_host = '127.0.0.1'; 
    $db_name = 'chasing_completion_db'; 
    $db_user = 'root';       
    $db_pass = '';                   
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=$charset";


    try {

        $pdo = new PDO($dsn, $db_user, $db_pass);
        error_log("PDO DB Connected successfully in db_connect.php");
    } catch (PDOException $e) {
        error_log("Database Connection Error (PDO): " . $e->getMessage());

        die("Database connection failed. Please check server logs. Error: " . $e->getMessage());
    }


?>