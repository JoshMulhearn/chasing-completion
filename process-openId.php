<?php
    session_start();
    function p($arr){
        return '<pre>'.print_r($arr,true).'</pre>';
    }

    //parameters to send to steam open id
    $params = [
        'openid.assoc_handle' => $_GET['openid_assoc_handle'],
        'openid.signed'       => $_GET['openid_signed'],
        'openid.sig'          => $_GET['openid_sig'],
        'openid.ns'           => 'http://specs.openid.net/auth/2.0',
        'openid.mode'         => 'check_authentication',
    ];

    //dynamically use $_GET['openid_signed'] parameter to construct more parameters
    $signed = explode(',', $_GET['openid_signed']);
    
    foreach ($signed as $item) {
        $val = $_GET['openid_'.str_replace('.', '_', $item)];
        $params['openid.'.$item] = stripslashes($val);
    }

    //construct headers to hit steam endpoint with our open id information
    $data = http_build_query($params);
    //data prep
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Accept-language: en\r\n".
            "Content-type: application/x-www-form-urlencoded\r\n".
            'Content-Length: '.strlen($data)."\r\n",
            'content' => $data,
        ],
    ]);

    //get the data
    $result = file_get_contents('https://steamcommunity.com/openid/login', false, $context);


    // check result is valid
    if(preg_match("#is_valid\s*:\s*true#i", $result))
    {
        preg_match('#^https://steamcommunity.com/openid/id/([0-9]{17,25})#', $_GET['openid_claimed_id'], $matches);
        $steamID64 = is_numeric($matches[1]) ? $matches[1] : 0;
        echo 'request has been validated by open id, returning the client id (steam id) of: ' . $steamID64;    
    
    }
    else
    {
        echo 'error: unable to validate your request';
        exit();
    }

    $steam_api_key = 'CF9C8D9F6B8A4AD8DBE9D6DB383949E5'; //my api key. Per the terms and conditions this is not to be shared!


    //use steam id to get user data with api
    $response = file_get_contents('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key='.$steam_api_key.'&steamids='.$steamID64); //will need to add in more get contents for user games etc
    $response = json_decode($response,true);

    //relevant user data from the previous response (steam id, username, avatar)
    $userData = $response['response']['players'][0];
    $_SESSION['logged_in'] = true;
    $_SESSION['userData'] = [
        'steam_id'=>$userData['steamid'],
        'name'=>$userData['personaname'],
        'avatar'=>$userData['avatarmedium'],
    ];

    $redirect_url = "dashboard.php";
    header("Location: $redirect_url"); 
    exit();
?>

