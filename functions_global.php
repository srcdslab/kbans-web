<?php
    include_once('steam.php');

    /* How long a login lasts.
       This used to be `time() * 30` -- a multiplication where an addition was
       meant, which put the expiry in the year 3670:

           php > echo date("Y-m-d", time() * 30);
           3670-08-10

       so the credential never aged out and there was no way to expire it. */
    define('LOGIN_COOKIE_LIFETIME', 12 * 60 * 60);

    /* One place that decides how the login cookies are attributed, so
       login-process.php, logout.php and header.php cannot drift apart.

       `secure` stays on unconditionally: the panel is meant to be served over
       HTTPS and every existing setcookie() call already passed secure=true.

       `samesite` was absent, which left the browser default of Lax. It is set
       explicitly here so the intent is visible. */
    function loginCookieOptions(int $expires): array {
        return [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => $_SERVER['SERVER_NAME'] ?? '',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    function setLoginCookie(string $name, string $value): bool {
        return setcookie($name, $value, loginCookieOptions(time() + LOGIN_COOKIE_LIFETIME));
    }

    /* Clears the pre-session login cookies. Kept so a browser still holding a
       set from before the server-side-session change is cleaned up on logout. */
    function clearLoginCookies(): void {
        foreach (['steamID', 'secret_key', 'aid'] as $name) {
            setcookie($name, '', loginCookieOptions(time() - 3600));
        }
    }

    /* Starts the session the login lives in. Must run before any output.

       The login used to be three cookies whose only secret was SECRET_KEY --
       one constant, identical for every admin, handed to every browser that
       signed in. Nothing in that set proved the browser had ever completed the
       Steam OpenID flow: `steamID` is a public SteamID (admin SteamIDs are
       printed on every ban as "Banned by Admin"), `aid` is a small sequential
       integer, and `secret_key` was the same string for everyone. Anyone who
       saw that one string could re-issue the whole set for any other admin.

       The session id is now a server-issued secret that is not derived from
       anything the client knows. */
    function startPanelSession(): void {
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            return;
        }

        /* Reject a session id the server never issued, so an attacker cannot
           fix a victim's id in advance and inherit the session they create. */
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            /* A browser-session cookie; the real deadline is enforced by the
               server through $_SESSION['login_time']. */
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => $_SERVER['SERVER_NAME'] ?? '',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('kbans_session');
        session_start();
    }

    startPanelSession();

    /* Records a completed Steam OpenID login. */
    function establishAdminSession(string $steamID, array $adminRow): void {
        /* A fresh id for the authenticated session, so an id that existed
           before the login cannot be replayed after it. */
        session_regenerate_id(true);

        $_SESSION['steamid']    = $steamID;
        $_SESSION['aid']        = (int) $adminRow['aid'];
        $_SESSION['gid']        = (int) $adminRow['gid'];
        $_SESSION['user']       = $adminRow['user'];
        $_SESSION['login_time'] = time();
    }

    function destroyAdminSession(): void {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', loginCookieOptions(time() - 3600));
        }

        session_destroy();
    }

    class Utility {
        public static function sanitizeInput($input) {
            $input = (string) ($input ?? '');
            $replacements = array("'", '"', "\\", ";", "`", "--", "#", "=", ">", "<", "&", "%", "|", "^", "~", "(", ")");
            return str_replace($replacements, "", $input);
        }
    }

    class Admin {
        public $adminID = -1;
        public $adminGroupID = -1;
        public $adminSteamID = "";
        public $adminUser = "";

        /* Admin rows resolved during this request, so repeated eligibility
           checks for the same SteamID do not each re-hit SourceBans. */
        private static $adminRowCache = [];

        /* The SourceBans admin row for a SteamID, but only if that SteamID is
           allowed to sign in; null otherwise.

           Membership is re-read on every request -- never trusted from
           whatever the browser sent -- so removing an admin from SourceBans or
           moving them out of an allowed group takes effect immediately. */
        public static function lookupEligibleAdmin($steamID): ?array {
            $steamID = (string) ($steamID ?? '');
            if ($steamID === '') {
                return null;
            }

            if (array_key_exists($steamID, self::$adminRowCache)) {
                return self::$adminRowCache[$steamID];
            }

            $stmt = $GLOBALS['SBPP']->prepare(
                "SELECT `aid`, `gid`, `authid`, `user` FROM `sb_admins` WHERE `authid` = ?"
            );
            $stmt->bind_param("s", $steamID);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $acceptableGroups = array_merge(GID_STAFF, GID_ADMIN);
            if ($row === null || $row['gid'] == -1 || !in_array($row['gid'], $acceptableGroups)) {
                return self::$adminRowCache[$steamID] = null;
            }

            return self::$adminRowCache[$steamID] = $row;
        }

        /* The SteamID of the signed-in admin, or null. This is the whole
           credential check now: a server-side session the client cannot forge,
           plus a deadline the server owns. */
        public static function sessionSteamID(): ?string {
            $steamID = $_SESSION['steamid'] ?? null;
            $since   = $_SESSION['login_time'] ?? null;

            if (!is_string($steamID) || $steamID === '' || !is_int($since)) {
                return null;
            }

            /* The session cookie alone lasts only as long as the browser stays
               open, so the real deadline is enforced here. */
            if ((time() - $since) > LOGIN_COOKIE_LIFETIME) {
                return null;
            }

            return $steamID;
        }

        /* Populates this object from the signed-in admin. An explicit SteamID
           is accepted for the login flow, before a session exists; every other
           caller passes nothing and gets the session's admin. */
        public function UpdateAdminInfo(?string $steamID = null) {
            $steamID = $steamID ?? self::sessionSteamID();
            if (!is_string($steamID) || $steamID === '') {
                return false;
            }

            $row = self::lookupEligibleAdmin($steamID);
            if ($row === null) {
                return false;
            }

            $this->adminID = $row['aid'];
            $this->adminGroupID = $row['gid'];
            $this->adminSteamID = $row['authid'];
            $this->adminUser = $row['user'];

            return true;
        }

        public function GetAdminNameFromSteamID($steamID) {
            $steamID = (string) ($steamID ?? '');
            if (!str_contains($steamID, "STEAM")) {
                return "CONSOLE";
            }

            $sql = "SELECT * FROM `sb_admins` WHERE `authid`=?";
            $stmt = $GLOBALS['SBPP']->prepare($sql);
            $stmt->bind_param("s", $steamID);
            $stmt->execute();
            $queryResult = $stmt->get_result();
            $stmt->close();

            $results = $queryResult->fetch_all(MYSQLI_ASSOC);
            foreach ($results as $result) {
                return $result['user'];
            }

            return "<i>Admin Deleted</i>";
    }

        public function DoesHaveFullAccess() {
            return in_array($this->adminGroupID, GID_STAFF);
        }

    }

    class Kban {
        public function UnbanByID($id, $reasonA) {
            if (empty($reasonA)) {
                $reasonA = "No Reason";
            }

            $reason = Utility::sanitizeInput($reasonA);

            if (!IsAdminLoggedIn()) {
                return false; // Should never happen but better be safe
            }

            $admin = new Admin();
            if (!$admin->UpdateAdminInfo()) {
                return false;
            }

            $adminName = $admin->adminUser;
            $adminSteamID = $admin->adminSteamID;

            $kban = new Kban();
            $resultsB = $kban->getKbanInfoFromID($id);
            if ($resultsB === null) {
                return false;
            }
            $length = $resultsB['length'];

            $time_removed = time();

            $sql = "UPDATE `KbRestrict_CurrentBans` SET `is_expired`=1, `is_removed`=1, 
                    `admin_name_removed`=?, `admin_steamid_removed`=?, `reason_removed`=?, 
                    `time_stamp_removed`=? WHERE `id`=?";
            $stmt = $GLOBALS['DB']->prepare($sql);
            $stmt->bind_param("ssssi", $adminName, $adminSteamID, $reason, $time_removed, $id);
            $stmt->execute();
            $stmt->close();

            $results = $this->getKbanInfoFromID($id);
            if ($results === null) {
                return false;
            }
            $playerName = $results['client_name'];
            $playerSteamID = $results['client_steamid'];
            $message = "Kban Removed (was $length minutes. Reason: $reason)";
            $time_stamp_start = time();

            GetRowInfo($id);

            $sql = "INSERT INTO `KbRestrict_weblogs` (`client_name`, `client_steamid`, `admin_name`, `admin_steamid`, `message`, `time_stamp`) ";
            $sql .= "VALUES (?, ?, ?, ?, ?, ?)";

            $stmt = $GLOBALS['DB']->prepare($sql);
            $stmt->bind_param("sssssi", $playerName, $playerSteamID, $adminName, $adminSteamID, $message, $time_stamp_start);
            $stmt->execute();
            $stmt->close();

            //echo "<script>showKbanWindowInfo(2, \"$playerName\", \"$playerSteamID\", \"$reason\", \"$length minutes\");</script>";
            echo "<script>showKbanWindowInfo(2, \"$playerName\", \"$playerSteamID\", \"$reason\", \"$length minutes\", $id);</script>";

            return true;
        }

        public function RemoveKbanFromDB($id) {
            $admin = new Admin();
            $admin->UpdateAdminInfo();
            if (!IsAdminLoggedIn() || !$admin->DoesHaveFullAccess()) {
                return false;
            }

            $resultsC = $this->getKbanInfoFromID($id);
            if ($resultsC === null) {
                return false;
            }
            $playerName = $resultsC['client_name'];
            $playerSteamID = $resultsC['client_steamid'];
            $length = $resultsC['length'];
            $reason = $resultsC['reason'];
            $isExpired = ($resultsC['is_expired'] == 1);
            $isRemoved = ($resultsC['is_removed'] == 1);
            $time_stamp_end = $resultsC['time_stamp_end'];
            $status = "Active";
            if ($isExpired && !$isRemoved || ($time_stamp_end >= 1 && time() > $time_stamp_end)) {
                $status = "Expired";
            }
            if ($isRemoved) {
                $status = "Removed";
            }

            $message = "KBan Deleted (was $length minutes. Issued for: $reason. Kban was $status)";

			$adminName = $admin->adminUser;
			$adminSteamID = $admin->adminSteamID;
			$time_stamp = time();

            // Use prepared statements
            $stmt = $GLOBALS['DB']->prepare("DELETE FROM `KbRestrict_CurrentBans` WHERE `id` = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $GLOBALS['DB']->prepare("INSERT INTO `KbRestrict_weblogs` (`client_name`, `client_steamid`, `admin_name`, `admin_steamid`, `message`, `time_stamp`) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sssssi', $playerName, $playerSteamID, $adminName, $adminSteamID, $message, $time_stamp);
            $stmt->execute();
            $stmt->close();

            echo "<script>showKbanWindowInfo(3, \"$playerName\", \"$playerSteamID\", \"$reason\", \"$length minutes\", $id);</script>";
            //echo "<script>window.location.replace('index.php?all');</script>";
            return true;
        }

        public function formatLength($seconds) {
            /* if less than one minute */
            if ($seconds < 60) {
                return "$seconds Seconds";
            }

            /* if one minute or more */
            if ($seconds >= 60 && $seconds < 3600) {
                $minutes = ($seconds / 60);
                $minutesPhrase = ($minutes > 1) ? "Minutes" : "Minute";
                return "$minutes $minutesPhrase";
            }

            /* If hour or more*/
            if ($seconds >= 3600 && $seconds < 86400) {
                $hours = intval(($seconds / 3600));
                $minutes = intval((($seconds / 60) % 60));
                $hoursPhrase = ($hours > 1) ? "Hours" : "Hour";
                $minutesPhrase = ($minutes > 1) ? "Minutes" : "Minute";

                if ($minutes <= 0) {
                    return "$hours $hoursPhrase";
                }
                return "$hours $hoursPhrase, $minutes $minutesPhrase";
            }

            /* If day or more */
            if ($seconds >= 86400 && $seconds < 604800) {
                $days = intval(($seconds / 86400));
                $hours = intval((($seconds / 3600) % 24));
                $daysPhrase = ($days > 1) ? "Days" : "Day";
                $hoursPhrase = ($hours > 1) ? "Hours" : "Hour";

                if ($hours <= 0) {
                    return "$days $daysPhrase";
                }
                return "$days $daysPhrase, $hours $hoursPhrase";
            }

            /* if week or more */
            if ($seconds >= 604800 && $seconds < 2592000) {
                $weeks = intval(($seconds / 604800));
                $days = intval((($seconds / 86400) % 7));
                $weeksPhrase = ($weeks > 1) ? "Weeks" : "Week";
                $daysPhrase = ($days > 1) ? "Days" : "Day";
                
                if ($days <= 0) {
                    return "$weeks $weeksPhrase";
                }
                return "$weeks $weeksPhrase, $days $daysPhrase";
            }

            /* if month or more */
            if ($seconds >= 2592000) {
                $months = intval(($seconds / 2592000));
                $days = intval((($seconds / 86400) % 30));
                $monthsPhrase = ($months > 1) ? "Months" : "Month";
                $daysPhrase = ($days > 1) ? "Days" : "Day";

                if ($days <= 0) {
                    return "$months $monthsPhrase";
                }
                return "$months $monthsPhrase, $days $daysPhrase";
            }
        }

        public function getKbanInfoFromID($id) {
            $stmt = $GLOBALS['DB']->prepare("SELECT * FROM `KbRestrict_CurrentBans` WHERE `id` = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $query = $stmt->get_result();
            $result = $query->fetch_assoc();
            $stmt->close();
            return $result ?: null;
        }

        public function GetKbansNumber($steamID, $IP = "") {
            $search = (empty($steamID)) ? $IP : $steamID;
            $searchMethod = (empty($steamID)) ? "client_ip" : "client_steamid";
            
            $stmt = $GLOBALS['DB']->prepare("SELECT COUNT(*) AS total FROM `KbRestrict_CurrentBans` WHERE `$searchMethod` = ?");
            $stmt->bind_param("s", $search);
            $stmt->execute();
            $queryA = $stmt->get_result();
            $row = $queryA->fetch_assoc();
            $stmt->close();
            $rows = intval($row['total'] ?? 0);
            return $rows;
        }
        
        public function GetRealKbansNumber($steamID, $IP = "") {
            $search = (empty($steamID)) ? $IP : $steamID;
            $searchMethod = (empty($steamID)) ? "client_ip" : "client_steamid";

            $stmt = $GLOBALS['DB']->prepare("SELECT COUNT(*) AS total FROM `KbRestrict_CurrentBans` WHERE `$searchMethod` = ? AND `is_removed` = 0");
            $stmt->bind_param("s", $search);
            $stmt->execute();
            $queryA = $stmt->get_result();
            $row = $queryA->fetch_assoc();
            $stmt->close();
            $rows = intval($row['total'] ?? 0);
            return $rows;
        }

        public function addNewKban($playerNameA, $playerSteamID, $length, $reasonA) {
            $admin = new Admin();
            $admin->UpdateAdminInfo();
            $adminName = $admin->adminUser;
            $adminSteamID = $admin->adminSteamID;

            $playerName = Utility::sanitizeInput($playerNameA);
            $reason = Utility::sanitizeInput($reasonA);
            $lengthInMinutes = ($length / 60);
            $time_stamp_start = time();
            $time_stamp_end = 0;
            if($lengthInMinutes !== 0) {
                $time_stamp_end = ($length < 0) ? -1 : (time() + $length);
            }

            if ($length <= -1) {
                $lengthInMinutes = 30;
            } else if ($length == 0) {
                $lengthInMinutes = 0;
            }

            if ($this->IsSteamIDAlreadyBanned($playerSteamID)) {
                die();
            }

            // Use prepared statements for the insertion into `KbRestrict_CurrentBans`
            $sql = "INSERT INTO `KbRestrict_CurrentBans` 
                    (`client_name`, `client_steamid`, `client_ip`, `admin_name`, `admin_steamid`, `reason`, 
                    `map`, `length`, `time_stamp_start`, `time_stamp_end`, `is_expired`, `is_removed`, 
                    `admin_name_removed`, `admin_steamid_removed`, `time_stamp_removed`, `reason_removed`) 
                    VALUES (?, ?, 'Unknown', ?, ?, ?, 'Web Ban', ?, ?, ?, 0, 0, 'null', 'null', '0', 'null')";

            $stmt = $GLOBALS['DB']->prepare($sql);
            $stmt->bind_param("sssssiii", $playerName, $playerSteamID, $adminName, $adminSteamID, $reason, $lengthInMinutes, $time_stamp_start, $time_stamp_end);
            $stmt->execute();
            $stmt->close();

            $message = "Kban Added (";
            if ($lengthInMinutes >= 1) {
                $message .= "$lengthInMinutes Minutes";
            } else if ($lengthInMinutes == 0) {
                $message .= "Permanent";
            } else {
                $message .= "Session";
            }
            $message .= ")";

            // Use prepared statements for the insertion into `KbRestrict_weblogs`
            $sql = "INSERT INTO `KbRestrict_weblogs` 
                    (`client_name`, `client_steamid`, `admin_name`, `admin_steamid`, `message`, `time_stamp`) 
                    VALUES (?, ?, ?, ?, ?, ?)";

            $stmt = $GLOBALS['DB']->prepare($sql);
            $stmt->bind_param("sssssi", $playerName, $playerSteamID, $adminName, $adminSteamID, $message, $time_stamp_start);
            $stmt->execute();
            $stmt->close();

            echo "<script>showKbanWindowInfo(0, \"$playerName\", \"$playerSteamID\", \"$reason\", \"$lengthInMinutes minutes\");</script>";
            //echo "<script>window.location.replace('index.php?all');</script>";
        }

        public function EditKban($id, $playerNameA, $playerSteamID, $length, $reasonA) {
            $admin = new Admin();
            $admin->UpdateAdminInfo();
            $adminName = $admin->adminUser;
            $adminSteamID = $admin->adminSteamID;

            $playerName = Utility::sanitizeInput($playerNameA);
            $reason = Utility::sanitizeInput($reasonA);
            $lengthInMinutes = ($length / 60);
 
            $info = $this->getKbanInfoFromID($id);
            if ($info === null) {
                return false;
            }

            $time_stamp_end = ($info['time_stamp_start'] + $length);
            if ($length <= -1) {
                $lengthInMinutes = -1;
                $time_stamp_end = -1;
            } else if ($length == 0) {
                $lengthInMinutes = 0;
                $time_stamp_end = 0;
            }

            $time = time();
            if ($length >= 1) {
                if ($time_stamp_end < $time) {
                    $this->UnbanByID($id, "NO REASON");
                    echo "<script>window.location.replace('index.php?all');</script>";
                    die();
                }
            }

            // Use prepared statements for the UPDATE query
            $sql = "UPDATE `KbRestrict_CurrentBans` SET `client_name`=?, `client_steamid`=?, `reason`=?, `length`=?, `time_stamp_end`=? WHERE `id`=?";
            $stmt = $GLOBALS['DB']->prepare($sql);
            $stmt->bind_param("sssiii", $playerName, $playerSteamID, $reason, $lengthInMinutes, $time_stamp_end, $id);
            $stmt->execute();
            $stmt->close();

            $message = "Kban Edited (";
            if ($playerName != $info['client_name']) {
                $message .= " New Name: $playerName";
            }
            if ($playerSteamID != $info['client_steamid']) {
                $message .= " New SteamID: $playerSteamID";
            }
            if ($reason != $info['reason']) {
                $message .= " New Reason: $reason"; 
            }

            if ($lengthInMinutes != $info['length']) {
                if ($lengthInMinutes >= 1) {
                    $message .= " New Length: $lengthInMinutes Minutes";
                } else if ($lengthInMinutes == 0) {
                    $message .= " New Length: Permanent";
                } else {
                    $message .= " New Length: Session";
                }
            }

            $message .= " )";

            $playerNameOld = $info['client_name'];
            $playerSteamIDOld = $info['client_steamid'];

            // Use prepared statements for the INSERT query
            $sql = "INSERT INTO `KbRestrict_weblogs` (`client_name`, `client_steamid`, `admin_name`, `admin_steamid`, `message`, `time_stamp`) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $GLOBALS['DB']->prepare($sql);
            $stmt->bind_param("sssssi", $playerNameOld, $playerSteamIDOld, $adminName, $adminSteamID, $message, $time);
            $stmt->execute();
            $stmt->close();

            echo "<script>showKbanWindowInfo(1, \"$playerName\", \"$playerSteamID\", \"$reason\", \"$lengthInMinutes minutes\");</script>";
            //echo "<script>window.location.replace('index.php?all');</script>";
        }

        public function IsSteamIDAlreadyBanned($steamID) {
            $stmt = $GLOBALS['DB']->prepare("SELECT * FROM `KbRestrict_CurrentBans` WHERE `client_steamid` = ?");
            $stmt->bind_param("s", $steamID);
            $stmt->execute();
            $query = $stmt->get_result();
            $results = $query->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($results as $result) {
                $isActive = ($result['is_expired'] == 0 && $result['is_removed'] == 0);
                $isPermanent = ($result['time_stamp_end'] <= 0);
                $isExpired = !$isPermanent && (time() >= $result['time_stamp_end']);

                if ($isActive && ($isPermanent || !$isExpired)) {
                    return true; // Early return when a matching active ban is found
                }
            }

            return false;
        }

    }

    function IsAdminLoggedIn(): bool {
        $steamID = Admin::sessionSteamID();

        return $steamID !== null && Admin::lookupEligibleAdmin($steamID) !== null;
    }

    function EnsureCsrfToken() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    function ValidateCsrfToken($token) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $token = (string) ($token ?? '');

        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    function formatMethod(int $method) {
        $methods = ["client_steamid", "client_name", "client_ip", "admin_name", "admin_steamid", "map", "length"];
        return $methods[$method-1] ?? $methods[0];
    }

    /* A positive page number from the query string, or 1.
       `?page=abc`, `?page=0`, `?page[]=1` and `?page=99999999999999999999`
       (which overflows and stops being a valid int) all fall back to 1
       instead of flowing into the LIMIT offset as junk. */
    function currentPageFromRequest(): int {
        return (int) filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'default' => 1],
        ]);
    }

    /* The row offset for a page, computed only after the page number has been
       clamped to the real page count. A `?page=` past the end then lands on
       the last page instead of an out-of-range empty one. */
    function resultsOffset(int $currentPage, int $totalPages, int $perPage): int {
        $page = ($totalPages > 0) ? min($currentPage, $totalPages) : 1;
        return ($page - 1) * $perPage;
    }

    /* real_escape_string() does not neutralise the LIKE metacharacters, so a
       search for `a_b` or `50%` was matched as a pattern rather than as text.
       The backslashes are doubled because MySQL parses escapes in the LIKE
       pattern as well as in the surrounding string literal. Apply this before
       real_escape_string(). */
    function escapeLikeOperand(string $value): string {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    function GetRowInfo($id, $result2 = null) {
        $admin = new Admin();
        $kban = new Kban();
        
        if ($id != 0) {
            $result2 = $kban->getKbanInfoFromID($id);
        } else if ($result2 !== null) {
            $id = $result2['id'];
        }

        if ($result2 === null) {
            return;
        }

        $clientName         = $result2['client_name'];
        $clientSteamID      = $result2['client_steamid'];
        $clientIP           = $result2['client_ip'];
        $adminSteamID       = $result2['admin_steamid'];
        $reason             = $result2['reason'];
        $map                = $result2['map'];
        $time_stamp_start   = $result2['time_stamp_start'];
        $time_stamp_end     = $result2['time_stamp_end'];
        $isExpired          = ($result2['is_expired'] == 1);
        $isRemoved          = ($result2['is_removed'] == 1); 
        $adminNameRemoved   = $result2['admin_name_removed'];
        $time_stamp_removed = $result2['time_stamp_removed'];
        $reason_removed     = $result2['reason_removed'];

        $adminName = $admin->GetAdminNameFromSteamID($adminSteamID);

        $length = $kban->formatLength(($time_stamp_end - $time_stamp_start));
        $expiresOn = "";
        if ($time_stamp_end == 0) {
            $length = "Permanent";
            $expiresOn = "Never";
        } else if ($time_stamp_end <= -1) {
            $length = "Session";
            $expiresOn = "Temporary";
        }

        $status = "Kban Active";
        if ($isExpired && !$isRemoved || ($time_stamp_end >= 1 && time() > $time_stamp_end)) {
            $status = "Kban Expired";
        }

        if ($isRemoved) {
            $status = "Kban Removed";
        }

        echo "<div class='kban-buttons'>";
        
         $searchMethod = 1;
        if (IsAdminLoggedIn()) {
            if ($clientSteamID == "NO STEAMID") {
                $href = "ViewPlayerHistory(\"$clientIP\", 3)";
                $searchMethod = 3;
            } else {
                $href = "ViewPlayerHistory(\"$clientSteamID\", 1);";
                $searchMethod = 1;
            }
        } else {
            if ($clientSteamID != "NO STEAMID") {
                $href = "ViewPlayerHistory(\"$clientSteamID\", 1);";
                $searchMethod = 1;
            } else {
                $searchMethod = 3;
            }
        }

        if (IsAdminLoggedIn() || ($searchMethod == 1 && !IsAdminLoggedIn())) {
            echo "<button onclick='$href' class='button button-light' title='View History'><i class='fa-solid fa-clock-rotate-left'></i>&nbspView History</button>";
        }

        if (IsAdminLoggedIn()) {
            $admin->UpdateAdminInfo();

            if (($time_stamp_end < 1 && $isRemoved == false && $isExpired == false) || ($time_stamp_end >= 1 && time() < $time_stamp_end && $isRemoved == false && $isExpired == false)) {
            
                if ($admin->DoesHaveFullAccess() || $adminSteamID == $admin->adminSteamID) {
                    $editFunction = "EditFromID(\"$id\")";
                    echo "<button class='button button-primary' title='Edit' onclick='$editFunction'><i class='fa-regular fa-pen-to-square'></i>&nbspEdit Details</button>";
                    $unbanFunction = "ConfirmUnban($id, \"$clientName\", \"$clientSteamID\");";
                    echo "<button class='button button-important' title='Unban' onclick='$unbanFunction'><i class='fas fa-undo fa-lg'></i>&nbspUnban</button>";
                }
            } else {
                if ($clientSteamID != "NO STEAMID" && !$kban->IsSteamIDAlreadyBanned($clientSteamID)) {
                    $reBanFunction = "RebanFromID(\"$id\");";
                    echo "<button class='button button-important' title='Reban' onclick='$reBanFunction'><i class='fas fa-redo fa-lg'></i>&nbspReban</button>";
                }
            }
        }

        if ($admin->DoesHaveFullAccess()) {
            $deleteFunction = "RemoveKbanFromDBCheck($id);";
            echo "<button class='button button-important' title='Delete' onclick='$deleteFunction'><i class='fa-solid fa-trash'></i>&nbspDelete KBan</button>";
        }

        if (!IsAdminLoggedIn()) {
            $href = "Login();";
            echo "<button onclick='$href' class='button button-success' title='Sign in'>Admin? Sign in</button>";
        }

        echo "</div>";

        $date = new DateTime("now", new DateTimeZone(DATE_TIME_ZONE));
        $date->setTimestamp($time_stamp_start);
        $startDate  = $date->format(DATE_TIME_FORMAT);

        $date->setTimestamp($time_stamp_end);
        $endDate    = ($expiresOn !== "") ? $expiresOn : $date->format(DATE_TIME_FORMAT);

        echo "<ul class='kban_details'>";

        echo "<li>";
        echo "<span><i class='fas fa-user'></i> Player</span>";
        echo "<span>$clientName</span>";
        echo "</li>";

        $steam = new Steam();
        $clientSteamID3 = $steam->SteamID_To_SteamID3($clientSteamID);
        $clientSteamID64 = $steam->SteamID_To_SteamID64($clientSteamID);
        echo "<li>";
        echo "<span><i class='fab fa-steam-symbol'></i> Steam ID</span>";
        echo "<span>$clientSteamID</span>";
        echo "</li>";

        echo "<li>";
        echo "<span><i class='fab fa-steam-symbol'></i> Steam3 ID</span>";
        echo "<span><a href='https://steamcommunity.com/profiles/$clientSteamID64' target='_blank'>$clientSteamID3</a></span>";
        echo "</li>";

        echo "<li>";
        echo "<span><i class='fab fa-steam-symbol'></i> Steam Community</span>";
        echo "<span><a href='https://steamcommunity.com/profiles/$clientSteamID64' target='_blank'>$clientSteamID64</a></span>";
        echo "</li>";

        if (IsAdminLoggedIn() && $admin->DoesHaveFullAccess()) {
            echo "<li>";
            echo "<span><i class='fas fa-network-wired'></i> IP address</span>";
            if ($clientIP == "Unknown" || $clientIP == "unknown") {
                echo "<span>$clientIP</span>";
            } else {
                echo "<span><a href='https://www.infobyip.com/ip-$clientIP.html' target='_blank'>$clientIP</a></span>";
            }
            echo "</li>";
        }

        echo "<li>";
        echo "<span><i class='fas fa-play'></i> Invoked on</span>";
        echo "<span>$startDate</span>";
        echo "</li>";

        echo "<li>";
        echo "<span><i class='fas fa-hourglass-half'></i> KBan Duration</span>";
        echo "<span>$length</span>";
        echo "</li>";

        echo "<li>";
        echo "<span><i class='fas fa-clock'></i> Expires on</span>";
        echo "<span>$endDate</span>";
        echo "</li>";

        echo "<li>";
        echo "<span><i class='fas fa-question'></i> Reason</span>";
        echo "<span>$reason</span>";
        echo "</li>";

        echo "<li>";
        echo "<span><i class='fas fa-ban'></i> Banned by Admin</span>";
        echo "<span>$adminName</span>";
        echo "</li>";

        echo "<li>";
        echo "<span><i class='fa-solid fa-circle-exclamation'></i> KBan Status</span>";
        echo "<span>$status</span>";
        echo "</li>";

        if ($isRemoved) {
            $date->setTimestamp($time_stamp_removed);
            $removedDate = $date->format(DATE_TIME_FORMAT);

            echo "<li>";
            echo "<span><i class='fas fa-play'></i> Unbanned on</span>";
            echo "<span>$removedDate</span>";
            echo "</li>";

            echo "<li>";
            echo "<span><i class='fas fa-ban'></i> Unbanned By Admin</span>";
            echo "<span>$adminNameRemoved</span>";
            echo "</li>";

            echo "<li>";
            echo "<span><i class='fas fa-question'></i> Unban Reason</span>";
            echo "<span>$reason_removed</span>";
            echo "</li>";
        }

        echo "<li>";
        echo "<span><i class='fa-solid fa-gamepad'></i> Map</span>";
        if ($map != "Web Ban" && $map != "From Web") {
            $fastdl = $GLOBALS['SERVER_FASTDL'];
            echo "<span><a href='$fastdl/maps/$map.bsp.bz2'>$map</a></span>";
        } else {
            echo "<span>$map</span>";
        }
        echo "</li>";

        echo "</ul>";
        
    }

    function GetKbanLengths($addTag = true) {
        if($addTag == true) {
            echo "<select id='add-select' class='select add-select'>";
        }
        echo "<optgroup label='Minutes'>";
        for($second = 1; $second < 3600; $second++) {
            /* we want 10, 30, and 50 minutes */
            if ($second == (10*60) || $second == (30*60) || $second == (50*60)) {
                $minutes = ($second / 60);
                $minutesToSeconds = ($minutes * 60);
                if ($second == $minutesToSeconds) { //
                    echo "<option value='$second'>$minutes Minutes</option>";
                }
            }
        }

        echo "</optgroup>";
        echo "<optgroup label='Hours'>";
        for($second = 1; $second < (3600 * 24); $second++) {
            /* we want 1, 2, 4, 8, and 16 hours */
            if ($second == (1*60*60) || $second == (2*60*60) || $second == (4*60*60) ||
                $second == (8*60*60) || $second == (16*60*60)) {
                $hours = ($second / (60 * 60));
                $hoursToSeconds = ($hours * (60 * 60));
                if ($second == $hoursToSeconds) { //
                    echo "<option value='$second'>$hours Hours</option>";
                }
            }
        }

        echo "</optgroup>";
        echo "<optgroup label='Days'>";

        for($second = 1; $second <= (3600 * 24 * 3); $second++) {
            /* we want 1, 2, 3 days */
            if ($second == (1*60*60*24) || $second == (2*60*60*24) || $second == (3*60*60*24)) {
                $days = ($second / (60 * 60 * 24));
                $daysToSeconds = ($days * (60 * 60 * 24));
                if ($second == $daysToSeconds) { //
                    echo "<option value='$second'>$days Days</option>";
                }
            }
        }

        echo "</optgroup>";
        echo "<optgroup label='Weeks'>";
        
        for($second = 1; $second <= (3600 * 24 * 7 * 3); $second++) {
            /* we want 1, 2, 3 weeks */
            if ($second == (1*60*60*24*7) || $second == (2*60*60*24*7) || $second == (3*60*60*24*7)) {
                $weeks = ($second / (60 * 60 * 24 * 7));
                $weeksToSeconds = ($weeks * (60 * 60 * 24 * 7));
                if ($second == $weeksToSeconds) { //
                    echo "<option value='$second'>$weeks Weeks</option>";
                }
            }
        }

        echo "</optgroup>";
        echo "<optgroup label='Months'>";
        for($second = 1; $second <= (3600 * 24 * 30 * 3); $second++) {
            /* we want 1, 2, 3 months */
            if ($second == (1*60*60*24*30) || $second == (2*60*60*24*30) || $second == (3*60*60*24*30)) {
                $months = ($second / (60 * 60 * 24 * 30));
                $monthsToSeconds = ($months * (60 * 60 * 24 * 30));
                if ($second == $monthsToSeconds) { //
                    echo "<option value='$second'>$months Months</option>";
                }
            }
        }
        
        echo "</optgroup>";
        $admin = new Admin();
        $admin->UpdateAdminInfo();
        if ($addTag == false || $admin->DoesHaveFullAccess()) {
            echo "<optgroup label='Others'>";
            echo "<option value='0'>Permanent</option>";
            if($addTag == false) {
                echo "<option value='-1'>Session</option>";
                echo "<option value='-2'>Custom (in Minutes)</option>";
            }

            echo "</optgroup>";
        }

        if($addTag == true) {
            echo "</select>";
        }
    }

    function GetKbanLengthTypes() {
        echo "<select id='edit-select' class='select edit-select'>";
        echo "<option value='2' selected>Minutes</option>";
        echo "<option value='3'>Hours</option>";
        echo "<option value='4'>Days</option>";
        echo "<option value='5'>Weeks</option>";
        echo "<option value='6'>Months</option>";
        echo "</select>";
    }
?>
