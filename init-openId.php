<?php
    $login_url_params = [
        'openid.ns'         => 'http://specs.openid.net/auth/2.0',
        'openid.mode'       => 'checkid_setup',
        'openid.return_to'  => 'http://localhost/login-with-steam-yt/process-openId.php',
        'openid.realm'      => (!empty($_SERVER['HTTPS']) ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'],
        'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
        'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
    ];
?>

<!--i dont know what this is. Continue watching video at https://www.youtube.com/watch?v=7IzEqAK_PLg-->