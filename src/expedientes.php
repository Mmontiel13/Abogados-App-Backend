<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Http\UploadedFile;

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
    foreach ($existingCaseFiles as $existingCaseFile) {
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

    try {
        $rootFolderId = '1b4-38SH6FYJj0dnwVNFEqc7dXm8Jz9br';
        $clienteFolderId = findOrCreateFolder($this->googleDriveService, $rootFolderId, 'cliente-' . $data['clientId']);
        $expedienteFolderName = 'expediente-' . $caseFileId;
        $expedienteFolderId = findOrCreateFolder($this->googleDriveService, $clienteFolderId, $expedienteFolderName);
    } catch (\Exception $e) {
        error_log("ERROR Google Drive POST /case (creación de carpetas): " . $e->getMessage());
        $payload = ['error' => 'Error al crear carpetas en Google Drive: ' . $e->getMessage()];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

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
        'googleDriveFolderId' => $expedienteFolderId,
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

$app->get('/cases', function (Request $request, Response $response) {
    $caseFiles = $this->firebaseDb->getReference('expedientes')->getValue() ?? [];
    $response->getBody()->write(json_encode(array_values($caseFiles)));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/cases/client/{clientId}', function (Request $request, Response $response, array $args) {
    $clientId = $args['clientId'];
    $allCaseFiles = $this->firebaseDb->getReference('expedientes')->getValue() ?? [];

    $clientCases = [];
    foreach ($allCaseFiles as $caseFile) {
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

$app->get('/case/{id}/documents', function (Request $request, Response $response, array $args) {
    $id = $args['id'];
    $caseFile = $this->firebaseDb->getReference('expedientes/' . $id)->getValue();

    if (!$caseFile || !isset($caseFile['googleDriveFolderId'])) {
        $payload = ['error' => 'Expediente o carpeta de Drive no encontrada.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $folderId = $caseFile['googleDriveFolderId'];
    $driveDocuments = [];

    try {
        $query = "'$folderId' in parents and trashed=false";
        $results = $this->googleDriveService->files->listFiles([
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
        error_log("ERROR Google Drive GET /case/{id}/documents: " . $e->getMessage());
        $payload = ['error' => 'Error al listar documentos: ' . $e->getMessage()];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    $response->getBody()->write(json_encode($driveDocuments));
    return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
});

$app->get('/case/{id}', function (Request $request, Response $response, $args) {
    $id = $args['id'];
    $caseFile = $this->firebaseDb->getReference('expedientes/' . $id)->getValue();

    if (!$caseFile) {
        $payload = ['error' => 'Expediente no encontrado'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $caseFile['documents'] = [];
    $response->getBody()->write(json_encode($caseFile));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/case/{id}', function (Request $request, Response $response, array $args) {
    $caseFileId = $args['id'];
    $data = $request->getParsedBody();

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
        'documents' => [],
        'googleDriveFolderId' => $existingCase['googleDriveFolderId'] ?? null
    ];

    $caseRef->set($updatedCase);
    $payload = ['message' => 'Expediente actualizado correctamente.'];
    $response->getBody()->write(json_encode($payload));
    return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
});

$app->delete('/case/{id}', function (Request $request, Response $response, $args) {
    $id = $args['id'];
    $caseFileRef = $this->firebaseDb->getReference('expedientes/' . $id);
    $existing = $caseFileRef->getValue();

    if (!$existing) {
        $payload = ['error' => 'Expediente no encontrado'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $folderId = $existing['googleDriveFolderId'] ?? null;
    if ($folderId) {
        try {
            $this->googleDriveService->files->delete($folderId);
            error_log("Carpeta de Drive eliminada: $folderId para expediente: $id");
        } catch (\Exception $e) {
            error_log("Error al eliminar carpeta de Drive $folderId para expediente $id: " . $e->getMessage());
        }
    }

    $caseFileRef->remove();
    $payload = ['message' => 'Expediente eliminado exitosamente'];
    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json');
});
