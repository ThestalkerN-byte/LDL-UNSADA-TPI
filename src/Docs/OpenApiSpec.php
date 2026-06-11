<?php

declare(strict_types=1);

namespace App\Docs;

/*
 * =========================================================================
 * ESPECIFICACIÓN OPENAPI 3.1 — LDL UNSADA TPI API
 * =========================================================================
 *
 * Documentación de la API usando atributos PHP 8 de swagger-php.
 * No contiene lógica de negocio — solo metadatos para generar
 * public/openapi.json.
 *
 * Comando de generación:
 *   ./vendor/bin/openapi src/Docs -o public/openapi.json
 *
 * Router: index.php?action=login|refresh|me|logout|credential|...
 * Respuestas: { status, message, data }
 * =========================================================================
 */

use OpenApi\Attributes as OA;

#[OA\OpenApi(
    openapi: '3.1.0',
    info: new OA\Info(
        title: 'LDL UNSADA — Credenciales Digitales',
        version: '1.0.0',
        description: 'API del sistema de Credenciales Digitales (TPI LDL UNSADA).

**Enrutamiento**: Todas las peticiones entran por `index.php` con el parámetro `?action=`.

**Autenticación JWT**:
- `POST index.php?action=login` → access_token (15 min) + refresh_token (30 días)
- Header: `Authorization: Bearer {access_token}`
- Rotación de refresh token en cada renovación

**Formato de respuesta**:
- Éxito: `{ "status": "success", "message": "...", "data": {...} | [...] }`
- Error: `{ "status": "error", "message": "...", "data": [] }`'
    ),
    servers: [
        new OA\Server(url: '/', description: 'Servidor Apache (producción)'),
        new OA\Server(url: 'http://localhost:8000', description: 'Servidor de desarrollo (php -S)'),
    ],
    tags: [
        new OA\Tag(name: 'Auth', description: 'Autenticación JWT'),
        new OA\Tag(name: 'Credential', description: 'Gestión de credenciales digitales'),
    ],
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'Access token JWT obtenido de POST index.php?action=login'
)]
class OpenApiSpec
{
    // ─── Schemas compartidos ───────────────────────────────────────────────

