<?php
    include_once('connect.php');
    include_once('functions_global.php');
    
    if(isset($_GET['id']) && !isset($_GET['reban']) && !isset($_GET['edit'])) {
        $id = $_GET['id'];
        showKbanInfo($id);
    }

    if(isset($_GET['oldid']) && !isset($_GET['reban']) && !isset($_GET['edit'])) {
        if(!isset($_COOKIE['steamID'])) {
            die();
        }

        $admin = new Admin();
        $admin->UpdateAdminInfo($_COOKIE['steamID']);

        $id = $_GET['oldid'];
        
        $kban = new Kban();
        $info = $kban->getKbanInfoFromID($id);
        if(!IsAdminLoggedIn() || (!$admin->DoesHaveFullAccess() && $info['admin_steamid'] != $admin->adminSteamID)) {
            die();
        }
        $reason = $_GET['reason'];
        $kban = new Kban();
        if(!$kban->UnbanByID($id, $reason)) {
            die();
        } 

        die();
    }

    function showKbanInfo(int $id) {
       GetRowInfo($id);
    }

    if(isset($_GET['add']) && isset($_GET['playerName'])) {
        if(!IsAdminLoggedIn()) {
            die();
        }

        $playerName = $_GET['playerName'];
        $playerSteamID = $_GET['playerSteamID'];
        $length = $_GET['length'];
        $reason = $_GET['reason'];

        $icon = "<i class='fa-solid fa-xmark'></i>&nbsp";
        if(empty($playerName)) {
            echo "<p>$icon Player name cannot be empty!</p>";
            die();
        }

        if(empty($playerSteamID)) {
            echo "<p>$icon Player SteamID cannot be empty!</p>";
            die();
        }

        if(!preg_match("/^STEAM_[0-5]:[01]:\d+$/", $playerSteamID)) {
            echo "<p>$icon Invalid SteamID Format</p>";
            die();
        }

        if(empty($reason)) {
            echo "<p>$icon Reason cannot be empty!</p>";
            die();
        }

        if(str_contains($playerName, "'") || str_contains($playerName, "\"")) {
            echo "<p>$icon ' and \" characters cannot be used for Player Name!</p>";
            die();
        }

        if(str_contains($reason, "'") || str_contains($reason, "\"")) {
            echo "<p>$icon ' and \" characters cannot be used for Reason!</p>";
            die();
        }

        $kban = new Kban();
        if($kban->IsSteamIDAlreadyBanned($playerSteamID)) {
            echo "<p>$icon $playerSteamID is already kbanned!</p>";
            die();
        }

        $kban->addNewKban($playerName, $playerSteamID, $length, $reason);
    }

    if(isset($_GET['edit']) && isset($_GET['playerName'])) {
        if(!isset($_COOKIE['steamID'])) {
            die();
        }

        $id = $_GET['id'];
        $playerName = $_GET['playerName'];
        $playerSteamID = $_GET['playerSteamID'];
        $length = $_GET['length'];
        $reason = $_GET['reason'];

        $icon = "<i class='fa-solid fa-xmark'></i>&nbsp";
        if(empty($playerName)) {
            echo "<p>$icon Player name cannot be empty!</p>";
            die();
        }

        if(empty($playerSteamID)) {
            echo "<p>$icon Player SteamID cannot be empty!</p>";
            die();
        }

        if(!preg_match("/^STEAM_[0-5]:[01]:\d+$/", $playerSteamID)) {
            echo "<p>$icon Invalid SteamID Format</p>";
            die();
        }

        if(empty($reason)) {
            echo "<p>$icon Reason cannot be empty!</p>";
            die();
        }

        if(str_contains($playerName, "'") || str_contains($playerName, "\"")) {
            echo "<p>$icon ' and \" characters cannot be used for Player Name!</p>";
            die();
        }

        if(str_contains($reason, "'") || str_contains($reason, "\"")) {
            echo "<p>$icon ' and \" characters cannot be used for Reason!</p>";
            die();
        }
        
        $admin = new Admin();
        $admin->UpdateAdminInfo($_COOKIE['steamID']);

        if($length == 0 && !$admin->DoesHaveFullAccess()) {
            echo "<p>$icon You do not have permission for Permanent bans!</p>";
            die();
        }

        if(empty($reason)) {
            $reason = "NO REASON";
        }

        if(empty($length)) {
            $length = 0;
        }
        
        if($length < 0) {
            $length = 30;
        }

        $kban = new Kban();

        $info = $kban->getKbanInfoFromID($id);

        if(!IsAdminLoggedIn() || (!$admin->DoesHaveFullAccess() && $info['admin_steamid'] != $admin->adminSteamID)) {
            die();
        }

        if($playerName == $info['client_name'] && $playerSteamID == $info['client_steamid'] && $reason == $info['reason'] && $length == ($info['length'] * 60)) {
            echo "<p>$icon Cannot detect any changes to edit!</p>";
            die();
        }

        if(!$kban->IsSteamIDAlreadyBanned($playerSteamID)) {
            echo "<p>$icon The edited steamid is alread kbanned and cannot be edited from here</p>";
            die();
        }

        $time_stamp_end = ($info['time_stamp_start'] + $length);
        if($time_stamp_end < time()) {
            echo "<p>$icon Invalid Duration! Expected a duration that will last in the future but got a one that has already ended.</p>";
            die();
        }
        $kban->EditKban($id, $playerName, $playerSteamID, $length, $reason);
    }

    if(isset($_GET['delete'])) {
        if(!isset($_COOKIE['steamID'])) {
            die();
        }
        
        $admin = new Admin();
        $admin->UpdateAdminInfo($_COOKIE['steamID']);
        if(!IsAdminLoggedIn() || !$admin->DoesHaveFullAccess()) {
            die();
        }

        $id = $_GET['deleteid'];
        $kban = new Kban();
        $kban->RemoveKbanFromDB($id);
        die();
    }
?>
