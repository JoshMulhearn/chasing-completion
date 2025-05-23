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


    //check result is valid
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

    require_once __DIR__ . '/steam-api-key.php';

    $steam_api_key = steam_api_key; //api key is taken from a config file (steam-api-key) as legally it is not supposed to be shared


    //use steam id to get user data with api
    $player_sum_response = file_get_contents('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key='.$steam_api_key.'&steamids='.$steamID64); 
    $player_sum_response = json_decode($player_sum_response,true);

    //owned games data with api
    $owned_games_response = file_get_contents('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/?key='.$steam_api_key.'&steamid='.$steamID64.'&include_appinfo=1&include_played_free_games=1');
    $owned_games_response = json_decode($owned_games_response,true);


    //relevant user data from the previous response (steam id, username, avatar)
    $playerSum = $player_sum_response['response']['players'][0];
    //relevant owned games data
    $ownedGamesList = $owned_games_response['response']['games'];
    $gameCount = $owned_games_response['response']['game_count'];

    $_SESSION['logged_in'] = true;
    // Initialize with player summary
    $_SESSION['userData'] = [
        'steam_id'    => $playerSum['steamid'],
        'name'        => $playerSum['personaname'],
        'avatar'      => $playerSum['avatarmedium'],
        'timecreated' => $playerSum['timecreated'] ?? null,
    ];

    // Add owned games data as the full array which will be looped through for specific data
    if (isset($owned_games_response['response']) && isset($owned_games_response['response']['games'])) {
        $_SESSION['userData']['owned_games'] = [
            'game_count' => $owned_games_response['response']['game_count'] ?? 0,
            'games'      => $owned_games_response['response']['games']
        ];
    }    
    else 
    {
        $_SESSION['userData']['owned_games'] = ['game_count' => 0, 'games' => []];
        //if no games list is empty
    }

    //forces dashboard.php to recalculate
    //this ensures logging out and logging back in doesnt break dashboard.php
    unset($_SESSION['dashboard_stats']);
    error_log("process-openId.php: Cleared any existing \$_SESSION['dashboard_stats'] for fresh calculation for user {$steamID64}.");

    $redirect_url = "loading.php";
    header("Location: $redirect_url"); 
    exit();
?>