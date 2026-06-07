<?php

declare(strict_types=1);

namespace ICB\Docs;

/*
 * =========================================================================
 * ESPECIFICACIÓN OPENAPI 3.1 — ICB API REST
 * =========================================================================
 *
 * Este archivo define la documentación completa de la API usando
 * atributos PHP 8 de swagger-php (zircote/swagger-php).
 *
 * No contiene lógica de negocio — solo metadatos para generar
 * public/openapi.json.
 *
 * La clase OpenApiSpec es un contenedor dummy para los atributos.
 * NO debe ser instanciada — existe únicamente para que el
 * TokenScanner de swagger-php pueda parsear los atributos.
 *
 * Comando de generación:
 *   ./vendor/bin/openapi src/Docs -o public/openapi.json
 *
 * Tags de agrupación:
 *   - Health          → Monitoreo del servidor
 *   - Auth            → Autenticación y recuperación de contraseña
 *   - Usuario         → Datos del usuario autenticado
 *   - Sellos          → Sellos institucionales (público)
 *   - Conversaciones  → Consultas de usuarios
 *   - Admin Usuarios  → CRUD de usuarios (admin)
 *   - Admin Credenciales → Emisión y renovación (admin)
 *   - Admin Sellos    → CRUD sellos (admin)
 *   - Admin Conversaciones → Bandeja de consultas (admin)
 *   - Admin Historial → Auditoría de cambios (admin)
 * =========================================================================
 */

use OpenApi\Attributes as OA;

/**
 * Contenedor dummy de atributos OpenAPI.
 *
 * Esta clase no tiene lógica de negocio. Todos sus métodos tienen cuerpo
 * vacío y todas sus constantes tienen valor null. Sirve exclusivamente
 * como soporte estructural para que el parser de swagger-php (TokenScanner)
 * pueda leer los atributos PHP 8 y generar la especificación OpenAPI.
 *
 * Los atributos PHP 8 (#[OA\Get], #[OA\Schema], etc.) NO pueden existir
 * sueltos en el ámbito global — deben estar atados a una clase, método,
 * propiedad o constante. Esta clase proporciona esos "ganchos".
 *
 * Los atributos que aplican a toda la API (OpenApi, SecurityScheme) van
 * ANTES de la declaración de la clase, lo cual es válido en PHP 8.
 *
 * Los schemas compartidos se definen como constantes de clase con
 * #[OA\Schema] para que aparezcan en components/schemas.
 *
 * Los endpoints (Get, Post, Put, Delete) se definen como métodos con
 * cuerpo vacío, agrupados por sección lógica.
 */

#[\OpenApi\Attributes\OpenApi(
    openapi: '3.1.0',
    info: new \OpenApi\Attributes\Info(
        title: 'ICB - Credenciales Digitales',
        version: '1.0.0',
        description: 'API REST del sistema de Credenciales Digitales de la Iglesia Cristiana Bíblica.

**Autenticación**: La API usa JWT (JSON Web Tokens) para autenticación stateless.
- `POST /api/auth/login` → obtiene access_token (15 min) + refresh_token (30 días)
- El access_token se envía como `Authorization: Bearer {token}`
- El refresh_token implementa rotación: cada uso invalida el anterior

**Roles**:
- **Usuario**: puede ver su perfil, credencial, y crear consultas
- **Admin**: acceso completo a todos los endpoints de administración

**Formato de respuesta**:
- Éxito: `{ "data": {...} | [...], "message": "..." }`
- Error: `{ "error": "...", "code": 400|401|403|404|500 }`
- Listas: `{ "data": [...], "total": int }`'
    ),
    servers: [
        new \OpenApi\Attributes\Server(
            url: '/icb',
            description: 'Servidor Apache (producción)'
        ),
        new \OpenApi\Attributes\Server(
            url: 'http://localhost:8000',
            description: 'Servidor de desarrollo local'
        ),
    ],
    tags: [
        new \OpenApi\Attributes\Tag(name: 'Health', description: 'Monitoreo del servidor'),
        new \OpenApi\Attributes\Tag(name: 'Auth', description: 'Autenticación y recuperación de contraseña'),
        new \OpenApi\Attributes\Tag(name: 'Usuario', description: 'Datos del usuario autenticado'),
        new \OpenApi\Attributes\Tag(name: 'Sellos', description: 'Sellos institucionales (público)'),
        new \OpenApi\Attributes\Tag(name: 'Conversaciones', description: 'Consultas de usuarios'),
        new \OpenApi\Attributes\Tag(name: 'Admin Usuarios', description: 'CRUD de usuarios'),
        new \OpenApi\Attributes\Tag(name: 'Admin Credenciales', description: 'Emisión y renovación de credenciales'),
        new \OpenApi\Attributes\Tag(name: 'Admin Sellos', description: 'CRUD de sellos institucionales'),
        new \OpenApi\Attributes\Tag(name: 'Admin Conversaciones', description: 'Bandeja de administración de consultas'),
        new \OpenApi\Attributes\Tag(name: 'Admin Historial', description: 'Auditoría de cambios del sistema'),
    ],
)]
#[\OpenApi\Attributes\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'Access token JWT obtenido de POST /api/auth/login'
)]
class OpenApiSpec
{
    // ═══════════════════════════════════════════════════════════════════════════
    //  SCHEMAS COMPARTIDOS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Schema: ErrorResponse
     *
     * Respuesta de error estándar devuelta por todos los endpoints
     * cuando ocurre una condición de error.
     */