    #[OA\Schema(
        schema: 'ErrorResponse',
        description: 'Respuesta de error estándar',
        properties: [
            new OA\Property(property: 'status', type: 'string', example: 'error'),
            new OA\Property(property: 'message', type: 'string', example: 'Mensaje descriptivo del error'),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'string'), example: []),
        ],
        type: 'object'
    )]
    private ?object $_schemaErrorResponse = null;

    #[OA\Schema(
        schema: 'User',
        description: 'Datos de un usuario del sistema',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 1),
            new OA\Property(property: 'usuario', type: 'string', example: 'admin'),
            new OA\Property(property: 'dni', type: 'string', example: '12345678'),
            new OA\Property(property: 'nombre', type: 'string', example: 'Administrador'),
            new OA\Property(property: 'apellido', type: 'string', example: 'Principal'),
            new OA\Property(property: 'email', type: 'string', example: 'admin@unsada.edu.ar'),
            new OA\Property(property: 'rol', type: 'string', example: 'admin'),
            new OA\Property(property: 'estado', type: 'boolean', example: true),
        ],
        type: 'object'
    )]
    private ?object $_schemaUser = null;

    #[OA\Schema(
        schema: 'AuthTokens',
        description: 'Tokens de autenticación devueltos en login y refresh',
        properties: [
            new OA\Property(property: 'access_token', type: 'string', description: 'JWT de acceso (15 min)'),
            new OA\Property(property: 'refresh_token', type: 'string', description: 'Token de refresco con rotación (30 días)'),
            new OA\Property(property: 'expires_in', type: 'integer', description: 'Vida del access_token en segundos', example: 900),
            new OA\Property(property: 'usuario', ref: '#/components/schemas/User'),
        ],
        type: 'object'
    )]
    private ?object $_schemaAuthTokens = null;

    #[OA\Schema(
        schema: 'Credential',
        description: 'Credencial digital en respuestas de listado y detalle',
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 1),
            new OA\Property(property: 'nombre', type: 'string', example: 'Juan'),
            new OA\Property(property: 'apellido', type: 'string', example: 'Pérez'),
            new OA\Property(property: 'dni', type: 'string', nullable: true, example: '12345678'),
            new OA\Property(property: 'rol', type: 'string', example: 'usuario'),
            new OA\Property(property: 'fecha_vencimiento', type: 'string', format: 'date', example: '2027-06-01'),
            new OA\Property(property: 'estado', type: 'string', enum: ['ACTIVA', 'VENCIDA', 'INACTIVA'], example: 'ACTIVA'),
            new OA\Property(property: 'sellos', type: 'array', items: new OA\Items(type: 'string'), example: ['UNSADA']),
        ],
        type: 'object'
    )]
    private ?object $_schemaCredential = null;

    // ─── Auth ──────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/index.php?action=login',
        summary: 'Iniciar sesión',
        description: 'Autentica con identificador (usuario o DNI) y contraseña. Devuelve access_token y refresh_token.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'identificador', type: 'string', description: 'Usuario o DNI', example: 'admin'),
                new OA\Property(property: 'usuario', type: 'string', description: 'Alternativa a identificador', example: 'admin'),
                new OA\Property(property: 'dni', type: 'string', description: 'Alternativa a identificador', example: '12345678'),
                new OA\Property(property: 'password', type: 'string', example: 'admin123'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login exitoso',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'message', type: 'string', example: 'Login exitoso.'),
                    new OA\Property(property: 'data', ref: '#/components/schemas/AuthTokens'),
                ])
            ),
            new OA\Response(response: 400, description: 'Campos obligatorios faltantes', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 401, description: 'Credenciales inválidas', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limit excedido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _authLogin(): void {}

    #[OA\Post(
        path: '/index.php?action=refresh',
        summary: 'Renovar tokens',
        description: 'Renueva el access_token con un refresh_token válido. Implementa rotación del refresh token.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['refresh_token'], properties: [
                new OA\Property(property: 'refresh_token', type: 'string', example: 'a1b2c3d4e5f6...'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tokens renovados',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'message', type: 'string', example: 'Tokens renovados correctamente.'),
                    new OA\Property(property: 'data', ref: '#/components/schemas/AuthTokens'),
                ])
            ),
            new OA\Response(response: 400, description: 'refresh_token requerido', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 401, description: 'Token inválido o expirado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _authRefresh(): void {}

    #[OA\Get(
        path: '/index.php?action=me',
        summary: 'Obtener perfil del usuario autenticado',
        description: 'Devuelve los datos del usuario asociado al JWT.',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Perfil obtenido',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'message', type: 'string', example: 'Perfil obtenido correctamente.'),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'usuario', ref: '#/components/schemas/User'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _authMe(): void {}

    #[OA\Post(
        path: '/index.php?action=logout',
        summary: 'Cerrar sesión',
        description: 'Revoca el refresh token del usuario autenticado.',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sesión cerrada',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'message', type: 'string', example: 'Sesión cerrada correctamente.'),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'string'), example: []),
                ])
            ),
            new OA\Response(response: 401, description: 'No autenticado', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _authLogout(): void {}

    // ─── Credential ────────────────────────────────────────────────────────

    #[OA\Get(
        path: '/index.php?action=credential',
        summary: 'Listar credenciales activas',
        description: 'Devuelve todas las credenciales con es_activa = true.',
        tags: ['Credential'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de credenciales',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'message', type: 'string', example: 'Credenciales obtenidas.'),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Credential')),
                ])
            ),
        ]
    )]
    public function _credentialList(): void {}

    #[OA\Get(
        path: '/index.php?action=credential&id={id}',
        summary: 'Detalle de credencial',
        description: 'Devuelve una credencial activa por ID. Si está vencida, campos sensibles pueden ser null.',
        tags: ['Credential'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer'), description: 'ID de la credencial'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Credencial encontrada',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'message', type: 'string', example: 'Credencial encontrada.'),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Credential'),
                ])
            ),
            new OA\Response(response: 404, description: 'Credencial no encontrada', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _credentialShow(): void {}

    #[OA\Post(
        path: '/index.php?action=credential',
        summary: 'Emitir credencial',
        description: 'Crea una credencial nueva para un usuario. Desactiva cualquier credencial activa previa del mismo usuario.',
        tags: ['Credential'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['id_usuario', 'fecha_vencimiento'], properties: [
                new OA\Property(property: 'id_usuario', type: 'integer', example: 5),
                new OA\Property(property: 'fecha_vencimiento', type: 'string', format: 'date', example: '2027-06-01'),
                new OA\Property(property: 'sellos', type: 'array', items: new OA\Items(type: 'string'), example: ['UNSADA', 'SACRA']),
            ])
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Credencial emitida',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'message', type: 'string', example: 'Credencial emitida correctamente.'),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 10),
                        new OA\Property(property: 'fecha_emision', type: 'string', format: 'date', example: '2026-06-11'),
                        new OA\Property(property: 'fecha_vencimiento', type: 'string', format: 'date', example: '2027-06-01'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 400, description: 'Datos inválidos', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Usuario no encontrado o inactivo', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _credentialCreate(): void {}

    #[OA\Put(
        path: '/index.php?action=credential&id={id}',
        summary: 'Actualizar credencial',
        description: 'Actualiza sellos y/o fecha de vencimiento de una credencial activa.',
        tags: ['Credential'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'fecha_vencimiento', type: 'string', format: 'date', example: '2028-01-15'),
                new OA\Property(property: 'sellos', type: 'array', items: new OA\Items(type: 'string'), example: ['UNSADA']),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Credencial actualizada',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'message', type: 'string', example: 'Credencial actualizada correctamente.'),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 10),
                        new OA\Property(property: 'fecha_vencimiento', type: 'string', format: 'date', example: '2028-01-15'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 404, description: 'Credencial no encontrada', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _credentialUpdate(): void {}

    #[OA\Delete(
        path: '/index.php?action=credential&id={id}',
        summary: 'Dar de baja credencial',
        description: 'Baja lógica: marca esActiva = false sin borrar el registro.',
        tags: ['Credential'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Credencial dada de baja',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'message', type: 'string', example: 'Credencial dada de baja correctamente.'),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'string'), example: []),
                ])
            ),
            new OA\Response(response: 404, description: 'Credencial no encontrada', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _credentialDelete(): void {}

    #[OA\Post(
        path: '/index.php?action=credential&sub=renew&id={id}',
        summary: 'Renovar credencial',
        description: 'Marca la credencial actual como inactiva y crea una nueva con fecha recalculada.',
        tags: ['Credential'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Credencial renovada',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'message', type: 'string', example: 'Credencial renovada exitosamente.'),
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'nueva_id', type: 'integer', example: 11),
                        new OA\Property(property: 'fecha_emision', type: 'string', format: 'date', example: '2026-06-11'),
                        new OA\Property(property: 'fecha_vencimiento', type: 'string', format: 'date', example: '2027-06-11'),
                    ], type: 'object'),
                ])
            ),
            new OA\Response(response: 404, description: 'Credencial no encontrada', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _credentialRenew(): void {}

    #[OA\Get(
        path: '/index.php?action=credential&sub=alerts',
        summary: 'Alertas de vencimiento',
        description: 'Credenciales activas próximas a vencer en los próximos 30 días.',
        tags: ['Credential'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Credenciales por vencer',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'message', type: 'string', example: '3 credencial/es próxima/s a vencer.'),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Credential')),
                ])
            ),
        ]
    )]
    public function _credentialAlerts(): void {}
}
