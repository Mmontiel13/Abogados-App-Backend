<?php
use Kreait\Firebase\Factory;
use Kreait\Firebase\Auth;
use Kreait\Firebase\Storage;

require_once __DIR__ . '/../vendor/autoload.php';

$firebase = (new Factory)
    ->withServiceAccount(__DIR__ . '/../firebase_credentials.json')
    ->withDatabaseUri('https://calva-corro-bd-default-rtdb.firebaseio.com/');

// Puedes usar los servicios que necesites:
$auth = $firebase->createAuth();
$storage = $firebase->createStorage();
$database = $firebase->createDatabase(); // Si usas Realtime DB
