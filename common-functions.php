<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('PATH' , '/' . trim( strtok($_SERVER["REQUEST_URI"], '?') , '/' ));
define('PATH_ARRAY' , [ ...array_filter(explode('/' , PATH)) ]);
require_once __DIR__. '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function postvar( string|int $var , &$assign_to , string|int $equals_to = null ):bool {

    if( !array_key_exists( $var , $_POST ) ){
        return false;
    }

    
    if( $equals_to !== null && $_POST[$var] !== $equals_to ) {
        return false;
    }
    
    $assign_to = $_POST[$var];  
    return true;

}

function new_room_with_memebers( array $room_members , string $room_type ,  $pdo ) :int|false {


    $iq = $pdo->prepare("insert into chat_rooms ( room_type , last_active ) values ( ? , unix_timestamp() ) ");
    $iq->execute([$room_type]);

    if( $iq->rowCount() ) {

        $last_insert_id = $pdo->lastInsertId();

        $iq_room_member = $pdo->prepare("insert  into room_members ( room_id , user_id ) values ( $last_insert_id , ? )");

        foreach ( array_unique($room_members) as $room_member ) {
            $iq_room_member->execute( [$room_member] );
        }   

        return $last_insert_id;

    }

    return false;

}

function get_messages_by_room( int $room_id , int $limit , int $offset , $pdo ) :array {

    $sq = $pdo->prepare("SELECT chat.id, chat.sender , chat.sent_at, chat.message , csl.user_id is not null as is_seen FROM chat left join chat_seen_log csl on chat.id = csl.message_id WHERE room_id = :room_id ORDER BY sent_at LIMIT :l OFFSET :os");
    $sq->execute([ 'room_id' => $room_id , 'l' => $limit , 'os' => $offset ]);

    return $sq->fetchAll();

}

function getvar( string|int $var , &$assign_to , string|int $equals_to = null ):bool {

    if( !array_key_exists( $var , $_GET ) ){
        return false;
    }

    
    if( $equals_to !== null && $_GET[$var] !== $equals_to ) {
        return false;
    }
    
    $assign_to = $_GET[$var];  
    return true;

}

function jwt_encode( $payload ) {

    return JWT::encode($payload, JWT_SECRET_KEY , 'HS256');

} 

function jwt_decode( $jwt ) :stdClass|false {

    try {
        $output = JWT::decode($jwt, new Key(JWT_SECRET_KEY, 'HS256'));
    } catch (\Throwable $e) {
        $output = false;
    }

    return $output; 
    
}

function loginable_user( string $username , string $password , &$userdata ) :bool {

    global $pdo;

    $sq = $pdo->prepare("select * from users where username = :uname");
    $sq->execute(['uname' => trim($username)]);

    if( $ud = $sq->fetch() ){

        
        if( password_verify($password , $ud['password']) ){
            unset($ud['password']);

            $userdata = $ud;
            $jwt = jwt_encode( [
                'id' => $ud['id'],
                'valid_till' => time()+AUTH_TOKEN_LIFETIME
            ]);  
            $userdata['jwt_token'] = $jwt;
            return true;
        }

    }

    return false;

}

function is_loggedin() :bool {

    return array_key_exists( 'user' , $_SESSION ) && array_key_exists( 'id' , $_SESSION['user'] );

}

function is_safe_password( string $password ) :bool {
    
    return strlen($password) > 5;

}

function is_valid_username( string $username ) :bool {

    return strlen($username) > 5;

}

function username_exists( string $username ) :bool {

    global $pdo;
    $sq = $pdo->prepare("select id from users where username = :uname");
    $sq->execute(['uname' => $username ]);

    return !!$sq->rowCount();

}

function println( string $m ) {
    echo $m . PHP_EOL;
}