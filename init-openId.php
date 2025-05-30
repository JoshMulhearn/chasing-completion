<?php

    //parameters to pass as a GET query to the initial URL
    //tells steam what protocol we are using and how to redirect our user
    $login_url_params = [
        'openid.ns'         => 'http://specs.openid.net/auth/2.0',
        'openid.mode'       => 'checkid_setup',
        'openid.return_to'  => 'http://localhost/chasing-completion/process-openId.php', //url is under chasing-completion folder
        'openid.realm'      => (!empty($_SERVER['HTTPS']) ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'],
        'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
        'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
    ];

    //constructing url from parameters and redirecting
    $steam_login_url = 'https://steamcommunity.com/openid/login'.'?'.http_build_query($login_url_params, '', '&');

    header("location: $steam_login_url");
    exit();

?>