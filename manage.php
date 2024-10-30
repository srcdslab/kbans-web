<?php
    include('header.php');

    $admin = new Admin();
    if(!IsAdminLoggedIn()) {
        echo "<div class='container'>
        <div class='error-box'>
        <p><i class='fa-solid fa-triangle-exclamation'></i> You do not have access to this page.</p>
        </div>
        </div>
        </div>";
        die();
    }

    $reban = false;
    $edit = false;
    $add = false;

    if(isset($_GET['reban'])) {
        $reban = true;
        $oldid = $_GET['oldid'];
        $kban = new Kban();
        $info = $kban->getKbanInfoFromID(intval($oldid));
    }

    if(isset($_GET['edit'])) {
        $edit = true;
        $oldid = $_GET['oldid'];
        $kban = new Kban();
        $info = $kban->getKbanInfoFromID(intval($oldid));

        $time_stamp_end = $info['time_stamp_end'];
        if($time_stamp_end >= 1 && time() > $time_stamp_end) {
            echo "<center><h2 style='color: cyan;'>Cannot edit an old kban!</h2></center>";
            die();
        }
    }

    if(isset($_GET['add'])) {
        $add = true;

        echo "<script>setActive(3);</script>";
    }

?>

<!DOCTYPE html>

<html>
    <?php
    $text = ($edit == true) ? "Edit Kban" : "Add Kban";
    $formHeader = ($edit == true) ? "<i class='fa-regular fa-pen-to-square'></i>" : "<i class='fas fa-user-times'></i>";
    $formHeader .= " $text";
	$icon = ($edit == false) ? "<i class='fas fa-user-times'></i>" : "<i class='fa-regular fa-pen-to-square'></i>";
    ?>
    <div class="container">
        <div class="container-header">
			<h1><?php echo "$icon $text"; ?></h1>
			<div class="breadcrumb">
<i class="fas fa-angle-right"></i> <a href="index.php?all">Home</a>
<i class="fas fa-angle-right"></i> <a href="manage.php?add"><?php echo $text ?></a>
</div>
        </div>
        <div class="container-box2">
            <div class="kban-form">
                <div class="header">
                    <p><?php echo $formHeader; ?></p>
                </div>

                <div class="error">

                </div>

                <?php
                    $val = "";
                    if($edit == true || $reban == true) {
                        $val = $info['client_name'];
                    }
                ?>

                <div class="input-group">
                    <label for="name">Player Name</label>
                    <input id="playerName" type="text" class="input kban-input" max="32" value=<?php echo "\"$val\""; ?>>
                </div>

                <?php
                    $val = "";
                    if($edit == true || $reban == true) {
                        $val = $info['client_steamid'];
                    }
                ?>

                <div class="input-group">
                    <label for="steamid">Steam ID</label>
                    <?php if(empty($val)) { ?>
                        <input id="playerSteamID" type="text" class="input kban-input">
                    <?php } else { ?>
                        <input id="playerSteamID" type="text" class="input kban-input" value=<?php echo "\"$val\""; ?> title="Why the f*ck do you want to edit the SteamID? Just add a new Kban nigger" disabled>
                    <?php } ?>
                </div>

                <?php
                    $val = "";
                    if($edit == true || $reban == true) {
                        $val = $info['reason'];
                    }
                ?>

                <div class="input-group">
                    <label for="reason">Reason</label>
                    <input id="reason" type="text" class="input kban-input" max="120" value=<?php echo "\"$val\""; ?>>
                </div>

                <?php if($add == true) { ?>
                <div class="input-group">
                    <label for="length">Duration</label>
                    <?php GetKbanLengths(); ?>
                </div>
                <?php } ?>

                <?php if($add == false) {
                    $time_stamp_end = $info['time_stamp_end'];
                    $val = 0;

                    if($time_stamp_end == 0) {
                        $val = 0;
                    } else if($time_stamp_end <= -1) {
                        $val = -1;
                    } else {
                        $val = ($time_stamp_end - $info['time_stamp_start']);
                        $val = ($val / 60);
                    }
                ?>
                <div class="input-group">
                    <label for="length"> Duration </label>
					<p style="font-style: italic; color: var(--theme-text_light); margin-top: 5px;">Enter 0 minutes for a permanent ban</p>
                    <input id="length-edit" type="text" class="input kban-input" value=<?php echo "\"$val\""; ?>style="width: 110px; display: inline-block;">
                    <?php GetKbanLengthTypes(); ?>
                </div>
                <?php } ?>

                <?php
                $buttonID = ($edit == false) ? "add-button" : "edit-button";
				$buttonTextValidate = ($edit == false) ? "Add Kban" : "Save Changes";
                if(!isset($_GET['oldid'])) {
                    $oldid = "";
                }

                echo "<button class='button button-success kban-form-button' style='width: 80%; margin-left: 10%;' data-oldid='$oldid' id='$buttonID' type='submit'> $buttonTextValidate</button>";
                ?>
            </div>
        </div>
    </div>
    <?php include('footer.php'); ?>
</div>
</body>
    <script>
            $(function() {
                function verifyAndConvertSteamID(steamID, callback) {
                    $.ajax({
                        url: 'verify_steamid.php',
                        type: 'POST',
                        data: { steamid: steamID },
                        success: function(response) {
                            const result = JSON.parse(response);
                            callback(result);
                        },
                        error: function() {
                            alert('Error verifying SteamID.');
                        }
                    });
                }

            $('#add-button').on('click', function() {
                let playerName = $('#playerName').val();
                let playerSteamID = $('#playerSteamID').val();
                let reason = $('#reason').val();
                let length = 30;

                <?php if ($add == true) { ?>
                    length = $('#add-select').val();
                <?php } else { ?>
                    length = $('#length-edit').val();
                    let select = $('#edit-select').val();

                    if (select == 2) {
                        length *= 60;
                    } else if (select == 3) {
                        length *= 60 * 60;
                    } else if (select == 4) {
                        length *= 60 * 60 * 24;
                    } else if (select == 5) {
                        length *= 60 * 60 * 24 * 7;
                    } else if (select == 6) {
                        length *= 60 * 60 * 24 * 30;
                    }
                <?php } ?>

                verifyAndConvertSteamID(playerSteamID, function(response) {
                    if (response.success) {
                        addNewKban(playerName, response.steamID2, length, reason);
                    } else {
                        alert('Invalid SteamID: ' + response.error);
                    }
                });
            });

            $('#edit-button').on('click', function() {
                let id = $(this).attr('data-oldid');
                let playerName = $('#playerName').val();
                let playerSteamID = $('#playerSteamID').val();
                let reason = $('#reason').val();
                let length = $('#length-edit').val();
                let select = $('#edit-select').val();

                if (select == 2) {
                    length *= 60;
                } else if (select == 3) {
                    length *= 60 * 60;
                } else if (select == 4) {
                    length *= 60 * 60 * 24;
                } else if (select == 5) {
                    length *= 60 * 60 * 24 * 7;
                } else if (select == 6) {
                    length *= 60 * 60 * 24 * 30;
                }

                verifyAndConvertSteamID(playerSteamID, function(response) {
                    if (response.success) {
                        EditKban(id, playerName, response.steamID2, length, reason);
                    } else {
                        alert('Invalid SteamID: ' + response.error);
                    }
                });
            });
        });
    </script>
