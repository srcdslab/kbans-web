<?php
    include('connect.php');
    include('functions_global.php'); 

    $requiredOpenIdParams = [
        'openid_assoc_handle',
        'openid_signed',
        'openid_sig',
        'openid_claimed_id',
    ];

    foreach ($requiredOpenIdParams as $param) {
        if (!isset($_GET[$param])) {
            echo 'error: invalid openid response';
            exit();
        }
    }

    $params = [
        'openid.assoc_handle' => $_GET['openid_assoc_handle'],
        'openid.signed'       => $_GET['openid_signed'],
        'openid.sig'          => $_GET['openid_sig'],
        'openid.ns'           => 'http://specs.openid.net/auth/2.0',
        'openid.mode'         => 'check_authentication',
    ];

    $signed = explode(',', $_GET['openid_signed']);
        
    foreach ($signed as $item) {
        $key = 'openid_'.str_replace('.', '_', $item);
        if (!isset($_GET[$key])) {
            echo 'error: invalid openid response';
            exit();
        }

        $val = $_GET[$key];
        $params['openid.'.$item] = stripslashes($val);
    }

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

    if($result !== false && preg_match("#is_valid\s*:\s*true#i", $result)){
        preg_match('#^https://steamcommunity.com/openid/id/([0-9]{17,25})#', $_GET['openid_claimed_id'], $matches);
        $steamID64 = isset($matches[1]) && is_numeric($matches[1]) ? $matches[1] : 0;
    } else {
        echo 'error: unable to validate your request';
        exit();
    }

    $steam_api_key = $GLOBALS['STEAM_API_KEY'];
    $secret_key = $GLOBALS['SECRET_KEY'];

    $response = file_get_contents('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key='.$steam_api_key.'&steamids='.$steamID64);
    if ($response === false) {
        echo 'error: unable to fetch steam profile';
        exit();
    }

    $response = json_decode($response,true);
    if (!is_array($response) || empty($response['response']['players'][0])) {
        echo 'error: invalid steam profile response';
        exit();
    }


    $userData = $response['response']['players'][0];

    $steamID64 = $userData['steamid'];
    $steam = new Steam();
    $steamID32 = $steam->SteamID64_To_SteamID($steamID64);

    $admin = new Admin();
    if($admin->IsLoginValid($steamID32, $secret_key, true)) {
        setcookie('steamID', $steamID32, (time() * 30), "/", $_SERVER['SERVER_NAME'], true, true);
        setcookie("secret_key", $secret_key, (time() * 30), "/", $_SERVER['SERVER_NAME'], true, true);

        // Create an unique cookie based on sbpp aid for each user
        // Aid is the safer option to use as a cookie since it does not have any personal information
        $sql = "SELECT aid FROM sb_admins WHERE authid = ?";
        $stmt = $GLOBALS['SBPP']->prepare($sql);
        $stmt->bind_param("s", $steamID32);
        $stmt->execute();
        $queryResult = $stmt->get_result();
        $stmt->close();

        $row = $queryResult->fetch_assoc();
        $aid = $row['aid'] ?? '';

        setcookie("aid", $aid, (time() * 30), "/", $_SERVER['SERVER_NAME'], true, true);
    }

    $server_host_url = (!empty($_SERVER['HTTPS']) ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'];
    header("Location: ". $server_host_url);
    die();
?>
