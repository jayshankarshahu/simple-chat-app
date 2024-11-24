<?php

define('DB_USER' , 'jay_user');
define('DB_PASSWORD' , 'Jay@2005');
define('DB_HOST' , 'localhost');
define('DB_NAME' , 'chat-app');
define('APP_NAME','No BS Chat');
define("AUTH_TOKEN_LIFETIME", 12*3600);
define("JWT_SECRET_KEY" , 'oNZigBhfPS19Z7tGcMnUuKSKQDGzTpFa');
define('APP_CONSTANTS' , [
    'app_name' => APP_NAME
]);
class CHAT_ROOMS {
    public const ROOM_TYPE_PERSONAL = 'personal';
    public const ROOM_TYPE_GROUP = 'group';
    public const MAX_MESSAGE_PER_PAGE = 100;
}

// define("SESSION_DELIM", "|");
// define("SESSION_FILE_PREFIX", "sess_");
define("SOCKET_SERVER_HOST" , 'localhost');
define("SOCKET_SERVER_PORT" , 2000);
date_default_timezone_set('Asia/Calcutta'); 

try {
    
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, FALSE);

} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}