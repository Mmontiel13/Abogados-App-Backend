<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Crear cliente con ID personalizado basado en el nombre (POST /cliente)
$app->post('/cliente', function (Request $request, Response $response, array $args) {
    $data = $request->getParsedBody();

    // Validar campos obligatorios al crear un cliente (solo nombre, email, teléfono)
    $requiredFields = ['name', 'email', 'phone']; 
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $payload = ['error' => "El campo '$field' es obligatorio."];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    // Formatear nombre a ID: mayúsculas, espacios por guiones
    $nombre = trim($data['name']);
    $idFormateado = strtoupper(preg_replace('/\s+/', '-', $nombre)); 

    // Verificar si ya existe un cliente con ese ID y activo
    $clientesExistentes = $this->firebaseDb->getReference('clientes')->getValue() ?? [];

    if (isset($clientesExistentes[$idFormateado]) && (!isset($clientesExistentes[$idFormateado]['activo']) || $clientesExistentes[$idFormateado]['activo'] === true)) {
        $payload = ['error' => 'Ya existe un cliente activo con ese nombre.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }

    // Asegurar que 'activo' sea true por defecto para nuevos clientes
    $data['activo'] = true; 
    // Añadir fecha de creación (si no viene del form, usar la actual)
    $data['dateAdded'] = $data['dateAdded'] ?? date('Y-m-d'); // Usar la fecha del form si existe, sino la actual
    $data['createdAt'] = date('Y-m-d H:i:s'); // Para un timestamp más preciso

    // Campos permitidos y guardados
    $clientDataToSave = [
        'name' => $data['name'],
        'email' => $data['email'],
        'phone' => $data['phone'],
        'dateAdded' => $data['dateAdded'],
        'activo' => $data['activo'],
        'createdAt' => $data['createdAt']
    ];

    $this->firebaseDb->getReference('clientes/' . $idFormateado)->set($clientDataToSave);

    $payload = [
        'message' => 'Cliente creado correctamente',
        'id' => $idFormateado, // Usar 'id' para consistencia con el frontend
        'data' => $clientDataToSave // Devolver los datos guardados
    ];

    $response->getBody()->write(json_encode($payload));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
});

// Listar clientes activos (GET /clientes)
$app->get('/clientes', function (Request $request, Response $response, array $args) {
    $clientes = $this->firebaseDb->getReference('clientes')->getValue();

    $clientesActivos = [];
    if ($clientes) {
        foreach ($clientes as $id => $cliente) {
            // Asegúrate de que solo los clientes activos se listan y se añade el 'id'
            if (isset($cliente['activo']) && $cliente['activo'] === true) {
                $cliente['id'] = $id; // Añade el ID de Firebase al objeto cliente
                // Asegúrate de que solo los campos deseados se devuelven
                $simplifiedClient = [
                    'id' => $cliente['id'],
                    'name' => $cliente['name'] ?? '',
                    'email' => $cliente['email'] ?? '',
                    'phone' => $cliente['phone'] ?? '',
                    'dateAdded' => $cliente['dateAdded'] ?? '',
                    'activo' => $cliente['activo'] ?? false // Mantener el estado activo para filtrado si es necesario
                ];
                $clientesActivos[] = $simplifiedClient;
            }
        }
    }

    $response->getBody()->write(json_encode(array_values($clientesActivos))); // array_values para asegurar que el frontend reciba un array indexado
    return $response->withHeader('Content-Type', 'application/json');
});

// Obtener cliente por ID (GET /cliente/{id})
$app->get('/cliente/{id}', function (Request $request, Response $response, array $args) {
    $idCliente = $args['id'];
    $cliente = $this->firebaseDb->getReference('clientes/' . $idCliente)->getValue();

    if (!$cliente || (isset($cliente['activo']) && $cliente['activo'] === false)) {
        $payload = ['error' => 'Cliente no encontrado o inactivo'];
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json')->withBody(json_encode($payload));
    } else {
        $cliente['id'] = $idCliente; // Añade el ID de Firebase al objeto cliente
        // Devolver solo los campos deseados
        $simplifiedClient = [
            'id' => $cliente['id'],
            'name' => $cliente['name'] ?? '',
            'email' => $cliente['email'] ?? '',
            'phone' => $cliente['phone'] ?? '',
            'dateAdded' => $cliente['dateAdded'] ?? '',
            'activo' => $cliente['activo'] ?? false // Mantener el estado activo
        ];
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json')->withBody(json_encode($simplifiedClient));
    }
});

// Actualizar cliente por ID (POST /cliente/{id} con _method=PUT)
// Hemos cambiado de PUT a POST para manejar FormData con _method
$app->post('/cliente/{id}', function (Request $request, Response $response, array $args) {
    $idCliente = $args['id'];
    $data = $request->getParsedBody();

    // Validar método simulado (importante para POST que simula PUT)
    if (strtoupper($data['_method'] ?? '') !== 'PUT') {
        $payload = ['error' => 'Método no permitido. Usa POST con _method=PUT para actualizar.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(405)->withHeader('Content-Type', 'application/json');
    }

    // Validar campos obligatorios al actualizar
    $requiredFields = ['name', 'email', 'phone']; // Estos deben venir siempre
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $payload = ['error' => "El campo '$field' es obligatorio para la actualización."];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    $clienteRef = $this->firebaseDb->getReference('clientes/' . $idCliente);
    $clienteExistente = $clienteRef->getValue();

    if (!$clienteExistente || (isset($clienteExistente['activo']) && $clienteExistente['activo'] === false)) {
        $payload = ['error' => 'Cliente no encontrado o inactivo.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    } else {
        // PREVENCIÓN DE DUPLICADOS EN ACTUALIZACIÓN:
        // Si el nombre cambia, verificar que el nuevo nombre no cause un conflicto de ID con un cliente ACTIVO existente.
        $newNombre = trim($data['name'] ?? $clienteExistente['name']);
        $idFormateadoNuevo = strtoupper(preg_replace('/\s+/', '-', $newNombre));

        if ($idFormateadoNuevo !== $idCliente) { // Solo si el ID resultante del nuevo nombre es diferente
            $clientesExistentesTodos = $this->firebaseDb->getReference('clientes')->getValue() ?? [];
            if (isset($clientesExistentesTodos[$idFormateadoNuevo]) && $idFormateadoNuevo !== $idCliente && (!isset($clientesExistentesTodos[$idFormateadoNuevo]['activo']) || $clientesExistentesTodos[$idFormateadoNuevo]['activo'] === true)) {
                $payload = ['error' => 'El nuevo nombre ya pertenece a un cliente activo existente.'];
                $response->getBody()->write(json_encode($payload));
                return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
            }
        }
        
        // Datos para actualizar, solo los campos deseados
        $updateData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'dateAdded' => $data['dateAdded'] ?? $clienteExistente['dateAdded'], // Usar la del form o la existente
            'activo' => $data['activo'] ?? $clienteExistente['activo'] ?? true, // Mantener o actualizar si viene, o true por defecto
            'updatedAt' => date('Y-m-d H:i:s') // Añadir timestamp de actualización
        ];

        // Si el nombre del cliente cambia y con ello su ID formateado, necesitamos moverlo en Firebase
        if ($idFormateadoNuevo !== $idCliente) {
            // Clonar el cliente con el nuevo ID y luego borrar el antiguo
            $this->firebaseDb->getReference('clientes/' . $idFormateadoNuevo)->set($updateData);
            $clienteRef->remove(); // Eliminar el registro antiguo
            $idFinal = $idFormateadoNuevo;
        } else {
            // Actualizar el cliente existente sin cambiar el ID
            $clienteRef->update($updateData);
            $idFinal = $idCliente;
        }
        
        $payload = [
            'message' => 'Cliente actualizado correctamente',
            'id' => $idFinal, // Devolver el ID final (nuevo o antiguo)
            'data' => $updateData
        ];
    }

    $response->getBody()->write(json_encode($payload));
    return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
});

// Borrado lógico (activo = false) (DELETE /cliente/{id})
$app->delete('/cliente/{id}', function (Request $request, Response $response, array $args) {
    $idCliente = $args['id'];

    $clienteRef = $this->firebaseDb->getReference('clientes/' . $idCliente);
    $clienteExistente = $clienteRef->getValue();

    if (!$clienteExistente || (isset($clienteExistente['activo']) && $clienteExistente['activo'] === false)) {
        $payload = ['error' => 'Cliente no encontrado o ya inactivo.'];
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json')->withBody(json_encode($payload));
    } else {
        $clienteRef->update(['activo' => false]); // Solo actualiza el estado a inactivo
        $payload = ['message' => 'Cliente borrado (lógicamente) correctamente', 'id' => $idCliente];
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json')->withBody(json_encode($payload));
    }
});
