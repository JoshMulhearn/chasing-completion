<?php
    //server name variables
    $my_host = "127.0.0.1";
    $my_db = "chasing_completion_db";
    $my_db_username = "root";
    $my_db_password = "";

    try {
        $DB = new PDO("mysql:host=$my_host;dbname=$my_db", $my_db_username, $my_db_password);

    }
    catch (Exception $ex){
        echo $ex->getMessage();
    }
?>