<?php
require 'AsyncHttp.php';
$options = array(
        'host' => '192.168.62.15',
        'port'     => 80,
        'path'     => '/a.php',
        'method'   => 'POST',
        'params'   => '{"user":"root","pwd":"123"}',
        'headers'  => array(
                'Content-Type'   => 'application/json',
                'Content-Length' => 27
        ),
        'callback' => 'http://192.168.62.15/notify.php'
);

AsyncHttp::request($options);
echo 'invoke OK' . PHP_EOL;
