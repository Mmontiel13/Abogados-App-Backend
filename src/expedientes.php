<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Slim\Http\UploadedFile;

require '../vendor/autoload.php';

// NOTA: La función findOrCreateFolder se ha movido a src/utils/drive_helpers.php
// Ruta POST para crear un nuevo expediente
$app->post('/case', function (Request $request, Response $response) {
    $data = $request->getParsedBody();

    $requiredFields = ['clientId', 'title', 'subject', 'date', 'place', 'court'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field]) && $data[$field] !== '0') {
            $payload = ['error' => "El campo '$field' es requerido."];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    $clients = $this->firebaseDb->getReference('clientes')->getValue() ?? [];
    $clientActive = false;
    foreach ($clients as $key => $client) {
        if ($key === $data['clientId'] && (!isset($client['activo']) || $client['activo'] === true)) {
            $clientActive = true;
            break;
        }
    }
    if (!$clientActive) {
        $payload = ['error' => 'El cliente seleccionado no está activo o no existe.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $existingCaseFiles = $this->firebaseDb->getReference('expedientes')->getValue() ?? [];
    foreach ($existingCaseFiles as $caseFileKey => $existingCaseFile) {
        if (
            strtolower(trim($existingCaseFile['title'] ?? '')) === strtolower(trim($data['title'])) &&
            trim($existingCaseFile['clientId'] ?? '') === trim($data['clientId'])
        ) {
            $payload = ['error' => 'Ya existe un expediente con el mismo título y cliente.'];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
    }

    $counterRef = $this->firebaseDb->getReference('contadores/expediente');
    $counter = $counterRef->getValue() ?? 0;
    $newCounter = $counter + 1;
    $caseFileId = sprintf("EXP-%03d", $newCounter);

    $expedienteFolderId = null;
    try {
        $googleClient = new \Google\Client();
        $credentialsPath = '.././credentials-google.json';

        if (!file_exists($credentialsPath)) {
            throw new \Exception("El archivo de credenciales no existe en: " . $credentialsPath);
        }

        $googleClient->setAuthConfig($credentialsPath);
        $googleClient->addScope(Drive::DRIVE_FILE); // Necesario para crear/gestionar archivos y carpetas
        $googleClient->setSubject(null); // Si usas una cuenta de servicio, esto es común

        $googleClient->fetchAccessTokenWithAssertion();
        $service = new Drive($googleClient);

        $myPersonalDataFilesFolderId = '1Xdb39qfZIbdPLQdg7xfh353QVeI7eQCA'; // Tu ID de carpeta raíz

        // 1. Encontrar o crear la carpeta del cliente
        $clienteFolderId = findOrCreateFolder($service, $myPersonalDataFilesFolderId, 'cliente-' . $data['clientId']);
        if (!$clienteFolderId) {
            throw new \Exception("No se pudo crear la carpeta para el cliente " . $data['clientId'] . " en Google Drive.");
        }

        // 2. Encontrar o crear la carpeta del expediente dentro de la carpeta del cliente
        $expedienteFolderName = 'expediente-' . $caseFileId;
        $expedienteFolderId = findOrCreateFolder($service, $clienteFolderId, $expedienteFolderName);
        if (!$expedienteFolderId) {
            throw new \Exception("No se pudo crear la carpeta para el expediente " . $caseFileId . " en Google Drive.");
        }

    } catch (\Exception $e) {
        error_log("ERROR Google Drive POST /case (creación de carpetas): " . $e->getMessage());
        $payload = ['error' => 'Error al crear carpetas en Google Drive: ' . $e->getMessage()];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    // --- Fin de la lógica de Google Drive para carpetas ---

    $caseFile = [
        'id' => $caseFileId,
        'clientId' => $data['clientId'],
        'title' => $data['title'],
        'subject' => $data['subject'],
        'date' => $data['date'],
        'place' => $data['place'],
        'court' => $data['court'] ?? '',
        'description' => $data['description'] ?? '',
        'createdAt' => date('Y-m-d H:i:s'),
        'documents' => [],
    ];

    $this->firebaseDb->getReference('expedientes/' . $caseFileId)->set($caseFile);
    $counterRef->set($newCounter);

    $payload = [
        'message' => 'Expediente creado exitosamente.',
        'id' => $caseFileId,
        'driveIds' => $expedienteFolderId,
    ];

    $response->getBody()->write(json_encode($payload));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
});

// Obtener todos los expedientes
$app->get('/cases', function (Request $request, Response $response) {
    $caseFiles = $this->firebaseDb->getReference('expedientes')->getValue() ?? [];
    $response->getBody()->write(json_encode(array_values($caseFiles)));
    return $response->withHeader('Content-Type', 'application/json');
});

// NUEVA RUTA: Obtener expedientes por ID de cliente
$app->get('/cases/client/{clientId}', function (Request $request, Response $response, array $args) {
    $clientId = $args['clientId'];
    $allCaseFiles = $this->firebaseDb->getReference('expedientes')->getValue() ?? [];

    $clientCases = [];
    foreach ($allCaseFiles as $caseFileId => $caseFile) {
        if (isset($caseFile['clientId']) && $caseFile['clientId'] === $clientId) {
            $clientCases[] = [
                'id' => $caseFile['id'],
                'title' => $caseFile['title'],
            ];
        }
    }

    $response->getBody()->write(json_encode(array_values($clientCases)));
    return $response->withHeader('Content-Type', 'application/json');
});

// NUEVA RUTA: Obtener lista de documentos de un expediente desde Google Drive
$app->get('/case/{id}/documents', function (Request $request, Response $response, array $args) {
    $id = $args['id']; // ID del expediente

    // 1. Obtener el expediente de Firebase para obtener el googleDriveFolderId
    $caseFile = $this->firebaseDb->getReference('expedientes/' . $id)->getValue();
    if (!$caseFile) {
        $payload = ['error' => 'Expediente no encontrado.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $expedienteFolderId = $caseFile['googleDriveFolderId'] ?? null;
    if (!$expedienteFolderId) {
        $payload = ['error' => 'No se encontró una carpeta de Google Drive asociada a este expediente.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    // 2. Listar los archivos en esa carpeta de Google Drive
    $driveDocuments = [];
    try {
        $googleClient = new \Google\Client();
        $credentialsPath = '.././credentials-google.json';

        if (!file_exists($credentialsPath)) {
            throw new \Exception("El archivo de credenciales no existe en: " . $credentialsPath);
        }

        $googleClient->setAuthConfig($credentialsPath);
        $googleClient->addScope(Drive::DRIVE_FILE);
        $googleClient->fetchAccessTokenWithAssertion();
        $service = new Drive($googleClient);

        $query = "'" . $expedienteFolderId . "' in parents and trashed=false";
        $results = $service->files->listFiles([
            'q' => $query,
            'spaces' => 'drive',
            'fields' => 'files(id, name, mimeType, size)' // Obtener ID, nombre, tipo MIME y tamaño
        ]);

        foreach ($results->getFiles() as $file) {
            $driveDocuments[] = [
                'id' => $file->getId(),
                'name' => $file->getName(),
                'mimeType' => $file->getMimeType(),
                'size' => $file->getSize(), // Tamaño en bytes
            ];
        }

    } catch (\Exception $e) {
        error_log("ERROR Google Drive GET /case/{id}/documents: " . $e->getMessage());
        $payload = ['error' => 'Error al listar documentos de Google Drive: ' . $e->getMessage()];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    $response->getBody()->write(json_encode($driveDocuments));
    return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
});

// Obtener expediente por ID
$app->get('/case/{id}', function (Request $request, Response $response, $args) {
    $id = $args['id'];
    $caseFile = $this->firebaseDb->getReference('expedientes/' . $id)->getValue();
    if (!$caseFile) {
        $payload = ['error' => 'Expediente no encontrado'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    // Asegurarse de que 'documents' es un array vacío o se elimina, ya que ahora se obtendrán de Drive
    $caseFile['documents'] = []; 

    $response->getBody()->write(json_encode($caseFile));
    return $response->withHeader('Content-Type', 'application/json');
});

// Actualizar expediente (ya no maneja subida de archivos)
$app->post('/case/{id}', function (Request $request, Response $response, array $args) {
    $caseFileId = $args['id'];
    $data = $request->getParsedBody();
    // Ya no necesitamos $files aquí para la actualización

    if (strtoupper($data['_method'] ?? '') !== 'PUT') {
        $payload = ['error' => 'Método no permitido. Usa POST con _method=PUT para actualizar.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(405)->withHeader('Content-Type', 'application/json');
    }

    $requiredFields = ['clientId', 'title', 'subject', 'date', 'place', 'court'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            $payload = ['error' => "El campo '$field' es requerido."];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    $caseRef = $this->firebaseDb->getReference('expedientes/' . $caseFileId);
    $existingCase = $caseRef->getValue();
    if (!$existingCase) {
        $payload = ['error' => "El expediente con ID $caseFileId no existe."];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $clients = $this->firebaseDb->getReference('clientes')->getValue() ?? [];
    $clientActive = false;
    foreach ($clients as $key => $client) {
        if ($key === $data['clientId'] && (!isset($client['activo']) || $client['activo'] === true)) {
            $clientActive = true;
            break;
        }
    }
    if (!$clientActive) {
        $payload = ['error' => 'El cliente seleccionado no está activo o no existe.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // No se procesan archivos adjuntos aquí.
    // Los documentos se gestionarán directamente en Google Drive.
    $googleDriveFolderId = $existingCase['googleDriveFolderId'] ?? null; // Mantener el ID de la carpeta de Drive

    try {
        $updatedCase = [
            'id' => $caseFileId,
            'clientId' => $data['clientId'],
            'title' => $data['title'],
            'subject' => $data['subject'],
            'date' => $data['date'],
            'place' => $data['place'],
            'court' => $data['court'] ?? '',
            'description' => $data['description'] ?? '',
            'updatedAt' => date('Y-m-d H:i:s'),
            'createdAt' => $existingCase['createdAt'] ?? date('Y-m-d H:i:s'),
            'documents' => [], // Siempre vacío desde el backend, se obtienen de Drive
            'googleDriveFolderId' => $googleDriveFolderId // Asegurarse de que el ID de la carpeta se mantenga
        ];

        $caseRef->set($updatedCase); // Usa set para sobrescribir y asegurar la estructura
        $payload = ['message' => 'Expediente actualizado correctamente.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    } catch (\Exception $e) {
        error_log("Error al guardar expediente en Firebase (PUT): " . $e->getMessage());
        $payload = ['error' => 'Error al guardar expediente: ' . $e->getMessage()];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});


// Eliminar expediente
$app->delete('/case/{id}', function (Request $request, Response $response, $args) {
    $id = $args['id'];
    $caseFileRef = $this->firebaseDb->getReference('expedientes/' . $id);
    $existing = $caseFileRef->getValue();

    if (!$existing) {
        $payload = ['error' => 'Expediente no encontrado'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    // --- Lógica para eliminar la carpeta de Drive asociada (y su contenido) ---
    $expedienteFolderId = $existing['googleDriveFolderId'] ?? null;

    if ($expedienteFolderId) {
        try {
            $googleClient = new Client();
            $credentialsPath = '.././credentials-google.json';
            if (!file_exists($credentialsPath)) {
                error_log("ERROR CRÍTICO: El archivo de credenciales de Google Drive NO EXISTE en la ruta: " . $credentialsPath);
            } else {
                $googleClient->setAuthConfig($credentialsPath);
                $googleClient->addScope(Drive::DRIVE_FILE); // Necesario para eliminar archivos/carpetas
                $googleClient->fetchAccessTokenWithAssertion();
                $service = new Drive($googleClient);

                // Eliminar la carpeta del expediente de Google Drive
                // Nota: La API de Drive no permite eliminar directamente una carpeta no vacía
                // sin eliminar primero su contenido. Para simplificar, si la carpeta tiene contenido,
                // la eliminación de la carpeta fallará a menos que uses un método más avanzado.
                // Para este escenario, intentaremos eliminar la carpeta directamente.
                // Si falla por no estar vacía, el expediente se borrará de Firebase pero la carpeta de Drive persistirá.
                try {
                    $service->files->delete($expedienteFolderId);
                    error_log("Carpeta de Drive eliminada: " . $expedienteFolderId . " para expediente: " . $id);
                } catch (\Google\Service\Exception $e) {
                    error_log("Error al eliminar carpeta de Drive " . $expedienteFolderId . " para expediente " . $id . ": " . $e->getMessage());
                    // Esto suele ocurrir si la carpeta no está vacía.
                    // Podrías implementar una lógica para listar y eliminar archivos recursivamente aquí si es crítico.
                }
            }
        } catch (\Exception $e) {
            error_log("Error fatal en la inicialización/autenticación de Google Drive para eliminación de expediente " . $id . ": " . $e->getMessage());
        }
    }
    // --- FIN Lógica para eliminar la carpeta de Drive asociada ---

    $caseFileRef->remove();

    $payload = ['message' => 'Expediente eliminado exitosamente'];
    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json');
});
