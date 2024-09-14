<?php
    include_once('connect.php');
    include_once('functions_global.php');

    function sanitizeString($input) {
        // Replace problematic characters with an empty string
        $replacements = array("'", '"', "\\", ";", "`", "--", "#", "=", ">", "<", "&", "%", "|", "^", "~", "(", ")");
        $sanitized = str_replace($replacements, "", $input);
        return htmlspecialchars($sanitized, ENT_QUOTES, 'UTF-8'); // Escape HTML entities
    }

    if (isset($_GET['id']) && !isset($_GET['reban']) && !isset($_GET['edit'])) {
        $id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
        showKbanInfo($id);
    }

    if (isset($_GET['oldid']) && !isset($_GET['reban']) && !isset($_GET['edit'])) {
        if (!isset($_COOKIE['steamID'])) {
            die();
        }

        $admin = new Admin();
        $admin->UpdateAdminInfo($_COOKIE['steamID']);

        $id = filter_input(INPUT_GET, 'oldid', FILTER_SANITIZE_NUMBER_INT);
        
        $kban = new Kban();
        $info = $kban->getKbanInfoFromID($id);
        if (!IsAdminLoggedIn() || (!$admin->DoesHaveFullAccess() && $info['admin_steamid'] != $admin->adminSteamID)) {
            die();
        }
        
        $reason = sanitizeString(filter_input(INPUT_GET, 'reason', FILTER_SANITIZE_STRING));
        
        if (!$kban->UnbanByID($id, $reason)) {
            die();
        } 

        die();
    }

    function showKbanInfo(int $id) {
        GetRowInfo($id);
    }

    if (isset($_GET['add']) && isset($_GET['playerName'])) {
        if (!IsAdminLoggedIn()) {
            die();
        }

        $playerName = sanitizeString(filter_input(INPUT_GET, 'playerName', FILTER_SANITIZE_STRING));
        $playerSteamID = sanitizeString(filter_input(INPUT_GET, 'playerSteamID', FILTER_SANITIZE_STRING));
        $length = filter_input(INPUT_GET, 'length', FILTER_SANITIZE_NUMBER_INT);
        $reason = sanitizeString(filter_input(INPUT_GET, 'reason', FILTER_SANITIZE_STRING));

        $icon = "<i class='fa-solid fa-xmark'></i>&nbsp";
        if (empty($playerName)) {
            echo "<p>$icon Player name cannot be empty!</p>";
            die();
        }

        if (empty($playerSteamID)) {
            echo "<p>$icon Player SteamID cannot be empty!</p>";
            die();
        }

        if (!preg_match("/^STEAM_[0-5]:[01]:\d+$/", $playerSteamID)) {
            echo "<p>$icon Invalid SteamID Format</p>";
            die();
        }

        if (empty($reason)) {
            echo "<p>$icon Reason cannot be empty!</p>";
            die();
        }

        if (str_contains($playerName, "'") || str_contains($playerName, "\"")) {
            echo "<p>$icon ' and \" characters cannot be used for Player Name!</p>";
            die();
        }

        if (str_contains($reason, "'") || str_contains($reason, "\"")) {
            echo "<p>$icon ' and \" characters cannot be used for Reason!</p>";
            die();
        }

        $kban = new Kban();
        if ($kban->IsSteamIDAlreadyBanned($playerSteamID)) {
            echo "<p>$icon $playerSteamID is already kbanned!</p>";
            die();
        }

        $kban->addNewKban($playerName, $playerSteamID, $length, $reason);
    }

    if (isset($_GET['edit']) && isset($_GET['playerName'])) {
        if (!isset($_COOKIE['steamID'])) {
            die();
        }

        $id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
        $playerName = sanitizeString(filter_input(INPUT_GET, 'playerName', FILTER_SANITIZE_STRING));
        $playerSteamID = sanitizeString(filter_input(INPUT_GET, 'playerSteamID', FILTER_SANITIZE_STRING));
        $length = filter_input(INPUT_GET, 'length', FILTER_SANITIZE_NUMBER_INT);
        $reason = sanitizeString(filter_input(INPUT_GET, 'reason', FILTER_SANITIZE_STRING));

        $icon = "<i class='fa-solid fa-xmark'></i>&nbsp";
        if (empty($playerName)) {
            echo "<p>$icon Player name cannot be empty!</p>";
            die();
        }

        if (empty($playerSteamID)) {
            echo "<p>$icon Player SteamID cannot be empty!</p>";
            die();
        }

        if (!preg_match("/^STEAM_[0-5]:[01]:\d+$/", $playerSteamID)) {
            echo "<p>$icon Invalid SteamID Format</p>";
            die();
        }

        if (empty($reason)) {
            echo "<p>$icon Reason cannot be empty!</p>";
            die();
        }

        if (str_contains($playerName, "'") || str_contains($playerName, "\"")) {
            echo "<p>$icon ' and \" characters cannot be used for Player Name!</p>";
            die();
        }

        if (str_contains($reason, "'") || str_contains($reason, "\"")) {
            echo "<p>$icon ' and \" characters cannot be used for Reason!</p>";
            die();
        }

        $admin = new Admin();
        $admin->UpdateAdminInfo($_COOKIE['steamID']);

        if ($length === null && !$admin->DoesHaveFullAccess()) {
            echo "<p>$icon You do not have permission for Permanent bans!</p>";
            die();
        }

        if (empty($reason)) {
            $reason = "NO REASON";
        }

        if ($length === null) {
            $length = 0;
        }
        
        if ($length < 0) {
            $length = 30;
        }

        $kban = new Kban();

        $info = $kban->getKbanInfoFromID($id);

        if (!IsAdminLoggedIn() || (!$admin->DoesHaveFullAccess() && $info['admin_steamid'] != $admin->adminSteamID)) {
            die();
        }

        if ($playerName == $info['client_name'] && $playerSteamID == $info['client_steamid'] && $reason == $info['reason'] && $length == ($info['length'] * 60)) {
            echo "<p>$icon Cannot detect any changes to edit!</p>";
            die();
        }

        if (!$kban->IsSteamIDAlreadyBanned($playerSteamID)) {
            echo "<p>$icon The edited steamid is already kbanned and cannot be edited from here</p>";
            die();
        }

        $time_stamp_end = ($info['time_stamp_start'] + $length);
        if ($length > 0 && $time_stamp_end < time()) {
            echo "<p>$icon Invalid Duration! Expected a duration that will last in the future but got one that has already ended.</p>";
            die();
        }
        $kban->EditKban($id, $playerName, $playerSteamID, $length, $reason);
    }

    if (isset($_GET['delete'])) {
        if (!isset($_COOKIE['steamID'])) {
            die();
        }
        
        $admin = new Admin();
        $admin->UpdateAdminInfo($_COOKIE['steamID']);
        if (!IsAdminLoggedIn() || !$admin->DoesHaveFullAccess()) {
            die();
        }

        $id = filter_input(INPUT_GET, 'deleteid', FILTER_SANITIZE_NUMBER_INT);
        $kban = new Kban();
        $kban->RemoveKbanFromDB($id);
        die();
    }
?>
