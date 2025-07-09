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
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
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

// $allowedOrigin = $appEnv === 'development' ? 'http://localhost:3000' : 'https://calva-corro-system.vercel.app';

// // Registrar allowedOrigin en el contenedor para que sea accesible en el middleware CORS
// $container['settings']['allowedOrigin'] = $allowedOrigin; // <--- ESTA LÍNEA YA ESTABA CORRECTA

$allowedOrigins = [
    'https://calva-corro-system.vercel.app', // Tu dominio principal de Vercel
    'https://calva-corro-system-8f1vj9lxk-lucasmontiel358-4941s-projects.vercel.app', // El subdominio específico de tu despliegue actual
];

// Añadir localhost solo si estás en desarrollo
$appEnv = $_ENV['APP_ENV'] ?? 'development';
if ($appEnv === 'development') {
    $allowedOrigins[] = 'http://localhost:3000';
}

// --- CORS ---
// AHORA MODIFICAMOS EL MIDDLEWARE Y LA RUTA OPTIONS PARA USAR ESTA LISTA

// MODIFICADO: Inyectar el contenedor Y la lista de orígenes permitidos
$app->options('/{routes:.+}', function (Request $request, Response $response) use ($container, $allowedOrigins) {
    $origin = $request->getHeaderLine('Origin'); // Obtener el origen de la solicitud
    
    // Verificar si el origen de la solicitud está en nuestra lista de orígenes permitidos
    if (in_array($origin, $allowedOrigins)) {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin) // Responder con el origen que hizo la solicitud (si está permitido)
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    }
    // Si el origen no está en la lista, no incluir Access-Control-Allow-Origin para que el navegador lo bloquee.
    return $response
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

// MODIFICADO: Inyectar el contenedor Y la lista de orígenes permitidos
$app->add(function (Request $request, Response $response, $next) use ($container, $allowedOrigins) {
    $response = $next($request, $response);
    
    $origin = $request->getHeaderLine('Origin');
    if (in_array($origin, $allowedOrigins)) {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    }
    
    return $response
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