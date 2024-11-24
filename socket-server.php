<?php

ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/php-error.log");

require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;


require_once __DIR__ . '/conf.php';
require_once __DIR__ . '/common-functions.php';

function unserialize_session($session_data, $start_index=0, &$dict=null) {
    isset($dict) or $dict = array();

    $name_end = strpos($session_data, SESSION_DELIM, $start_index);
    
    if ($name_end !== FALSE) {
        $name = substr($session_data, $start_index, $name_end - $start_index);
        $rest = substr($session_data, $name_end + 1);
    
        $value = unserialize($rest);
        $dict[$name] = $value;
    
        return unserialize_session($session_data, $name_end + 1 + strlen(serialize($value)), $dict);
    }
    
    return $dict;
}

function get_session_by_sess_id( string $sess_id ) :array {

    $session_file = SESSION_SAVE_PATH . DIRECTORY_SEPARATOR . SESSION_FILE_PREFIX . $sess_id;

    if( !is_file($session_file) ){
        return [];
    }

    return unserialize_session(file_get_contents($session_file));
}


class MyChat implements MessageComponentInterface {

    protected array $clients;
    protected array $uuid_to_id_cache;
    protected array $room_members_cache;
    private PDOStatement $send_message_stmt;
    private PDOStatement $get_members_stmt;
    private PDOStatement $mark_as_read_stmt;
    public static string $RESP_MSG = 'msg';
    public static string $RESP_ACTION = 'action';
    public static string $RESP_ERR = 'error';
    public static string $STATUS_DELIVERED = 'dlvrd';
    public static string $STATUS_READ = 'rd';

    public function __construct() {

        global $pdo;
        
        $this->clients = [];
        $this->uuid_to_id_cache = [];
        $this->room_members_cache = [];
        $this->send_message_stmt = $pdo->prepare('INSERT INTO chat (sender, room_id, sent_at, message , media_url ) VALUES (:sender, :room_id , floor(unix_timestamp(now(3))*1000) , :message , :media_url )');       
        $this->get_members_stmt = $pdo->prepare("select user_id from room_members where room_id = ?");
        $this->mark_as_read_stmt = $pdo->prepare("insert ignore into chat_seen_log ( message_id , user_id , seen_at ) select chat.id , room.user_id , floor(unix_timestamp(now(3))*1000) time from chat join room_members room on chat.room_id = room.room_id where chat.id = ? and chat.room_id = ? and room.user_id = ?");
        
    }

    /*
    private function uuid_to_id( string $uuid ) :int|false {

        if( array_key_exists( $uuid , $this->uuid_to_id_cache ) ) {
            return $this->uuid_to_id_cache[$uuid];
        }

        global $pdo;

        $sq = $pdo->prepare('select id from users where uuid = :uuid');
        $sq->execute(['uuid' => $uuid]);

        if( $r = $sq->fetch() ){
            $this->uuid_to_id_cache[$uuid] = $r['id'];
            return $r['id'];
        }

        return false;

    }
    */

    private function attatch_client( string $id , ConnectionInterface $conn ) {

        $conn->user_id = $id;
        $this->clients[$id] = $conn;        

    }

    private function detatch_client( string $id ) {

        if( array_key_exists( $id , $this->clients ) ){
            $this->clients[$id]->close();
        }

        unset($this->clients[$id]);
        
    }   

    private function get_connection_getvars(ConnectionInterface $conn) :array{

        $query = parse_url($conn->httpRequest->getUri(), PHP_URL_QUERY);
                
        parse_str($query, $params);

        return $params;
    }

    private function get_room_members( int $room_id ) {

        if( array_key_exists( $room_id , $this->room_members_cache ) ) {
            $room_members = $this->room_members_cache[$room_id];
        } else {
            $this->get_members_stmt->execute([$room_id]);
            $room_members = array_column( $this->get_members_stmt->fetchAll() , 'user_id');
            $this->room_members_cache[$room_id] = $room_members;
        }

        return $room_members;

    }

