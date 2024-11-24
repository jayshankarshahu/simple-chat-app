<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__. '/conf.php';
require_once __DIR__. '/common-functions.php';



$loader = new \Twig\Loader\FilesystemLoader(__DIR__. '/templates');
$twig = new \Twig\Environment($loader, [
    // 'cache' => '/path/to/compilation_cache',
]);

function render_and_die( string $template , array $data , int $resp_code = 200 ) {

    global $twig;

    http_response_code($resp_code);
    $twig->display( $template , array_merge( $data , ['conf' => APP_CONSTANTS] ) );
    die;

};

function ajax_response( bool $success , string $message , ?array $data = null ) :void {

    $resp = ['success' => $success , 'message' => $message];
    if( $data !== null ) {
        $resp['data'] = $data;
    }

    die(json_encode($resp));

}

if( PATH_ARRAY ) {

    if( 'ajax' === PATH_ARRAY[0] && is_loggedin() ) {

        header('Content-Type: application/json;');
        
        if( array_key_exists( 1 , PATH_ARRAY ) ) {

            if( 'connections' === PATH_ARRAY[1] ) {

                if( postvar('action' , $action) ) {

                    if( 'new-connection' === $action && postvar('username' , $username) ) {

                        if( $_SESSION['user']['username'] === $username ) {
                            ajax_response( false , "Can't connect with yourself" );
                        }

                        $sq = $pdo->prepare("select id from users where username = ?");
                        $sq->execute([$username]);

                        if( !$sq->rowCount() ) {

                            ajax_response( false , "Can't connect with yourself" );                    

                        } 
                        
                        $iq = $pdo->prepare("insert ignore into connection_requests (sender , receiver , created_at , updated_at) values(? , ? , unix_timestamp() , unix_timestamp())");
                        $iq->execute([$_SESSION['user']['id'] , $sq->fetch()['id']]);

                        if( 0 === $iq->rowCount() ) {
                            ajax_response( false , "Request already exists" );
                        }

                        ajax_response( true , "Connection request sent" );     

                    } elseif ( 'accept-connection' === $action && postvar('id' , $id) ) {

                        $pdo->beginTransaction();
                       
                        try {
                            
                            $uq = $pdo->prepare("update connection_requests set status = 'accepted' and updated_at = unix_timestamp() where id = ? and status <> 'accepted'");
                            $uq->execute([$id]);

                            if( 1 !== $uq->rowCount() ) {
                                
                                ajax_response( false , "Failed to accept request" );
                                
                            } 
                            
                            $iq = $pdo->prepare("insert into chat_rooms ( room_type , last_active ) values ( 'personal' , unix_timestamp() ) ");
                            
                            if( $iq->execute() ) {

                                $room_id = $pdo->lastInsertId();

                                $sq = $pdo->prepare("select sender from connection_requests where id = ?");
                                $sq->execute([$id]);                                
                                

                                $iq2 = $pdo->prepare("insert into room_members ( room_id , user_id ) values ( ? , ? ) ( ? , ? )");
                                $iq2->execute( [$room_id , $_SESSION['user']['id'] , $room_id , $sq->fetch()['sender']] );

                            }

                        } catch (\PDOException $th) {
                            
                            $pdo->rollBack();
                            ajax_response( false , $th->getMessage() );

                        }
                        
                        $pdo->commit();
                        ajax_response( true , "Connection accepted" );

                    } elseif ( 'reject-connection' === $action && postvar('id' , $id) ) {

                        $uq = $pdo->prepare("update connection_requests set status = 'rejected' and updated_at = unix_timestamp() where id = ? and status <> 'rejected'");                        
                        $uq->execute([$id]);

                        if( 1 !== $uq->rowCount() ) {
                                
                            ajax_response( false , "Failed to reject request" );
                            
                        }

                        ajax_response( true , "Connection rejected" );

                    }


                }
           

            } elseif ( 'search-user' === PATH_ARRAY[1] && getvar('q' , $query) ) {

                getvar('strict' , $strict);

                if( $strict ) {

                    $sq = $pdo->prepare("select username , profile_picture from users where username = ?");
                    $sq->execute([$query]);

                } else {

                    $sq = $pdo->prepare("select username , profile_picture from users where username like ?");
                    $sq->execute(["%$query%"]);

                }
                
                ajax_response( true , "" , $sq->fetchAll() );
                
            } elseif ( 'chat' === PATH_ARRAY[1] && is_loggedin() && array_key_exists( 'HTTP_AUTHTOKEN' , $_SERVER ) ) {

                $user_data = jwt_decode( $_SERVER['HTTP_AUTHTOKEN'] );

                if( false === $user_data ) {
                    ajax_response( false , "Authentication Failure" );
                }

                if( 'chatlist' === PATH_ARRAY[2] ) {

                    $sq = $pdo->prepare("select rm.user_id user_id , rm.room_id room_id , users.username username from room_members rm join (select room_id , user_id from room_members where user_id = ?) rms on rms.room_id = rm.room_id and rm.user_id <> rms.user_id join users on users.id = rm.user_id group by room_id , user_id , username;");

                    // $sq = $pdo->prepare("select u.id , u.username , u.profile_picture from users u join connection_requests cr on u.id in ( cr.sender , cr.receiver ) where cr.status = 'accepted' and ? in ( cr.sender , cr.receiver )");
                    $sq->execute([$user_data->id]);

                    ajax_response( true , "" , $sq->fetchAll() );

                } elseif ( 'messages' === PATH_ARRAY[2] && getvar( 'offset' , $offset ) ) {

                    if( getvar( 'room_id' , $room_id ) && $room_id ) {

                        $sq = $pdo->prepare("select room_id from room_members where room_id = ? and user_id = ?");
                        $sq->execute([$room_id , $user_data->id ]);

                        if( false === $sq->fetch() ) {
                            ajax_response( true , "" , null );
                        }

                        ajax_response( true , "" , ['messages' => get_messages_by_room( $room_id , CHAT_ROOMS::MAX_MESSAGE_PER_PAGE , $offset , $pdo )] );

                    } else if ( getvar('username' , $username ) && $username ) {

                        $sq = $pdo->prepare('select id from users where username = ?');
                        $sq->execute([$username]);

                        $u = $sq->fetch();

                        if( false === $u ) {
                            ajax_response( false , "User not found" , null );
                        }

                        $sq = $pdo->prepare("select current_user_rooms.room_id room_id from ( select room_id from room_members where user_id = ? ) current_user_rooms join ( select room_id from room_members where user_id = ? ) other_user_rooms on current_user_rooms.room_id = other_user_rooms.room_id");
                        $sq->execute( [ $user_data->id , $u['id'] ] );

                        $room_ids = $sq->fetch();

                        if( false === $room_ids ) {

                            $room_id = new_room_with_memebers( [ $user_data->id , $u['id'] ] , CHAT_ROOMS::ROOM_TYPE_PERSONAL , $pdo );

                            ajax_response( true , "" , [ 'messages' => [] , 'room_id' => $room_id ] );

                        } else {

                            ajax_response( true , "" , ['messages' => get_messages_by_room( $room_ids['room_id'] , CHAT_ROOMS::MAX_MESSAGE_PER_PAGE , $offset , $pdo )] );

                        }

                    }

                    ajax_response( false , "Invalid Request" );
                    

                }
                
            }

        }

        ajax_response( false , 'Oops, Something went wrong!' );

    } elseif ( 'signup' === PATH_ARRAY[0] && !is_loggedin()) {

        render_and_die('signup.html' , []);

    } elseif( 'login' === PATH_ARRAY[0] && !is_loggedin()) {

        render_and_die('login.html' , []);

    } elseif( 'logout' === PATH_ARRAY[0]) {

        PHP_SESSION_ACTIVE !== session_status() && session_start();
        session_unset();
        session_destroy();
        header("Location: /");

    } elseif( 'users' === PATH_ARRAY[0] ) {

        $username = array_key_exists( 1 , PATH_ARRAY ) ? trim(PATH_ARRAY[1]) : '';

        $sustmt = $pdo->prepare("select username , password , profile_picture from users where username = :username");
        $sustmt->execute(['username' => $username]);

        if( $sustmt->rowCount() ) {

            render_and_die( 'user-profile.html' , [ 'userdata' => $sustmt->fetch()] );

        } else {

            render_and_die('search-users.html' , []);

        }


    }

}