    #[\OpenApi\Attributes\Schema(
        schema: 'ErrorResponse',
        description: 'Respuesta de error estándar',
        properties: [
            new \OpenApi\Attributes\Property(property: 'error', type: 'string', example: 'Mensaje descriptivo del error'),
            new \OpenApi\Attributes\Property(property: 'code', type: 'integer', example: 400),
        ],
        type: 'object'
    )]
    private ?object $_schemaErrorResponse = null;

    /**
     * Schema: Usuario
     *
     * Datos completos de un usuario del sistema, incluyendo roles
     * y estado. Se usa en respuestas de perfil y CRUD de admin.
     */

    #[\OpenApi\Attributes\Schema(
        schema: 'Usuario',
        description: 'Datos de un usuario del sistema',
        properties: [
            new \OpenApi\Attributes\Property(property: 'id', type: 'integer', example: 1),
            new \OpenApi\Attributes\Property(property: 'usuario', type: 'string', maxLength: 50, example: 'admin'),
            new \OpenApi\Attributes\Property(property: 'dni', type: 'string', maxLength: 20, example: '12345678'),
            new \OpenApi\Attributes\Property(property: 'nombre', type: 'string', maxLength: 100, example: 'Administrador'),
            new \OpenApi\Attributes\Property(property: 'apellido', type: 'string', maxLength: 100, example: 'Principal'),
            new \OpenApi\Attributes\Property(property: 'email', type: 'string', maxLength: 100, example: 'admin@icb.com'),
            new \OpenApi\Attributes\Property(property: 'telefono', type: 'string', maxLength: 30, nullable: true, example: '000000000'),
            new \OpenApi\Attributes\Property(property: 'direccion', type: 'string', maxLength: 255, nullable: true),
            new \OpenApi\Attributes\Property(property: 'funcion', type: 'string', maxLength: 100, nullable: true, example: 'Administrador General'),
            new \OpenApi\Attributes\Property(property: 'estado', type: 'string', enum: ['Activo', 'Inactivo'], example: 'Activo'),
            new \OpenApi\Attributes\Property(property: 'roles', type: 'array', items: new \OpenApi\Attributes\Items(type: 'string'), example: ['Admin', 'Usuario']),
            new \OpenApi\Attributes\Property(property: 'fecha_alta', type: 'string', format: 'date-time', example: '2026-06-01 01:36:49'),
        ],
        type: 'object'
    )]
    private ?object $_schemaUsuario = null;

    /**
     * Schema: Credencial
     *
     * Credencial digital emitida a un miembro de la iglesia,
     * con código QR único y fechas de emisión/vencimiento.
     */

    #[\OpenApi\Attributes\Schema(
        schema: 'Credencial',
        description: 'Credencial digital de un miembro',
        properties: [
            new \OpenApi\Attributes\Property(property: 'id', type: 'integer', example: 1),
            new \OpenApi\Attributes\Property(property: 'id_usuario', type: 'integer', example: 1),
            new \OpenApi\Attributes\Property(property: 'usuario_nombre', type: 'string', example: 'Administrador Principal'),
            new \OpenApi\Attributes\Property(property: 'usuario_dni', type: 'string', example: '12345678'),
            new \OpenApi\Attributes\Property(property: 'fecha_emision', type: 'string', format: 'date', example: '2026-06-03'),
            new \OpenApi\Attributes\Property(property: 'fecha_vencimiento', type: 'string', format: 'date', example: '2027-06-03'),
            new \OpenApi\Attributes\Property(property: 'foto', type: 'string', nullable: true, example: null),
            new \OpenApi\Attributes\Property(property: 'codigo_qr', type: 'string', example: 'ICB-A1B2C3D4E5F6789012345678ABCDEF01'),
            new \OpenApi\Attributes\Property(property: 'activa', type: 'boolean', example: true),
            new \OpenApi\Attributes\Property(property: 'fecha_baja', type: 'string', format: 'date-time', nullable: true, example: null),
        ],
        type: 'object'
    )]
    private ?object $_schemaCredencial = null;

    /**
     * Schema: Sello
     *
     * Sello institucional que aparece en las credenciales digitales.
     * Los sellos activos se exponen públicamente.
     */

    #[\OpenApi\Attributes\Schema(
        schema: 'Sello',
        description: 'Sello institucional que aparece en las credenciales',
        properties: [
            new \OpenApi\Attributes\Property(property: 'id', type: 'integer', example: 1),
            new \OpenApi\Attributes\Property(property: 'nombre', type: 'string', maxLength: 100, example: 'Sello Oficial'),
            new \OpenApi\Attributes\Property(property: 'imagen_url', type: 'string', format: 'uri', example: '/images/sellos/ICB-A1B2C3D4E5F6.png'),
            new \OpenApi\Attributes\Property(property: 'activo', type: 'boolean', example: true),
            new \OpenApi\Attributes\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-06-03 15:00:00'),
        ],
        type: 'object'
    )]
    private ?object $_schemaSello = null;

    /**
     * Schema: Conversacion
     *
     * Hilo de consulta entre un usuario y la administración.
     * Incluye metadatos como estado y último mensaje.
     */

    #[\OpenApi\Attributes\Schema(
        schema: 'Conversacion',
        description: 'Hilo de consulta entre un usuario y la administración',
        properties: [
            new \OpenApi\Attributes\Property(property: 'id', type: 'integer', example: 1),
            new \OpenApi\Attributes\Property(property: 'usuario', properties: [
                new \OpenApi\Attributes\Property(property: 'id', type: 'integer', example: 1),
                new \OpenApi\Attributes\Property(property: 'nombre', type: 'string', example: 'Administrador Principal'),
            ], type: 'object'),
            new \OpenApi\Attributes\Property(property: 'estado', type: 'string', enum: ['Abierta', 'Cerrada'], example: 'Abierta'),
            new \OpenApi\Attributes\Property(property: 'fecha_creacion', type: 'string', format: 'date-time', example: '2026-06-03 15:00:00'),
            new \OpenApi\Attributes\Property(property: 'ultimo_mensaje', type: 'string', nullable: true, example: 'Gracias por su ayuda'),
            new \OpenApi\Attributes\Property(property: 'total_mensajes', type: 'integer', example: 3),
        ],
        type: 'object'
    )]
    private ?object $_schemaConversacion = null;

    /**
     * Schema: Mensaje
     *
     * Mensaje individual dentro de una conversación, con su emisor
     * (usuario o admin) y fecha de envío.
     */

    #[\OpenApi\Attributes\Schema(
        schema: 'Mensaje',
        description: 'Mensaje individual dentro de una conversación',
        properties: [
            new \OpenApi\Attributes\Property(property: 'id', type: 'integer', example: 1),
            new \OpenApi\Attributes\Property(property: 'contenido', type: 'string', example: 'Hola, necesito información'),
            new \OpenApi\Attributes\Property(property: 'emisor', properties: [
                new \OpenApi\Attributes\Property(property: 'id', type: 'integer', example: 1),
                new \OpenApi\Attributes\Property(property: 'nombre', type: 'string', example: 'Administrador Principal'),
                new \OpenApi\Attributes\Property(property: 'es_admin', type: 'boolean', example: true),
            ], type: 'object'),
            new \OpenApi\Attributes\Property(property: 'fecha_envio', type: 'string', format: 'date-time', example: '2026-06-03 15:00:00'),
        ],
        type: 'object'
    )]
    private ?object $_schemaMensaje = null;

    /**
     * Schema: HistorialEntry
     *
     * Registro de auditoría que captura cambios en el sistema:
     * quién hizo el cambio, qué cambió, valor anterior y nuevo.
     */

    #[\OpenApi\Attributes\Schema(
        schema: 'HistorialEntry',
        description: 'Registro de auditoría de cambios en el sistema',
        properties: [
            new \OpenApi\Attributes\Property(property: 'id', type: 'integer', example: 1),
            new \OpenApi\Attributes\Property(property: 'admin', properties: [
                new \OpenApi\Attributes\Property(property: 'id', type: 'integer', example: 1),
                new \OpenApi\Attributes\Property(property: 'nombre', type: 'string', example: 'Administrador Principal'),
            ], type: 'object'),
            new \OpenApi\Attributes\Property(property: 'tabla', type: 'string', example: 'usuarios'),
            new \OpenApi\Attributes\Property(property: 'registro_id', type: 'integer', example: 3),
            new \OpenApi\Attributes\Property(property: 'accion', type: 'string', enum: ['CREAR', 'EDITAR', 'BAJA', 'RESTAURAR', 'RENOVAR'], example: 'CREAR'),
            new \OpenApi\Attributes\Property(property: 'valor_anterior', type: 'string', nullable: true, description: 'Snapshot JSON del estado anterior'),
            new \OpenApi\Attributes\Property(property: 'valor_nuevo', type: 'string', nullable: true, description: 'Snapshot JSON del nuevo estado'),
            new \OpenApi\Attributes\Property(property: 'fecha', type: 'string', format: 'date-time', example: '2026-06-03 15:22:21'),
        ],
        type: 'object'
    )]
    private ?object $_schemaHistorialEntry = null;

    /**
     * Schema: AuthTokens
     *
     * Paquete de tokens devuelto al iniciar sesión o renovar.
     * Incluye access_token, refresh_token y datos del usuario.
     */

    #[\OpenApi\Attributes\Schema(
        schema: 'AuthTokens',
        description: 'Tokens de autenticación',
        properties: [
            new \OpenApi\Attributes\Property(property: 'access_token', type: 'string', description: 'JWT de acceso (15 min)'),
            new \OpenApi\Attributes\Property(property: 'refresh_token', type: 'string', description: 'Token de refresco con rotación (30 días)'),
            new \OpenApi\Attributes\Property(property: 'expires_in', type: 'integer', description: 'Tiempo de vida del access_token en segundos', example: 900),
            new \OpenApi\Attributes\Property(property: 'usuario', ref: '#/components/schemas/Usuario'),
        ],
        type: 'object'
    )]
    private ?object $_schemaAuthTokens = null;

    // ═══════════════════════════════════════════════════════════════════════════
    //  HEALTH
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/health
     *
     * Health check del servidor. Verifica que el servidor y la base
     * de datos estén operativos. Útil para monitoreo y balanceadores.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/health',
        summary: 'Health check del servidor',
        description: 'Verifica que el servidor y la base de datos estén operativos. Útil para monitoreo y balanceadores de carga.',
        tags: ['Health'],
        responses: [
            new \OpenApi\Attributes\Response(
                response: 200,
                description: 'Servidor operativo',
                content: new \OpenApi\Attributes\JsonContent(properties: [
                    new \OpenApi\Attributes\Property(property: 'status', type: 'string', example: 'ok'),
                    new \OpenApi\Attributes\Property(property: 'database', type: 'string', enum: ['connected', 'disconnected'], example: 'connected'),
                    new \OpenApi\Attributes\Property(property: 'timestamp', type: 'string', format: 'date-time', example: '2026-06-03T15:00:00+00:00'),
                ])
            ),
        ]
    )]
    public function _health(): void {}

    // ═══════════════════════════════════════════════════════════════════════════
    //  AUTH — Públicos (sin autenticación)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * POST /api/auth/login
     *
     * Autentica un usuario con usuario/DNI y contraseña. Devuelve
     * access_token (JWT, 15 min) y refresh_token (30 días, con rotación).
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/auth/login',
        summary: 'Iniciar sesión',
        description: 'Autentica un usuario con usuario/DNI y contraseña. Devuelve access_token (JWT, 15 min) y refresh_token (30 días, con rotación).',
        tags: ['Auth'],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'usuario', type: 'string', description: 'Nombre de usuario (alternativo a DNI)', example: 'admin'),
                new \OpenApi\Attributes\Property(property: 'dni', type: 'string', description: 'DNI del usuario (alternativo a usuario)', example: '12345678'),
                new \OpenApi\Attributes\Property(property: 'password', type: 'string', description: 'Contraseña del usuario', example: 'admin123'),
            ])
        ),
        responses: [
            new \OpenApi\Attributes\Response(
                response: 200,
                description: 'Inicio de sesión exitoso',
                content: new \OpenApi\Attributes\JsonContent(properties: [
                    new \OpenApi\Attributes\Property(property: 'data', ref: '#/components/schemas/AuthTokens'),
                    new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Inicio de sesión exitoso'),
                ])
            ),
            new \OpenApi\Attributes\Response(response: 400, description: 'Faltan credenciales', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 401, description: 'Credenciales inválidas', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _authLogin(): void {}

    /**
     * POST /api/auth/refresh
     *
     * Renueva el access_token usando un refresh_token válido.
     * Implementa rotación: el token anterior se invalida y se genera uno nuevo.
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/auth/refresh',
        summary: 'Renovar tokens',
        description: 'Renueva el access_token usando un refresh_token válido. Implementa rotación: el token anterior se invalida y se genera uno nuevo.',
        tags: ['Auth'],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'refresh_token', type: 'string', description: 'Refresh token obtenido del login', example: 'a1b2c3d4...'),
            ])
        ),
        responses: [
            new \OpenApi\Attributes\Response(
                response: 200,
                description: 'Tokens renovados exitosamente',
                content: new \OpenApi\Attributes\JsonContent(properties: [
                    new \OpenApi\Attributes\Property(property: 'data', ref: '#/components/schemas/AuthTokens'),
                    new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Tokens renovados exitosamente'),
                ])
            ),
            new \OpenApi\Attributes\Response(response: 400, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token inválido o expirado', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _authRefresh(): void {}

    /**
     * POST /api/auth/recuperar-solicitar
     *
     * Inicia el proceso de recuperación de contraseña. Genera un
     * token de un solo uso (64 hex chars, expira en 60 min) y lo
     * devuelve en la respuesta para desarrollo.
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/auth/recuperar-solicitar',
        summary: 'Solicitar recuperación de contraseña',
        description: 'Inicia el proceso de recuperación. Genera un token de un solo uso (64 hex chars, expira en 60 min) y lo devuelve en la respuesta para desarrollo. En producción se enviaría por email.',
        tags: ['Auth'],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'email', type: 'string', format: 'email', description: 'Email del usuario registrado', example: 'admin@icb.com'),
            ])
        ),
        responses: [
            new \OpenApi\Attributes\Response(
                response: 200,
                description: 'Solicitud procesada',
                content: new \OpenApi\Attributes\JsonContent(properties: [
                    new \OpenApi\Attributes\Property(property: 'data', properties: [
                        new \OpenApi\Attributes\Property(property: 'mensaje', type: 'string', example: 'Si el email está registrado, recibirás instrucciones'),
                        new \OpenApi\Attributes\Property(property: 'token', type: 'string', description: 'Token de recuperación (solo en desarrollo)', example: 'a1b2c3d4...'),
                    ], type: 'object'),
                    new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Solicitud de recuperación procesada'),
                ])
            ),
            new \OpenApi\Attributes\Response(response: 400, description: 'Email inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _authRecuperarSolicitar(): void {}

    /**
     * POST /api/auth/recuperar-confirmar
     *
     * Valida el token de recuperación y cambia la contraseña.
     * El token es de un solo uso y expira a los 60 minutos.
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/auth/recuperar-confirmar',
        summary: 'Confirmar recuperación de contraseña',
        description: 'Valida el token de recuperación y cambia la contraseña. El token es de un solo uso y expira a los 60 minutos.',
        tags: ['Auth'],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'token', type: 'string', description: 'Token de recuperación recibido', example: 'a1b2c3d4...'),
                new \OpenApi\Attributes\Property(property: 'password', type: 'string', minLength: 6, description: 'Nueva contraseña (mínimo 6 caracteres)', example: 'nuevaPass123'),
            ])
        ),
        responses: [
            new \OpenApi\Attributes\Response(
                response: 200,
                description: 'Contraseña actualizada',
                content: new \OpenApi\Attributes\JsonContent(properties: [
                    new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Contraseña actualizada exitosamente'),
                ])
            ),
            new \OpenApi\Attributes\Response(response: 400, description: 'Token inválido/expirado o password débil', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _authRecuperarConfirmar(): void {}

    // ═══════════════════════════════════════════════════════════════════════════
    //  USUARIO AUTENTICADO (Bearer Token)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/auth/me
     *
     * Devuelve los datos del usuario autenticado.
     * No incluye datos sensibles (password_hash, tokens).
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/auth/me',
        summary: 'Obtener usuario actual',
        description: 'Devuelve los datos del usuario autenticado. No incluye datos sensibles (password_hash, tokens).',
        tags: ['Usuario'],
        security: [['bearerAuth' => []]],
        responses: [
            new \OpenApi\Attributes\Response(
                response: 200,
                description: 'Datos del usuario',
                content: new \OpenApi\Attributes\JsonContent(properties: [
                    new \OpenApi\Attributes\Property(property: 'data', ref: '#/components/schemas/Usuario'),
                ])
            ),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido o inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _authMe(): void {}

    /**
     * GET /api/auth/mi-credencial
     *
     * Devuelve la credencial activa del usuario autenticado.
     * Retorna null si el usuario no tiene una credencial activa.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/auth/mi-credencial',
        summary: 'Mi credencial activa',
        description: 'Devuelve la credencial activa del usuario autenticado. Retorna null si el usuario no tiene una credencial activa (sin fecha de baja).',
        tags: ['Usuario'],
        security: [['bearerAuth' => []]],
        responses: [
            new \OpenApi\Attributes\Response(
                response: 200,
                description: 'Credencial activa o null',
                content: new \OpenApi\Attributes\JsonContent(properties: [
                    new \OpenApi\Attributes\Property(property: 'data', anyOf: [
                        new \OpenApi\Attributes\Schema(ref: '#/components/schemas/Credencial'),
                        new \OpenApi\Attributes\Schema(type: 'null'),
                    ]),
                    new \OpenApi\Attributes\Property(property: 'message', type: 'string'),
                ])
            ),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido o inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _authMiCredencial(): void {}

    // ═══════════════════════════════════════════════════════════════════════════
    //  SELLOS PÚBLICOS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/sellos
     *
     * Endpoint público que devuelve los sellos institucionales activos.
     * Se usa para mostrar los sellos en las credenciales digitales publicadas.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/sellos',
        summary: 'Listar sellos activos',
        description: 'Endpoint público que devuelve los sellos institucionales activos. Se usa para mostrar los sellos en las credenciales digitales publicadas.',
        tags: ['Sellos'],
        responses: [
            new \OpenApi\Attributes\Response(
                response: 200,
                description: 'Lista de sellos activos',
                content: new \OpenApi\Attributes\JsonContent(properties: [
                    new \OpenApi\Attributes\Property(property: 'data', type: 'array', items: new \OpenApi\Attributes\Items(ref: '#/components/schemas/Sello')),
                    new \OpenApi\Attributes\Property(property: 'total', type: 'integer'),
                ])
            ),
        ]
    )]
    public function _sellosList(): void {}

    // ═══════════════════════════════════════════════════════════════════════════
    //  CONVERSACIONES — Usuario autenticado
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/conversaciones
     *
     * Lista las conversaciones del usuario autenticado.
     * Incluye el último mensaje y el total de mensajes por conversación.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/conversaciones',
        summary: 'Listar mis conversaciones',
        description: 'Lista las conversaciones del usuario autenticado. Incluye el último mensaje y el total de mensajes por conversación.',
        tags: ['Conversaciones'],
        security: [['bearerAuth' => []]],
        responses: [
            new \OpenApi\Attributes\Response(
                response: 200,
                description: 'Lista de conversaciones del usuario',
                content: new \OpenApi\Attributes\JsonContent(properties: [
                    new \OpenApi\Attributes\Property(property: 'data', type: 'array', items: new \OpenApi\Attributes\Items(ref: '#/components/schemas/Conversacion')),
                    new \OpenApi\Attributes\Property(property: 'total', type: 'integer'),
                ])
            ),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _conversacionesList(): void {}

    /**
     * POST /api/conversaciones
     *
     * Crea una nueva conversación con el primer mensaje.
     * No existen conversaciones vacías: el contenido es obligatorio.
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/conversaciones',
        summary: 'Crear conversación',
        description: 'Crea una nueva conversación con el primer mensaje. No existen conversaciones vacías: el contenido es obligatorio.',
        tags: ['Conversaciones'],
        security: [['bearerAuth' => []]],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'contenido', type: 'string', description: 'Primer mensaje de la consulta', example: 'Hola, necesito información sobre mi credencial'),
            ])
        ),
        responses: [
            new \OpenApi\Attributes\Response(
                response: 200,
                description: 'Conversación creada. Devuelve la lista actualizada de conversaciones.',
                content: new \OpenApi\Attributes\JsonContent(properties: [
                    new \OpenApi\Attributes\Property(property: 'data', type: 'array', items: new \OpenApi\Attributes\Items(ref: '#/components/schemas/Conversacion')),
                    new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Consulta creada exitosamente'),
                ])
            ),
            new \OpenApi\Attributes\Response(response: 400, description: 'Contenido requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _conversacionesCreate(): void {}

    /**
     * GET /api/conversaciones/{id}/mensajes
     *
     * Obtiene los mensajes de una conversación.
     * Verifica que el usuario autenticado sea el dueño de la conversación.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/conversaciones/{id}/mensajes',
        summary: 'Ver mensajes de una conversación',
        description: 'Obtiene los mensajes de una conversación. Verifica que el usuario autenticado sea el dueño de la conversación.',
        tags: ['Conversaciones'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer'), description: 'ID de la conversación'),
        ],
        responses: [
            new \OpenApi\Attributes\Response(
                response: 200,
                description: 'Mensajes de la conversación',
                content: new \OpenApi\Attributes\JsonContent(properties: [
                    new \OpenApi\Attributes\Property(property: 'data', type: 'array', items: new \OpenApi\Attributes\Items(ref: '#/components/schemas/Mensaje')),
                    new \OpenApi\Attributes\Property(property: 'total', type: 'integer'),
                ])
            ),
            new \OpenApi\Attributes\Response(response: 400, description: 'ID inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 404, description: 'Conversación no encontrada', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _conversacionesMensajesList(): void {}

    /**
     * POST /api/conversaciones/{id}/mensajes
     *
     * Agrega un mensaje a una conversación existente.
     * La conversación debe estar abierta (no cerrada).
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/conversaciones/{id}/mensajes',
        summary: 'Enviar mensaje en conversación',
        description: 'Agrega un mensaje a una conversación existente. La conversación debe estar abierta (no cerrada).',
        tags: ['Conversaciones'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer'), description: 'ID de la conversación'),
        ],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'contenido', type: 'string', description: 'Contenido del mensaje', example: 'Gracias por la respuesta'),
            ])
        ),
        responses: [
            new \OpenApi\Attributes\Response(
                response: 200,
                description: 'Mensaje enviado. Devuelve la lista actualizada de mensajes.',
                content: new \OpenApi\Attributes\JsonContent(properties: [
                    new \OpenApi\Attributes\Property(property: 'data', type: 'array', items: new \OpenApi\Attributes\Items(ref: '#/components/schemas/Mensaje')),
                    new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Mensaje enviado'),
                ])
            ),
            new \OpenApi\Attributes\Response(response: 400, description: 'Conversación cerrada o ID inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _conversacionesMensajesCreate(): void {}

    // ═══════════════════════════════════════════════════════════════════════════
    //  ADMIN — USUARIOS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/admin/usuarios
     *
     * Lista todos los usuarios del sistema con filtros opcionales
     * de búsqueda y estado.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/admin/usuarios',
        summary: 'Listar usuarios',
        description: 'Lista todos los usuarios del sistema con filtros opcionales de búsqueda y estado.',
        tags: ['Admin Usuarios'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'busqueda', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'string'), description: 'Búsqueda textual parcial (DNI, apellido, función)'),
            new \OpenApi\Attributes\Parameter(name: 'estado', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'string', enum: ['Activo', 'Inactivo']), description: 'Filtrar por estado'),
        ],
        responses: [
            new \OpenApi\Attributes\Response(
                response: 200,
                description: 'Lista de usuarios',
                content: new \OpenApi\Attributes\JsonContent(properties: [
                    new \OpenApi\Attributes\Property(property: 'data', type: 'array', items: new \OpenApi\Attributes\Items(ref: '#/components/schemas/Usuario')),
                    new \OpenApi\Attributes\Property(property: 'total', type: 'integer'),
                ])
            ),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 403, description: 'Se requiere rol Admin', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminUsuariosList(): void {}

    /**
     * GET /api/admin/usuarios/{id}
     *
     * Obtiene los datos de un usuario específico por su ID.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/admin/usuarios/{id}',
        summary: 'Obtener usuario por ID',
        tags: ['Admin Usuarios'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer'), description: 'ID del usuario'),
        ],
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Datos del usuario', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', ref: '#/components/schemas/Usuario'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'ID inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 404, description: 'Usuario no encontrado', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminUsuariosGet(): void {}

    /**
     * POST /api/admin/usuarios
     *
     * Crea un nuevo usuario en el sistema. La contraseña se hashea
     * con bcrypt (costo 12). Queda registrado en el historial de cambios.
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/admin/usuarios',
        summary: 'Crear usuario',
        description: 'Crea un nuevo usuario en el sistema. La contraseña se hashea con bcrypt (costo 12). Queda registrado en el historial de cambios.',
        tags: ['Admin Usuarios'],
        security: [['bearerAuth' => []]],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'dni', type: 'string', maxLength: 20, description: 'DNI (único)', example: '87654321'),
                new \OpenApi\Attributes\Property(property: 'usuario', type: 'string', maxLength: 50, description: 'Nombre de usuario (único)', example: 'jperez'),
                new \OpenApi\Attributes\Property(property: 'password', type: 'string', minLength: 6, description: 'Contraseña (mínimo 6 caracteres)', example: 'pass123'),
                new \OpenApi\Attributes\Property(property: 'nombre', type: 'string', maxLength: 100, description: 'Nombre', example: 'Juan'),
                new \OpenApi\Attributes\Property(property: 'apellido', type: 'string', maxLength: 100, description: 'Apellido', example: 'Pérez'),
                new \OpenApi\Attributes\Property(property: 'email', type: 'string', format: 'email', maxLength: 100, description: 'Email (único)', example: 'jperez@icb.com'),
                new \OpenApi\Attributes\Property(property: 'telefono', type: 'string', maxLength: 30, description: 'Teléfono (opcional)', example: '1122334455'),
                new \OpenApi\Attributes\Property(property: 'direccion', type: 'string', maxLength: 255, description: 'Dirección (opcional)'),
                new \OpenApi\Attributes\Property(property: 'funcion', type: 'string', maxLength: 100, description: 'Cargo o función (opcional)', example: 'Diácono'),
                new \OpenApi\Attributes\Property(property: 'roles', type: 'array', items: new \OpenApi\Attributes\Items(type: 'string'), description: 'Roles (default: ["Usuario"])', example: ['Usuario']),
            ])
        ),
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Usuario creado', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', ref: '#/components/schemas/Usuario'),
                new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Usuario creado exitosamente'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'Datos inválidos o duplicados', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 403, description: 'Se requiere rol Admin', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminUsuariosCreate(): void {}

    /**
     * PUT /api/admin/usuarios/{id}
     *
     * Actualiza datos de un usuario existente. Solo modifica los
     * campos presentes en el body (parcial). Queda registrado en el historial.
     */

    #[\OpenApi\Attributes\Put(
        path: '/api/admin/usuarios/{id}',
        summary: 'Actualizar usuario',
        description: 'Actualiza datos de un usuario existente. Solo modifica los campos presentes en el body (parcial). Queda registrado en el historial.',
        tags: ['Admin Usuarios'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer')),
        ],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'dni', type: 'string', maxLength: 20),
                new \OpenApi\Attributes\Property(property: 'usuario', type: 'string', maxLength: 50),
                new \OpenApi\Attributes\Property(property: 'password', type: 'string', minLength: 6),
                new \OpenApi\Attributes\Property(property: 'nombre', type: 'string', maxLength: 100),
                new \OpenApi\Attributes\Property(property: 'apellido', type: 'string', maxLength: 100),
                new \OpenApi\Attributes\Property(property: 'email', type: 'string', format: 'email', maxLength: 100),
                new \OpenApi\Attributes\Property(property: 'telefono', type: 'string', maxLength: 30),
                new \OpenApi\Attributes\Property(property: 'direccion', type: 'string', maxLength: 255),
                new \OpenApi\Attributes\Property(property: 'funcion', type: 'string', maxLength: 100),
                new \OpenApi\Attributes\Property(property: 'roles', type: 'array', items: new \OpenApi\Attributes\Items(type: 'string')),
            ])
        ),
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Usuario actualizado', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', ref: '#/components/schemas/Usuario'),
                new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Usuario actualizado exitosamente'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'Datos inválidos', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 404, description: 'Usuario no encontrado', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminUsuariosUpdate(): void {}

    /**
     * DELETE /api/admin/usuarios/{id}
     *
     * Da de baja un usuario de forma lógica: cambia su estado a
     * "Inactivo". NO elimina el registro. Queda registrado en el historial.
     */

    #[\OpenApi\Attributes\Delete(
        path: '/api/admin/usuarios/{id}',
        summary: 'Eliminar usuario (baja lógica)',
        description: 'Da de baja un usuario de forma lógica: cambia su estado a "Inactivo". NO elimina el registro. Queda registrado en el historial.',
        tags: ['Admin Usuarios'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer')),
        ],
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Usuario dado de baja', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Usuario dado de baja exitosamente'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'Ya está inactivo', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 404, description: 'Usuario no encontrado', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminUsuariosDelete(): void {}

    /**
     * POST /api/admin/usuarios/{id}/restaurar
     *
     * Reactiva un usuario que estaba inactivo. Inverso del DELETE
     * lógico. Queda registrado en el historial.
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/admin/usuarios/{id}/restaurar',
        summary: 'Restaurar usuario',
        description: 'Reactiva un usuario que estaba inactivo. Inverso del DELETE lógico. Queda registrado en el historial.',
        tags: ['Admin Usuarios'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer')),
        ],
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Usuario restaurado', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', ref: '#/components/schemas/Usuario'),
                new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Usuario restaurado exitosamente'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'ID inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 404, description: 'Usuario no encontrado', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminUsuariosRestore(): void {}

    /**
     * GET /api/admin/usuarios/{id}/historial
     *
     * Obtiene el historial de cambios específico de un usuario.
     * Útil para auditar qué cambios se hicieron y quién los hizo.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/admin/usuarios/{id}/historial',
        summary: 'Historial de cambios de un usuario',
        description: 'Obtiene el historial de cambios específico de un usuario. Útil para auditar qué cambios se hicieron y quién los hizo.',
        tags: ['Admin Historial'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer'), description: 'ID del usuario'),
            new \OpenApi\Attributes\Parameter(name: 'tabla', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'string'), description: 'Filtrar por tabla afectada'),
            new \OpenApi\Attributes\Parameter(name: 'accion', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'string', enum: ['CREAR', 'EDITAR', 'BAJA', 'RESTAURAR', 'RENOVAR']), description: 'Filtrar por tipo de acción'),
            new \OpenApi\Attributes\Parameter(name: 'fecha_desde', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'string', format: 'date'), description: 'Fecha inicio (Y-m-d)'),
            new \OpenApi\Attributes\Parameter(name: 'fecha_hasta', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'string', format: 'date'), description: 'Fecha fin (Y-m-d)'),
            new \OpenApi\Attributes\Parameter(name: 'page', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'integer', default: 1), description: 'Número de página'),
            new \OpenApi\Attributes\Parameter(name: 'limit', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'integer', default: 50, maximum: 100), description: 'Resultados por página'),
        ],
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Historial del usuario', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', type: 'array', items: new \OpenApi\Attributes\Items(ref: '#/components/schemas/HistorialEntry')),
                new \OpenApi\Attributes\Property(property: 'total', type: 'integer'),
                new \OpenApi\Attributes\Property(property: 'page', type: 'integer'),
                new \OpenApi\Attributes\Property(property: 'limit', type: 'integer'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'ID inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminUsuariosHistorial(): void {}

    // ═══════════════════════════════════════════════════════════════════════════
    //  ADMIN — CREDENCIALES
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/admin/credenciales
     *
     * Lista todas las credenciales del sistema, opcionalmente
     * filtradas por usuario. Ordenadas por fecha de emisión descendente.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/admin/credenciales',
        summary: 'Listar credenciales',
        description: 'Lista todas las credenciales del sistema, opcionalmente filtradas por usuario. Ordenadas por fecha de emisión descendente.',
        tags: ['Admin Credenciales'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'usuario_id', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'integer'), description: 'Filtrar por usuario'),
        ],
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Lista de credenciales', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', type: 'array', items: new \OpenApi\Attributes\Items(ref: '#/components/schemas/Credencial')),
                new \OpenApi\Attributes\Property(property: 'total', type: 'integer'),
            ])),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 403, description: 'Se requiere rol Admin', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminCredencialesList(): void {}

    /**
     * POST /api/admin/credenciales
     *
     * Emite una nueva credencial digital para un usuario. Genera un
     * código QR único de 32 caracteres hexadecimales. Queda registrado
     * en el historial.
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/admin/credenciales',
        summary: 'Emitir credencial',
        description: 'Emite una nueva credencial digital para un usuario. Genera un código QR único de 32 caracteres hexadecimales. Queda registrado en el historial.',
        tags: ['Admin Credenciales'],
        security: [['bearerAuth' => []]],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'id_usuario', type: 'integer', description: 'ID del usuario', example: 1),
                new \OpenApi\Attributes\Property(property: 'fecha_vencimiento', type: 'string', format: 'date', description: 'Fecha de vencimiento (debe ser futura)', example: '2027-06-04'),
                new \OpenApi\Attributes\Property(property: 'foto', type: 'string', format: 'uri', description: 'URL de foto (opcional)', example: 'https://ejemplo.com/foto.jpg'),
            ])
        ),
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Credencial emitida', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', ref: '#/components/schemas/Credencial'),
                new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Credencial emitida exitosamente'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'Datos inválidos', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 404, description: 'Usuario no encontrado', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminCredencialesCreate(): void {}

    /**
     * POST /api/admin/credenciales/{id}/renovar
     *
     * Renueva una credencial: la anterior se desactiva (fecha de baja)
     * y se crea una nueva con nuevo código QR. Mantiene el mismo usuario.
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/admin/credenciales/{id}/renovar',
        summary: 'Renovar credencial',
        description: 'Renueva una credencial: la anterior se desactiva (fecha de baja) y se crea una nueva con nuevo código QR. La nueva credencial mantiene el mismo usuario.',
        tags: ['Admin Credenciales'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer'), description: 'ID de la credencial a renovar'),
        ],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'fecha_vencimiento', type: 'string', format: 'date', description: 'Nueva fecha de vencimiento (default: +1 año)', example: '2028-06-04'),
                new \OpenApi\Attributes\Property(property: 'foto', type: 'string', format: 'uri', description: 'Nueva foto (opcional, conserva anterior)', example: 'https://ejemplo.com/nueva-foto.jpg'),
            ])
        ),
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Credencial renovada', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', ref: '#/components/schemas/Credencial'),
                new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Credencial renovada exitosamente'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'Datos inválidos', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 404, description: 'Credencial no encontrada', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminCredencialesRenovar(): void {}

    // ═══════════════════════════════════════════════════════════════════════════
    //  ADMIN — SELLOS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/admin/sellos
     *
     * Lista TODOS los sellos (activos e inactivos). Diferencia con
     * GET /api/sellos que solo devuelve los activos.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/admin/sellos',
        summary: 'Listar todos los sellos',
        description: 'Lista TODOS los sellos (activos e inactivos). Diferencia con GET /api/sellos que solo devuelve los activos.',
        tags: ['Admin Sellos'],
        security: [['bearerAuth' => []]],
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Lista de sellos', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', type: 'array', items: new \OpenApi\Attributes\Items(ref: '#/components/schemas/Sello')),
                new \OpenApi\Attributes\Property(property: 'total', type: 'integer'),
            ])),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 403, description: 'Se requiere rol Admin', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminSellosList(): void {}

    /**
     * GET /api/admin/sellos/{id}
     *
     * Obtiene los datos de un sello específico por su ID.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/admin/sellos/{id}',
        summary: 'Obtener sello por ID',
        tags: ['Admin Sellos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer')),
        ],
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Datos del sello', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', ref: '#/components/schemas/Sello'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'ID inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 404, description: 'Sello no encontrado', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminSellosGet(): void {}

    /**
     * POST /api/admin/sellos
     *
     * Crea un nuevo sello institucional con URL de imagen.
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/admin/sellos',
        summary: 'Crear sello',
        description: 'Crea un nuevo sello institucional con URL de imagen.',
        tags: ['Admin Sellos'],
        security: [['bearerAuth' => []]],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'nombre', type: 'string', maxLength: 100, description: 'Nombre del sello', example: 'Sello Oficial'),
                new \OpenApi\Attributes\Property(property: 'imagen_url', type: 'string', format: 'uri', maxLength: 255, description: 'URL de la imagen', example: '/images/sellos/ICB-A1B2C3D4.png'),
                new \OpenApi\Attributes\Property(property: 'activo', type: 'boolean', description: 'Activo (default: true)', example: true),
            ])
        ),
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Sello creado', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', ref: '#/components/schemas/Sello'),
                new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Sello creado exitosamente'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'Datos inválidos', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 403, description: 'Se requiere rol Admin', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminSellosCreate(): void {}

    /**
     * POST /api/admin/sellos/upload
     *
     * Sube una imagen para un sello mediante formulario multipart.
     * La imagen se guarda en el servidor con nombre único.
     * Formatos: PNG, JPG, WEBP, SVG. Máximo 2MB.
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/admin/sellos/upload',
        summary: 'Subir imagen de sello',
        description: 'Sube una imagen para un sello mediante formulario multipart. La imagen se guarda en el servidor con nombre único. Formatos: PNG, JPG, WEBP, SVG. Máximo 2MB.',
        tags: ['Admin Sellos'],
        security: [['bearerAuth' => []]],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\MediaType(
                mediaType: 'multipart/form-data',
                schema: new \OpenApi\Attributes\Schema(properties: [
                    new \OpenApi\Attributes\Property(property: 'nombre', type: 'string', description: 'Nombre del sello'),
                    new \OpenApi\Attributes\Property(property: 'imagen', type: 'string', format: 'binary', description: 'Archivo de imagen (PNG, JPG, WEBP, SVG, máx 2MB)'),
                    new \OpenApi\Attributes\Property(property: 'activo', type: 'string', description: 'true/false (opcional, default: true)'),
                ])
            )
        ),
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Sello creado con imagen', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', ref: '#/components/schemas/Sello'),
                new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Sello creado exitosamente'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'Archivo inválido o faltante', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 403, description: 'Se requiere rol Admin', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminSellosUpload(): void {}

    /**
     * PUT /api/admin/sellos/{id}
     *
     * Actualiza datos de un sello existente. Solo modifica los
     * campos presentes (parcial).
     */

    #[\OpenApi\Attributes\Put(
        path: '/api/admin/sellos/{id}',
        summary: 'Actualizar sello',
        description: 'Actualiza datos de un sello existente. Solo modifica los campos presentes (parcial).',
        tags: ['Admin Sellos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer')),
        ],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'nombre', type: 'string', maxLength: 100),
                new \OpenApi\Attributes\Property(property: 'imagen_url', type: 'string', format: 'uri', maxLength: 255),
                new \OpenApi\Attributes\Property(property: 'activo', type: 'boolean'),
            ])
        ),
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Sello actualizado', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', ref: '#/components/schemas/Sello'),
                new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Sello actualizado exitosamente'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'ID inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 404, description: 'Sello no encontrado', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminSellosUpdate(): void {}

    /**
     * DELETE /api/admin/sellos/{id}
     *
     * Baja lógica de un sello: cambia activo a false.
     * El registro no se elimina de la base de datos.
     * Para reactivar, usar PUT /api/admin/sellos/{id} con activo: true.
     */

    #[\OpenApi\Attributes\Delete(
        path: '/api/admin/sellos/{id}',
        summary: 'Desactivar sello (baja lógica)',
        description: 'Da de baja un sello de forma lógica: cambia activo a false. El registro NO se elimina de la base de datos. Consistente con el patrón de baja lógica de usuarios.',
        tags: ['Admin Sellos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer'), description: 'ID del sello'),
        ],
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Sello desactivado', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Sello desactivado exitosamente'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'El sello ya está inactivo o ID inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 403, description: 'Se requiere rol Admin', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 404, description: 'Sello no encontrado', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminSellosDelete(): void {}

    // ═══════════════════════════════════════════════════════════════════════════
    //  ADMIN — HISTORIAL GLOBAL
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/admin/historial
     *
     * Auditoría completa del sistema con filtros opcionales.
     * Permite rastrear todos los cambios realizados por administradores.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/admin/historial',
        summary: 'Historial global del sistema',
        description: 'Auditoría completa del sistema con filtros opcionales. Permite rastrear todos los cambios realizados por administradores.',
        tags: ['Admin Historial'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'usuario_id', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'integer'), description: 'Filtrar por administrador que realizó el cambio'),
            new \OpenApi\Attributes\Parameter(name: 'tabla', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'string'), description: 'Filtrar por tabla afectada (usuarios, credenciales)'),
            new \OpenApi\Attributes\Parameter(name: 'accion', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'string', enum: ['CREAR', 'EDITAR', 'BAJA', 'RESTAURAR', 'RENOVAR']), description: 'Filtrar por tipo de acción'),
            new \OpenApi\Attributes\Parameter(name: 'fecha_desde', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'string', format: 'date'), description: 'Fecha inicio (Y-m-d)'),
            new \OpenApi\Attributes\Parameter(name: 'fecha_hasta', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'string', format: 'date'), description: 'Fecha fin (Y-m-d)'),
            new \OpenApi\Attributes\Parameter(name: 'page', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'integer', default: 1), description: 'Número de página'),
            new \OpenApi\Attributes\Parameter(name: 'limit', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'integer', default: 50, maximum: 100), description: 'Resultados por página'),
        ],
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Historial del sistema', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', type: 'array', items: new \OpenApi\Attributes\Items(ref: '#/components/schemas/HistorialEntry')),
                new \OpenApi\Attributes\Property(property: 'total', type: 'integer'),
                new \OpenApi\Attributes\Property(property: 'page', type: 'integer'),
                new \OpenApi\Attributes\Property(property: 'limit', type: 'integer'),
            ])),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 403, description: 'Se requiere rol Admin', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminHistorialGlobal(): void {}

    // ═══════════════════════════════════════════════════════════════════════════
    //  ADMIN — CONVERSACIONES
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/admin/conversaciones
     *
     * Lista TODAS las conversaciones del sistema. Los usuarios solo
     * ven las suyas; el admin ve todas. Opcionalmente filtra por estado.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/admin/conversaciones',
        summary: 'Bandeja de conversaciones (admin)',
        description: 'Lista TODAS las conversaciones del sistema. Los usuarios solo ven las suyas; el admin ve todas. Opcionalmente filtra por estado.',
        tags: ['Admin Conversaciones'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'estado', in: 'query', required: false, schema: new \OpenApi\Attributes\Schema(type: 'string', enum: ['Abierta', 'Cerrada']), description: 'Filtrar por estado'),
        ],
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Lista de todas las conversaciones', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', type: 'array', items: new \OpenApi\Attributes\Items(ref: '#/components/schemas/Conversacion')),
                new \OpenApi\Attributes\Property(property: 'total', type: 'integer'),
            ])),
            new \OpenApi\Attributes\Response(response: 401, description: 'Token requerido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 403, description: 'Se requiere rol Admin', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminConversacionesList(): void {}

    /**
     * GET /api/admin/conversaciones/{id}/mensajes
     *
     * Admin puede ver los mensajes de cualquier conversación sin
     * restricción de ownership.
     */

    #[\OpenApi\Attributes\Get(
        path: '/api/admin/conversaciones/{id}/mensajes',
        summary: 'Ver mensajes de cualquier conversación (admin)',
        description: 'Admin puede ver los mensajes de cualquier conversación sin restricción de ownership.',
        tags: ['Admin Conversaciones'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer'), description: 'ID de la conversación'),
        ],
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Mensajes de la conversación', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', type: 'array', items: new \OpenApi\Attributes\Items(ref: '#/components/schemas/Mensaje')),
                new \OpenApi\Attributes\Property(property: 'total', type: 'integer'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'ID inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 404, description: 'Conversación no encontrada', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminConversacionesMensajesList(): void {}

    /**
     * POST /api/admin/conversaciones/{id}/mensajes
     *
     * Admin responde en una conversación. No hay restricción de ownership.
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/admin/conversaciones/{id}/mensajes',
        summary: 'Responder conversación (admin)',
        description: 'Admin responde en una conversación. No hay restricción de ownership.',
        tags: ['Admin Conversaciones'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer'), description: 'ID de la conversación'),
        ],
        requestBody: new \OpenApi\Attributes\RequestBody(
            required: true,
            content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'contenido', type: 'string', description: 'Respuesta del administrador', example: 'Claro, te ayudamos con eso. ¿Cuál es tu DNI?'),
            ])
        ),
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Respuesta enviada', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'data', type: 'array', items: new \OpenApi\Attributes\Items(ref: '#/components/schemas/Mensaje')),
                new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Respuesta enviada'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'Conversación cerrada o ID inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 404, description: 'Conversación no encontrada', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminConversacionesMensajesCreate(): void {}

    /**
     * POST /api/admin/conversaciones/{id}/cerrar
     *
     * Cierra una conversación. Una vez cerrada, no se pueden agregar
     * más mensajes. Lanza error si ya está cerrada.
     */

    #[\OpenApi\Attributes\Post(
        path: '/api/admin/conversaciones/{id}/cerrar',
        summary: 'Cerrar conversación (admin)',
        description: 'Cierra una conversación. Una vez cerrada, no se pueden agregar más mensajes. Lanza error si ya está cerrada.',
        tags: ['Admin Conversaciones'],
        security: [['bearerAuth' => []]],
        parameters: [
            new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'integer'), description: 'ID de la conversación'),
        ],
        responses: [
            new \OpenApi\Attributes\Response(response: 200, description: 'Conversación cerrada', content: new \OpenApi\Attributes\JsonContent(properties: [
                new \OpenApi\Attributes\Property(property: 'message', type: 'string', example: 'Conversación cerrada exitosamente'),
            ])),
            new \OpenApi\Attributes\Response(response: 400, description: 'Ya está cerrada o ID inválido', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new \OpenApi\Attributes\Response(response: 404, description: 'Conversación no encontrada', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function _adminConversacionesCerrar(): void {}
}