    public static function get_output_json( $data , $type ) {
        
        return json_encode(['event' => $type , 'data' => $data]);

    }

    public function onOpen(ConnectionInterface $conn) {

        $getvars = $this->get_connection_getvars($conn);

        $f_off = function () use($conn) {
            $conn->send( self::get_output_json( ['m' => 'authorisation failure!'] , self::$RESP_ERR ) );
            $conn->close();
        };        
        
        !array_key_exists( 'authtoken' , $getvars ) && $f_off();

        ($user_data = jwt_decode($getvars['authtoken'])) || $f_off();
        
        ( !property_exists($user_data , 'valid_till') || $user_data->valid_till < time() ) && $f_off();
        
        $this->attatch_client( $user_data->id , $conn );
        
    }

    public function onMessage(ConnectionInterface $from, $m) {

        $message = json_decode($m , true)??[];

        if( !$message || !array_key_exists( 'event' , $message ) || !array_key_exists( 'data' , $message ) ) {
            println("Invalid Message");
            return;
        } 

        $data = $message['data'];

        switch ($message['event']) {

            case 'msg':
                $this->send_message( $from , $data );                
                break;
            case 'rd':
                if( array_key_exists('room_id' , $data ) && array_key_exists( 'm_id' , $data ) ) {

                    $room_id = $data['room_id'];
                    $message_id = $data['m_id'];

                    $this->mark_as_read_stmt->execute( [ $message_id , $room_id , $from->user_id ] );
                    
                    if( $this->mark_as_read_stmt->rowCount() ) {

                        foreach( $this->get_room_members( $room_id ) as $room_member ) {
                            
                            if( $room_member != $from->user_id && array_key_exists( $room_member , $this->clients ) ) {
                                $this->clients[$room_member]->send(self::get_output_json( [ 'type' => self::$STATUS_READ , 'm_id' => $message_id ] , self::$RESP_ACTION ));
                            }
                        }

                        $this->mark_as_read_stmt->fetchAll();

                    }
                    

                }
                break;
            default:
                # code...
                break;

        }

    }

    private function send_message( ConnectionInterface $from , array $data ) :bool {

        global $pdo;
        
        $sent = false;
        $sender = $from->user_id;

        try {

            $room_id = $data['room_id'];
            
            $md = [
                'sender' => $sender,
                'room_id' => $room_id,
                'message' => $data['m'],
                'media_url' => isset($data['murl']) ? $data['murl'] : null
            ];

            $sent = !!$this->send_message_stmt->execute($md);

            if( !$sent ) {
                return false;
            }   

            $from->send(self::get_output_json( [ 'type' => self::$STATUS_DELIVERED , 'temp_uuid' => $data['temp_uuid'] , 'id' => $pdo->lastInsertId() ] , self::$RESP_ACTION ));

            $message_id = $pdo->lastInsertId();

            foreach ($this->get_room_members( $room_id ) as $member_id) {                            
                
                if( $member_id != $sender && array_key_exists($member_id , $this->clients ) ) {

                    $this->clients[$member_id]->send(self::get_output_json( [ 'm' => array_merge($md , ['id' => $message_id]) ] , self::$RESP_MSG ));
                }
                
            }


        } catch (\Throwable $th) {
            $sent = false;
            println($th->getMessage());
        }
        
        return $sent;
    }

    public function onClose(ConnectionInterface $conn) {

        
        foreach ($this->clients as $id => $connection) {
            
            if( $id === $conn->user_id ) {
                $this->detatch_client($id);
                break;
            }

        }
        
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
        error_log("connection closed due to error|".$e->getMessage()."|uuid:".$conn->uuid);
    }
}


$app = new Ratchet\App(SOCKET_SERVER_HOST, SOCKET_SERVER_PORT);
$app->route('/chat', new MyChat, array('*'));
println('Chat server running at ' . SOCKET_SERVER_HOST . ':' . SOCKET_SERVER_PORT);
$app->run();