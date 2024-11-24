<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require dirname(__DIR__) . '/vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


$loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/templates');
$twig = new \Twig\Environment($loader, [
    // 'cache' => '/path/to/compilation_cache',
]);

$twig->display('socket-test.html');