if( postvar('action' , $action , 'login') && postvar('username' , $username) && postvar('password' , $password) ) {
    
    if( !loginable_user( $username , $password , $userdata ) ) {

        render_and_die( 'login.html' , ['error' => 'invalid username or password'] );
        
    }

    if( !getvar('return_url' , $return_url) ) {
        $return_url = "/";
    }

    $_SESSION['user'] = $userdata;
    header('Location: '.$return_url);
    die;

}

if( postvar('action' , $action , 'signup') && postvar('username' , $username) && postvar('password' , $password) ) {

    $username = trim($username);
    $password = trim($password);
    
    if( is_valid_username($username) && is_safe_password($password) ) {

        $iq = $pdo->prepare('insert ignore into users ( username , password ) value ( :username , :password )');
        $iq->execute(['username' => $username , 'password' => password_hash($password , PASSWORD_BCRYPT)]);

        if( $iq->rowCount() === 1 ) {

            //assign userdata
            loginable_user( $username , $password , $userdata);

            $_SESSION['user'] = $userdata;

            if( !getvar('return_url' , $return_url) ) {
                $return_url = "/";
            }

            header('Location: '.$return_url);
            die;    

        }

    }

}

if( is_loggedin() ) {

    if( array_key_exists( 'jwt_token' , $_SESSION['user'] ) ){

        render_and_die( 'chat.html' , ['user' => $_SESSION['user']] );  

    }
    
}

render_and_die( 'landing.html' , []);