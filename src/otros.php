<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Http\UploadedFile;

// POST: Crear nuevo archivo "Otro"
$app->post('/other', function (Request $request, Response $response) {
    $data = $request->getParsedBody();

    $requiredFields = ['title', 'type', 'description'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            $payload = ['error' => "El campo '$field' es requerido."];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    $existingOthers = $this->firebaseDb->getReference('otros')->getValue() ?? [];
    foreach ($existingOthers as $key => $item) {
        if (
            strtolower(trim($item['title'] ?? '')) === strtolower(trim($data['title'])) &&
            strtolower(trim($item['type'] ?? '')) === strtolower(trim($data['type']))
        ) {
            $payload = ['error' => 'Ya existe un archivo "Otro" con el mismo título y tipo.'];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
    }

    $counterRef = $this->firebaseDb->getReference('contadores/otro');
    $counter = $counterRef->getValue() ?? 0;
    $newCounter = $counter + 1;
    $otherFileId = sprintf("DOC-%03d", $newCounter);

    try {
        $rootFolderId = '1Xdb39qfZIbdPLQdg7xfh353QVeI7eQCA';
        $mainFolderId = findOrCreateFolder($this->googleDrive, $rootFolderId, 'OtrosArchivos');
        $folderId = findOrCreateFolder($this->googleDrive, $mainFolderId, $data['title']);
    } catch (\Exception $e) {
        error_log("ERROR Google Drive POST /other: " . $e->getMessage());
        $payload = ['error' => 'Error al crear carpetas en Google Drive: ' . $e->getMessage()];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

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
        'documents' => [],
        'googleDriveFolderId' => $folderId,
    ];

    $this->firebaseDb->getReference('otros/' . $otherFileId)->set($otherFile);
    $counterRef->set($newCounter);

    $payload = [
        'message' => 'Archivo "Otro" creado exitosamente.',
        'id' => $otherFileId,
        'otherFile' => $otherFile,
        'googleDriveFolderId' => $folderId,
    ];

    $response->getBody()->write(json_encode($payload));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
});

// GET: Obtener todos los archivos "Otros"
$app->get('/others', function (Request $request, Response $response) {
    $others = $this->firebaseDb->getReference('otros')->getValue() ?? [];
    $response->getBody()->write(json_encode(array_values($others)));
    return $response->withHeader('Content-Type', 'application/json');
});

// GET: Obtener un archivo "Otro" por ID
$app->get('/other/{id}', function (Request $request, Response $response, array $args) {
    $id = $args['id'];
    $data = $this->firebaseDb->getReference('otros/' . $id)->getValue();
    if (!$data) {
        $payload = ['error' => 'Archivo "Otro" no encontrado'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    $data['documents'] = [];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

// GET: Obtener documentos del archivo "Otro"
$app->get('/other/{id}/documents', function (Request $request, Response $response, array $args) {
    $id = $args['id'];
    $data = $this->firebaseDb->getReference('otros/' . $id)->getValue();
    if (!$data || !isset($data['googleDriveFolderId'])) {
        $payload = ['error' => 'Archivo "Otro" o carpeta no encontrada.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    try {
        $query = "'{$data['googleDriveFolderId']}' in parents and trashed=false";
        $results = $this->googleDrive->files->listFiles([
            'q' => $query,
            'spaces' => 'drive',
            'fields' => 'files(id, name, mimeType, size)'
        ]);

        $files = [];
        foreach ($results->getFiles() as $file) {
            $files[] = [
                'id' => $file->getId(),
                'name' => $file->getName(),
                'mimeType' => $file->getMimeType(),
                'size' => $file->getSize(),
            ];
        }
        $response->getBody()->write(json_encode($files));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    } catch (\Exception $e) {
        error_log("ERROR Google Drive GET /other/{id}/documents: " . $e->getMessage());
        $payload = ['error' => 'Error al listar documentos: ' . $e->getMessage()];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

// POST con _method=PUT: Actualizar archivo "Otro"
$app->post('/other/{id}', function (Request $request, Response $response, array $args) {
    $id = $args['id'];
    $data = $request->getParsedBody();

    if (strtoupper($data['_method'] ?? '') !== 'PUT') {
        $payload = ['error' => 'Método no permitido. Usa POST con _method=PUT.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(405)->withHeader('Content-Type', 'application/json');
    }

    $requiredFields = ['title', 'type', 'description'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            $payload = ['error' => "El campo '$field' es requerido."];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    $ref = $this->firebaseDb->getReference('otros/' . $id);
    $existing = $ref->getValue();
    if (!$existing) {
        $payload = ['error' => 'Archivo "Otro" no encontrado.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $others = $this->firebaseDb->getReference('otros')->getValue() ?? [];
    foreach ($others as $key => $item) {
        if ($key === $id) continue;
        if (
            strtolower(trim($item['title'] ?? '')) === strtolower(trim($data['title'])) &&
            strtolower(trim($item['type'] ?? '')) === strtolower(trim($data['type']))
        ) {
            $payload = ['error' => 'Ya existe otro archivo con el mismo título y tipo.'];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
    }

    $update = array_merge($existing, [
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
    ]);

    if (isset($data['tags'])) {
        $tags = is_array($data['tags']) ? $data['tags'] : explode(',', $data['tags']);
        $update['tags'] = array_filter(array_map('trim', $tags));
    }

    $ref->set($update);
    $payload = ['message' => 'Archivo "Otro" actualizado correctamente.'];
    $response->getBody()->write(json_encode($payload));
    return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
});

// DELETE: Eliminar archivo "Otro"
$app->delete('/other/{id}', function (Request $request, Response $response, array $args) {
    $id = $args['id'];
    $ref = $this->firebaseDb->getReference('otros/' . $id);
    $existing = $ref->getValue();

    if (!$existing) {
        $payload = ['error' => 'Archivo "Otro" no encontrado'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $folderId = $existing['googleDriveFolderId'] ?? null;
    if ($folderId) {
        try {
            $this->googleDrive->files->delete($folderId);
            error_log("Carpeta de Drive eliminada: $folderId para archivo Otro: $id");
        } catch (\Exception $e) {
            error_log("ERROR al eliminar carpeta Drive $folderId para archivo Otro $id: " . $e->getMessage());
        }
    }

    $ref->remove();
    $payload = ['message' => 'Archivo "Otro" eliminado exitosamente'];
    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json');
});
