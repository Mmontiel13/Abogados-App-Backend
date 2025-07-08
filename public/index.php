<?php
require __DIR__ . '/../vendor/autoload.php';

// // Cargar variables de entorno desde .env si existe
// if (file_exists(__DIR__ . '/../.env')) {
//     $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
//     $dotenv->load();
// }

use Slim\App;
use Kreait\Firebase\Factory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Google\Client as GoogleClient;
use Google\Service\Drive;

$appEnv = $_ENV['APP_ENV'] ?? 'development';
// Configuración de Slim
$config = [
    'settings' => [
        // --- MODIFICADO PARA RAILWAY ---
        // displayErrorDetails debe ser false en producción. Lo hacemos condicional.
        'displayErrorDetails' => $appEnv === 'development', // true para development, false para production
        // --- FIN MODIFICADO PARA RAILWAY ---
    ],
];
$app = new App($config);

// --- FIREBASE: Obtener credenciales desde variable de entorno ---
$firebaseJson = $_ENV['FCREDENTIALS'] ?? null;
if (!$firebaseJson) {
    throw new \Exception("No se encontró la variable de entorno FCREDENTIALS");
}

$tempFirebasePath = tempnam(sys_get_temp_dir(), 'firebase_creds_');
file_put_contents($tempFirebasePath, $firebaseJson);

$firebase = (new Factory)
    ->withServiceAccount($tempFirebasePath)
    ->withDatabaseUri('https://calva-corro-bd-default-rtdb.firebaseio.com'); // <-- Puedes mover esto a variable también

$firebaseDb = $firebase->createDatabase();

// Registrar Firebase en el contenedor
$container = $app->getContainer();
$container['firebaseDb'] = function() use ($firebaseDb) {
    return $firebaseDb;
};

// --- GOOGLE DRIVE: inicialización desde variable de entorno (si decides usarla aquí también) ---
$container['googleDriveService'] = function () {
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

$allowedOrigin = $appEnv === 'development' ? 'http://localhost:3000' : 'https://cca-app.vercel.app';
// --- CORS ---
$app->options('/{routes:.+}', function (Request $request, Response $response) {
    // Maneja las solicitudes OPTIONS preflight.
    return $response
        ->withHeader('Access-Control-Allow-Origin', $this->getContainer()->get('settings')['allowedOrigin']) // Usar el origen dinámico
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

$app->add(function (Request $request, Response $response, $next) {
    $response = $next($request, $response);
    return $response
        ->withHeader('Access-Control-Allow-Origin', $this->getContainer()->get('settings')['allowedOrigin']) // Usar el origen dinámico
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

// Registrar allowedOrigin en el contenedor para que sea accesible en el middleware CORS
$container['settings']['allowedOrigin'] = $allowedOrigin;

// Cargar funciones auxiliares
require __DIR__ . '/../src/utils/drive_helpers.php';

// Cargar rutas
require __DIR__ . '/../src/cliente.php';
require __DIR__ . '/../src/usuario.php';
require __DIR__ . '/../src/expedientes.php';
require __DIR__ . '/../src/otros.php';

$app->run();
