<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
// Se eliminó la importación de StreamFactory, ya que no se usa directamente en Slim 3 para esto.

file_put_contents('debug.log', "usuario.php cargado\n", FILE_APPEND);

// Obtener todos los usuarios no eliminados
$app->get('/usuarios', function (Request $request, Response $response) {
    $usuarios = $this->firebaseDb->getReference('usuarios')->getValue() ?? [];

    $lista = [];
    foreach ($usuarios as $key => $usuario) {
        if (!isset($usuario['deleted']) || $usuario['deleted'] !== true) {
            // No enviar el password (hash) en la lista pública
            $usuarioSinPass = $usuario;
            unset($usuarioSinPass['password']);
            $lista[] = $usuarioSinPass;
        }
    }

    $response->getBody()->write(json_encode($lista));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/usuario', function (Request $request, Response $response) {
    $data = $request->getParsedBody();

    // Validar campos obligatorios
    if (!isset($data['email']) || trim($data['email']) === '') {
        $payload = ['error' => 'El campo "email" es obligatorio'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    if (!isset($data['password']) || trim($data['password']) === '') {
        $payload = ['error' => 'El campo "password" es obligatorio'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // Verificar si ya existe un usuario activo con ese email
    $usuariosExistentes = $this->firebaseDb->getReference('usuarios')->getValue() ?? [];

    foreach ($usuariosExistentes as $usuario) {
        if (
            isset($usuario['email']) &&
            strtolower(trim($usuario['email'])) === strtolower(trim($data['email'])) &&
            (!isset($usuario['deleted']) || $usuario['deleted'] === false)
        ) {
            $payload = ['error' => 'Ya existe un usuario activo con ese correo'];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
    }

    // Hash de la contraseña
    $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

    // Obtener contador y crear ID
    $contadorRef = $this->firebaseDb->getReference('contadores/usuario');
    $contador = $contadorRef->getValue() ?? 0;
    $nuevoContador = $contador + 1;
    $idUsuario = sprintf("USR-%03d", $nuevoContador);

    // Crear el objeto usuario
    $nuevoUsuario = [
        'id' => $idUsuario,
        'name' => $data['name'] ?? '',
        'role' => $data['role'] ?? '',
        'avatar' => $data['avatar'] ?? '',
        'phone' => $data['phone'] ?? '',
        'email' => $data['email'],
        'password' => $passwordHash,
        'deleted' => true
    ];

    // Guardar en Firebase
    $this->firebaseDb->getReference('usuarios/' . $idUsuario)->set($nuevoUsuario);
    $contadorRef->set($nuevoContador);

    // No enviar password en la respuesta
    $respuestaUsuario = $nuevoUsuario;
    unset($respuestaUsuario['password']);

    $payload = [
        'message' => 'Usuario creado correctamente',
        'idUsuario' => $idUsuario,
        'usuario' => $respuestaUsuario
    ];

    $response->getBody()->write(json_encode($payload));
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
});

// Obtener un usuario por ID
$app->get('/usuario/{id}', function (Request $request, Response $response, $args) {
    $usuario = $this->firebaseDb->getReference('usuarios/' . $args['id'])->getValue();

    if (!$usuario || ($usuario['deleted'] ?? false)) {
        $payload = ['message' => 'Usuario no encontrado'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    // No enviar el password hash
    unset($usuario['password']);

    $response->getBody()->write(json_encode($usuario));
    return $response->withHeader('Content-Type', 'application/json');
});

// Actualizar un usuario
$app->put('/usuario/{id}', function (Request $request, Response $response, $args) {
    $data = $request->getParsedBody();
    $ref = $this->firebaseDb->getReference('usuarios/' . $args['id']);
    $usuario = $ref->getValue();

    if (!$usuario || ($usuario['deleted'] ?? false)) {
        $payload = ['message' => 'Usuario no encontrado'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    // Si llega password en la actualización, hashearlo antes de guardar
    if (!empty($data['password'])) {
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
    } else {
        // Si no viene password en el update, mantener el anterior
        // Es importante hacer esto para que el password no se "borre" si no se envía en el PUT.
        unset($data['password']); 
    }

    // Validar si el email ha cambiado y si el nuevo email ya existe y no está eliminado
    if (isset($data['email']) && strtolower(trim($data['email'])) !== strtolower(trim($usuario['email'] ?? ''))) {
        $usuariosExistentes = $this->firebaseDb->getReference('usuarios')->getValue() ?? [];
        foreach ($usuariosExistentes as $key => $existingUser) {
            // Ignorar el propio usuario que se está actualizando
            if ($key === $args['id']) {
                continue;
            }
            if (
                isset($existingUser['email']) &&
                strtolower(trim($existingUser['email'])) === strtolower(trim($data['email'])) &&
                (!isset($existingUser['deleted']) || $existingUser['deleted'] === false)
            ) {
                $payload = ['error' => 'Ya existe un usuario activo con el nuevo correo electrónico proporcionado.'];
                $response->getBody()->write(json_encode($payload));
                return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
            }
        }
    }


    $actualizado = array_merge($usuario, $data);
    $ref->update($actualizado);

    // No enviar el password hash en la respuesta
    $usuarioSinPass = $actualizado;
    unset($usuarioSinPass['password']);

    $payload = [
        'message' => 'Usuario actualizado',
        'usuario' => $usuarioSinPass
    ];

    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json');
});

// --- RUTA PARA LOGIN ---
$app->post('/login', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';

    // Validar que email y password no estén vacíos
    if (empty(trim($email)) || empty(trim($password))) {
        $payload = ['error' => 'Correo electrónico y contraseña son obligatorios.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $usuarios = $this->firebaseDb->getReference('usuarios')->getValue() ?? [];
    $foundUser = null;

    // Buscar el usuario por email
    foreach ($usuarios as $usuario) {
        if (isset($usuario['email']) && strtolower(trim($usuario['email'])) === strtolower(trim($email))) {
            $foundUser = $usuario;
            break;
        }
    }

    if (!$foundUser || ($foundUser['deleted'] ?? false)) {
        // Usuario no encontrado o está marcado como eliminado
        $payload = ['error' => 'Usuario no habilitado.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json'); // 401 Unauthorized
    }

    // Verificar la contraseña hasheada
    if (password_verify($password, $foundUser['password'])) {
        // Login exitoso
        $userResponse = $foundUser;
        unset($userResponse['password']); // No enviar el hash de la contraseña al frontend

        $payload = [
            'message' => 'Inicio de sesión exitoso.',
            'user' => $userResponse
        ];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    } else {
        // Contraseña incorrecta
        $payload = ['error' => 'Credenciales inválidas.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json'); // 401 Unauthorized
    }
});

// Borrado lógico
$app->delete('/usuario/{id}', function (Request $request, Response $response, $args) {
    $ref = $this->firebaseDb->getReference('usuarios/' . $args['id']);
    $usuario = $ref->getValue();

    if (!$usuario || ($usuario['deleted'] ?? false)) {
        $payload = ['message' => 'Usuario no encontrado'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $ref->update(['deleted' => true]);

    $payload = ['message' => 'Usuario eliminado (borrado lógico)'];
    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json');
});