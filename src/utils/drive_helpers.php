<?php

// Importa las clases necesarias para la integración con Google Drive API
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

/**
 * Función auxiliar para buscar una carpeta existente por nombre dentro de un directorio padre
 * en Google Drive, o crearla si no existe.
 * Esto evita duplicados y asegura la estructura de carpetas deseada.
 *
 * @param Google\Service\Drive $service El objeto de servicio de Google Drive.
 * @param string $parentFolderId El ID de la carpeta padre donde buscar/crear.
 * @param string $folderName El nombre de la carpeta a buscar o crear.
 * @return string El ID de la carpeta encontrada o creada.
 * @throws \Exception Si ocurre un error al interactuar con Google Drive.
 */
function findOrCreateFolder(Drive $service, string $parentFolderId, string $folderName): string
{
    $query = "'" . $parentFolderId . "' in parents and mimeType='application/vnd.google-apps.folder' and name='" . $folderName . "' and trashed=false";
    $results = $service->files->listFiles([
        'q' => $query,
        'spaces' => 'drive',
        'fields' => 'files(id, name)'
    ]);

    if (count($results->getFiles()) > 0) {
        return $results->getFiles()[0]->getId();
    } else {
        $fileMetadata = new DriveFile([
            'name' => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parentFolderId]
        ]);
        try {
            $folder = $service->files->create($fileMetadata, ['fields' => 'id']);
            return $folder->id;
        } catch (\Google\Service\Exception $e) {
            error_log("Error al crear carpeta '" . $folderName . "': " . $e->getMessage());
            throw new \Exception("Error al crear la carpeta en Google Drive: " . $e->getMessage());
        }
    }
}


