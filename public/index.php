<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\App;
use Kreait\Firebase\Factory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
// use Slim\Middleware\ContentLengthMiddleware; // Comentado para evitar el error de clase no encontrada

$config = [
    'settings' => [
        'displayErrorDetails' => true, // Para debug, poner false en producción
    ],
];

// Configuración adicional para Slim 3 para Content-Length Middleware
$app = new App($config);

// Crear instancia de Firebase
$firebase = (new Factory)
    ->withServiceAccount(__DIR__ . '/../credentials.json')
    ->withDatabaseUri('https://calva-corro-bd-default-rtdb.firebaseio.com'); // URL correcta aquí

$firebaseDb = $firebase->createDatabase();

// Registrar Firebase en el contenedor
$container = $app->getContainer();
$container['firebaseDb'] = function() use ($firebaseDb) {
    return $firebaseDb;
};

// --- CRÍTICO: Incluir funciones auxiliares de Drive UNA SOLA VEZ ---
require __DIR__ . '/../src/utils/drive_helpers.php'; // <--- ¡Esta línea debe estar aquí y solo una vez!

// --- CORS Middleware ---
// Esto debe ir ANTES de cargar las rutas
$app->options('/{routes:.+}', function (Request $request, Response $response) {
    // Maneja las solicitudes OPTIONS preflight.
    // Simplemente devuelve una respuesta 200 OK con los encabezados CORS adecuados.
    return $response;
});

$app->add(function (Request $request, Response $response, $next) {
    // Configura los encabezados CORS para permitir solicitudes desde cualquier origen
    // En producción, deberías reemplazar '*' con el dominio específico de tu frontend.
    $response = $next($request, $response);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*') // O tu dominio específico: 'http://localhost:3000'
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

// Middleware para Content-Length (opcional pero recomendado por Slim)
// Si esta línea da error, significa que la clase no se encuentra. Es mejor comentarla
// si no se ha podido instalar la dependencia 'slim/http-cache' correctamente.
// $app->add(new ContentLengthMiddleware()); // Comentado para evitar errores de clase no encontrada


// Cargar rutas
require __DIR__ . '/../src/cliente.php';
require __DIR__ . '/../src/usuario.php';
require __DIR__ . '/../src/expedientes.php';
require __DIR__ . '/../src/otros.php';

$app->run();
