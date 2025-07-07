<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Slim\Http\UploadedFile;

require '../vendor/autoload.php';

// Ruta POST para crear un nuevo archivo "Otro"
$app->post('/other', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    // Archivos ya no se suben directamente en la creación

    // Validar campos obligatorios: title, type, description
    $requiredFields = ['title', 'type', 'description'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            $payload = ['error' => "El campo '$field' es requerido."];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    // Validación de duplicados
    $existingOthers = $this->firebaseDb->getReference('otros')->getValue() ?? [];
    foreach ($existingOthers as $otherKey => $existingOther) {
        $existingTitle = strtolower(trim($existingOther['title'] ?? ''));
        $existingType = strtolower(trim($existingOther['type'] ?? ''));
        $newTitle = strtolower(trim($data['title']));
        $newType = strtolower(trim($data['type']));

        if ($existingTitle === $newTitle && $existingType === $newType) {
            $payload = ['error' => 'Ya existe un archivo "Otro" con el mismo título y tipo.'];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
    }

    // Generar nuevo ID para el archivo "Otro"
    $counterRef = $this->firebaseDb->getReference('contadores/otro');
    $counter = $counterRef->getValue() ?? 0;
    $newCounter = $counter + 1;
    $otherFileId = sprintf("DOC-%03d", $newCounter);

    // --- Lógica de Google Drive API para crear carpetas ---
    $otherSpecificFolderId = null;
    try {
        $googleClient = new Client();
        $credentialsPath = '.././credentials-google.json';

        if (!file_exists($credentialsPath)) {
            throw new \Exception("El archivo de credenciales no existe en: " . $credentialsPath);
        }

        $googleClient->setAuthConfig($credentialsPath);
        $googleClient->addScope(Drive::DRIVE_FILE);
        $googleClient->setSubject(null);

        $googleClient->fetchAccessTokenWithAssertion();
        $service = new Drive($googleClient);

        $myPersonalDataFilesFolderId = '1Xdb39qfZIbdPLQdg7xfh353QVeI7eQCA'; // Tu ID de carpeta raíz

        // Sub-carpeta específica para "Otros archivos" dentro de DataFiles
        $othersMainFolderId = findOrCreateFolder($service, $myPersonalDataFilesFolderId, 'OtrosArchivos');
        if (!$othersMainFolderId) {
            throw new \Exception("No se pudo crear la carpeta 'OtrosArchivos' en Google Drive.");
        }

        // Carpeta para este archivo "Otro" específico (usando el ID generado)
        $otherSpecificFolderName = $data['title'];
        $otherSpecificFolderId = findOrCreateFolder($service, $othersMainFolderId, $otherSpecificFolderName);
        if (!$otherSpecificFolderId) {
            throw new \Exception("No se pudo crear la carpeta para el archivo 'Otro' con ID " . $otherFileId . " en Google Drive.");
        }

    } catch (\Exception $e) {
        error_log("ERROR Google Drive POST /other (creación de carpetas): " . $e->getMessage());
        $payload = ['error' => 'Error al crear carpetas en Google Drive: ' . $e->getMessage()];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    // --- Fin de la lógica de Google Drive para carpetas ---
    // Preparar los datos del archivo "Otro" para Firebase
    $otherFile = [
        'id' => $otherFileId,
        'title' => $data['title'],
        'type' => $data['type'],
        'description' => $data['description'],
        'author' => $data['author'] ?? '',
        'tags' => isset($data['tags']) ? array_map('trim', explode(',', $data['tags'])) : [],
        'source' => $data['source'] ?? '',
        'jurisdiction' => $data['jurisdiction'] ?? '',
        'court' => $data['court'] ?? '',
        'caseNumber' => $data['caseNumber'] ?? '',
        'year' => $data['year'] ?? '',
        'notes' => $data['notes'] ?? '',
        'dateAdded' => $data['date'] ?? date('Y-m-d'),
        'createdAt' => date('Y-m-d H:i:s'),
        'documents' => [], // Siempre vacío, los documentos se gestionan en Drive
        'googleDriveFolderId' => $otherSpecificFolderId, // Guardar el ID de la carpeta de Drive
    ];

    // Guardar el nuevo archivo "Otro" en Firebase
    $this->firebaseDb->getReference('otros/' . $otherFileId)->set($otherFile);
    $counterRef->set($newCounter);

    $payload = [
        'message' => 'Archivo "Otro" creado exitosamente. Ahora puedes subir documentos en la sección de edición.',
        'id' => $otherFileId,
        'otherFile' => $otherFile,
        'googleDriveFolderId' => $otherSpecificFolderId,
    ];

    $response->getBody()->write(json_encode($payload));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
});

// Ruta GET para obtener todos los archivos "Otros"
$app->get('/others', function (Request $request, Response $response) {
    $otherFiles = $this->firebaseDb->getReference('otros')->getValue() ?? [];
    $response->getBody()->write(json_encode(array_values($otherFiles)));
    return $response->withHeader('Content-Type', 'application/json');
});

// NUEVA RUTA: Obtener lista de documentos de un archivo "Otro" desde Google Drive
$app->get('/other/{id}/documents', function (Request $request, Response $response, array $args) {
    $id = $args['id']; // ID del archivo "Otro"

    // 1. Obtener el archivo "Otro" de Firebase para obtener el googleDriveFolderId
    $otherFile = $this->firebaseDb->getReference('otros/' . $id)->getValue();
    if (!$otherFile) {
        $payload = ['error' => 'Archivo "Otro" no encontrado.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $otherFolderId = $otherFile['googleDriveFolderId'] ?? null;
    if (!$otherFolderId) {
        $payload = ['error' => 'No se encontró una carpeta de Google Drive asociada a este archivo "Otro".'];
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

        $query = "'" . $otherFolderId . "' in parents and trashed=false";
        $results = $service->files->listFiles([
            'q' => $query,
            'spaces' => 'drive',
            'fields' => 'files(id, name, mimeType, size)'
        ]);

        foreach ($results->getFiles() as $file) {
            $driveDocuments[] = [
                'id' => $file->getId(),
                'name' => $file->getName(),
                'mimeType' => $file->getMimeType(),
                'size' => $file->getSize(),
            ];
        }

    } catch (\Exception $e) {
        error_log("ERROR Google Drive GET /other/{id}/documents: " . $e->getMessage());
        $payload = ['error' => 'Error al listar documentos de Google Drive: ' . $e->getMessage()];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    $response->getBody()->write(json_encode($driveDocuments));
    return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
});

// Ruta GET para obtener un archivo "Otro" por ID
$app->get('/other/{id}', function (Request $request, Response $response, $args) {
    $id = $args['id'];
    $otherFile = $this->firebaseDb->getReference('otros/' . $id)->getValue();
    if (!$otherFile) {
        $payload = ['error' => 'Archivo "Otro" no encontrado'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    if (isset($otherFile['tags']) && is_string($otherFile['tags'])) {
        $otherFile['tags'] = array_map('trim', explode(',', $otherFile['tags']));
    }
    // Asegurarse de que 'documents' es un array vacío
    $otherFile['documents'] = []; 

    $response->getBody()->write(json_encode($otherFile));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/other/{id}', function (Request $request, Response $response, array $args) {
    $id = $args['id'];
    $data = $request->getParsedBody();

    if (strtoupper($data['_method'] ?? '') !== 'PUT') {
        $payload = ['error' => 'Método no permitido. Usa POST con _method=PUT para actualizar.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(405)->withHeader('Content-Type', 'application/json');
    }

    // Validar campos obligatorios
    $requiredFields = ['title', 'type', 'description'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            $payload = ['error' => "El campo '$field' es requerido."];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    // Obtener el archivo existente
    $otherFileRef = $this->firebaseDb->getReference('otros/' . $id);
    $existing = $otherFileRef->getValue();
    if (!$existing) {
        $payload = ['error' => 'Archivo "Otro" no encontrado.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    // Validación de duplicados (mismo título y tipo)
    $newTitle = strtolower(trim($data['title']));
    $newType = strtolower(trim($data['type']));

    $existingOthers = $this->firebaseDb->getReference('otros')->getValue() ?? [];
    foreach ($existingOthers as $otherKey => $existingOther) {
        if ($otherKey === $id) continue;
        $existingTitle = strtolower(trim($existingOther['title'] ?? ''));
        $existingType = strtolower(trim($existingOther['type'] ?? ''));
        if ($existingTitle === $newTitle && $existingType === $newType) {
            $payload = ['error' => 'Ya existe otro archivo con el mismo título y tipo.'];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
    }

    // Mantener carpeta de Google Drive
    $googleDriveFolderId = $existing['googleDriveFolderId'] ?? null;

    // Actualizar datos
    $updateData = [
        'id' => $id,
        'title' => $data['title'],
        'type' => $data['type'],
        'description' => $data['description'],
        'author' => $data['author'] ?? $existing['author'] ?? '',
        'source' => $data['source'] ?? $existing['source'] ?? '',
        'jurisdiction' => $data['jurisdiction'] ?? $existing['jurisdiction'] ?? '',
        'court' => $data['court'] ?? $existing['court'] ?? '',
        'caseNumber' => $data['caseNumber'] ?? $existing['caseNumber'] ?? '',
        'year' => $data['year'] ?? $existing['year'] ?? '',
        'notes' => $data['notes'] ?? $existing['notes'] ?? '',
        'dateAdded' => $data['date'] ?? $existing['dateAdded'] ?? date('Y-m-d'),
        'updatedAt' => date('Y-m-d H:i:s'),
        'createdAt' => $existing['createdAt'] ?? date('Y-m-d H:i:s'),
        'documents' => [], // Siempre vacío
        'googleDriveFolderId' => $googleDriveFolderId,
    ];

    if (isset($data['tags'])) {
        $tags = is_array($data['tags']) ? $data['tags'] : explode(',', $data['tags']);
        $updateData['tags'] = array_filter(array_map('trim', $tags));
    } else {
        $updateData['tags'] = $existing['tags'] ?? [];
    }

    // Guardar en Firebase
    try {
        $otherFileRef->set($updateData);
        $payload = ['message' => 'Archivo "Otro" actualizado correctamente.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    } catch (\Exception $e) {
        error_log("Error al guardar archivo 'Otro': " . $e->getMessage());
        $payload = ['error' => 'Error al guardar: ' . $e->getMessage()];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});


// Ruta DELETE para eliminar un archivo "Otro"
$app->delete('/other/{id}', function (Request $request, Response $response, $args) {
    $id = $args['id'];
    $otherFileRef = $this->firebaseDb->getReference('otros/' . $id);
    $existing = $otherFileRef->getValue();

    if (!$existing) {
        $payload = ['error' => 'Archivo "Otro" no encontrado'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    // --- Lógica para eliminar la carpeta de Drive asociada (y su contenido) ---
    $otherFolderId = $existing['googleDriveFolderId'] ?? null;

    if ($otherFolderId) {
        try {
            $googleClient = new Client();
            $credentialsPath = '.././credentials-google.json';
            if (!file_exists($credentialsPath)) {
                error_log("ERROR CRÍTICO: El archivo de credenciales de Google Drive NO EXISTE en la ruta: " . $credentialsPath);
            } else {
                $googleClient->setAuthConfig($credentialsPath);
                $googleClient->addScope(Drive::DRIVE_FILE);
                $googleClient->fetchAccessTokenWithAssertion();
                $service = new Drive($googleClient);

                try {
                    // Eliminar la carpeta del archivo "Otro" de Google Drive
                    $service->files->delete($otherFolderId);
                    error_log("Carpeta de Drive eliminada: " . $otherFolderId . " para archivo 'Otro': " . $id);
                } catch (\Google\Service\Exception $e) {
                    error_log("Error al eliminar carpeta de Drive " . $otherFolderId . " para archivo 'Otro' " . $id . ": " . $e->getMessage());
                    // Esto suele ocurrir si la carpeta no está vacía.
                }
            }
        } catch (\Exception $e) {
            error_log("Error fatal en la inicialización/autenticación de Google Drive para eliminación de archivo 'Otro' " . $id . ": " . $e->getMessage());
        }
    }
    // --- FIN Lógica para eliminar la carpeta de Drive asociada ---

    $otherFileRef->remove();

    $payload = ['message' => 'Archivo "Otro" eliminado exitosamente'];
    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json');
});
