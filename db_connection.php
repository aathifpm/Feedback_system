<?php
$db_host = 'srv1880.hstgr.io';
$db_user = 'u518745130_panimalar';
$db_pass = 'cY;8^Z=LY~0#';
$db_name = 'u518745130_CFP_ADS';

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>