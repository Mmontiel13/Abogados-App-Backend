<?php
// Habilitar la visualización de todos los errores de PHP para depuración local
// Estas líneas son útiles para desarrollo, pero Railway las gestiona de forma diferente.
// Las mantendremos aquí por si las usas para un entorno de staging local antes de Railway.
// error_reporting(E_ALL); // Comentado para no mostrar todas las advertencias en Railway
// ini_set('display_errors', 1); // Comentado

// --- MODIFICADO PARA SUPRIMIR ADVERTENCIAS EN PRODUCCIÓN ---
// En producción (Railway), no queremos que las advertencias de "Deprecated" rompan la aplicación.
// Solo mostraremos errores graves.
if (($_ENV['APP_ENV'] ?? 'development') === 'production') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE); // Ignorar advertencias y notices
    ini_set('display_errors', 0); // No mostrar errores en la salida (se irán a los logs)
    ini_set('log_errors', 1); // Asegurarse de que los errores se registren
} else {
    // Para desarrollo local, mostrar todos los errores
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 0);
}
// --- FIN MODIFICADO ---

require __DIR__ . '/../vendor/autoload.php';

// Eliminar o comentar la carga de variables de entorno desde .env,
// ya que Railway inyecta las variables directamente.
/*
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}
*/

use Slim\App;
use Kreait\Firebase\Factory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Google\Client as GoogleClient;
use Google\Service\Drive;

$appEnv = $_ENV['APP_ENV'] ?? 'development';

$config = [
    'settings' => [
        'displayErrorDetails' => $appEnv === 'development', // true para development, false para production
    ],
];
$app = new App($config);

// Obtener el contenedor de Slim
$container = $app->getContainer(); // <--- OBTENEMOS EL CONTENEDOR AQUÍ

// --- FIREBASE: Obtener credenciales desde variable de entorno ---
$firebaseJson = $_ENV['FCREDENTIALS'] ?? null;
if (!$firebaseJson) {
    throw new \Exception("No se encontró la variable de entorno FCREDENTIALS");
}

$tempFirebasePath = tempnam(sys_get_temp_dir(), 'firebase_creds_');
file_put_contents($tempFirebasePath, $firebaseJson);

$firebase = (new Factory)
    ->withServiceAccount($tempFirebasePath)
    ->withDatabaseUri($_ENV['FIREBASE_DATABASE_URI'] ?? 'https://calva-corro-bd-default-rtdb.firebaseio.com');

$firebaseDb = $firebase->createDatabase();

// Registrar Firebase en el contenedor
$container['firebaseDb'] = function() use ($firebaseDb) {
    return $firebaseDb;
};

// --- GOOGLE DRIVE: inicialización desde variable de entorno ---
$container['googleDriveService'] = function () use ($container) { // <--- AÑADIDO: use ($container)
    $credentialsJson = $_ENV['GCREDENTIALS'] ?? null;
    if (!$credentialsJson) {
        throw new \Exception("No se encontró la variable de entorno GCREDENTIALS");
    }

    $decoded = json_decode($credentialsJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception("Error al decodificar GCREDENTIALS: " . json_last_error_msg());
    }

    $tempPath = tempnam(sys_get_temp_dir(), 'gdrive_');
    file_put_contents($tempPath, json_encode($decoded));

    $googleClient = new GoogleClient();
    $googleClient->setAuthConfig($tempPath);
    $googleClient->addScope(Drive::DRIVE_FILE);
    $googleClient->fetchAccessTokenWithAssertion();

    return new Drive($googleClient);
};

$allowedOrigin = $appEnv === 'development' ? 'http://localhost:3000' : 'https://calva-corro-system.vercel.app/';

// Registrar allowedOrigin en el contenedor para que sea accesible en el middleware CORS
$container['settings']['allowedOrigin'] = $allowedOrigin; // <--- ESTA LÍNEA YA ESTABA CORRECTA

// --- CORS ---
// MODIFICADO: Inyectar el contenedor en el Closure de la ruta OPTIONS
$app->options('/{routes:.+}', function (Request $request, Response $response) use ($container) { // <--- AÑADIDO: use ($container)
    // Maneja las solicitudes OPTIONS preflight.
    return $response
        ->withHeader('Access-Control-Allow-Origin', $container->get('settings')['allowedOrigin']) // <--- MODIFICADO: $container->get()
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

// MODIFICADO: Inyectar el contenedor en el Closure del middleware CORS
$app->add(function (Request $request, Response $response, $next) use ($container) { // <--- AÑADIDO: use ($container)
    $response = $next($request, $response);
    return $response
        ->withHeader('Access-Control-Allow-Origin', $container->get('settings')['allowedOrigin']) // <--- MODIFICADO: $container->get()
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});


// --- RUTA DE PRUEBA SIMPLE ---
$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write("¡Backend de CCA App funcionando!");
    return $response->withHeader('Content-Type', 'text/plain');
});
// --- FIN RUTA DE PRUEBA SIMPLE ---

// Cargar funciones auxiliares
require __DIR__ . '/../src/utils/drive_helpers.php';

// Cargar rutas
require __DIR__ . '/../src/cliente.php';
require __DIR__ . '/../src/usuario.php';
require __DIR__ . '/../src/expedientes.php';
require __DIR__ . '/../src/otros.php';

$app->run();