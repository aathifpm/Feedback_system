<?php
require_once 'session_config.php';
BrowserSessionHandler::destroySession();
header('Location: index.php');
exit();
?>