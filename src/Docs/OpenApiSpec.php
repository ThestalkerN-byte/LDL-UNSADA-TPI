<?php
namespace App\Docs;

use OpenApi\Attributes as OA;

/**
 * Esta clase no se ejecuta nunca. Solo existe para alojar los atributos
 * de PHP 8 que definen la documentación de Swagger.
 */
#[OA\Info(
    version: "1.0.0",
    title: "API Credenciales Digitales",
    description: "Documentación interactiva de la API para el TPI de Laboratorio de Lenguajes. Permite probar los endpoints directamente desde el navegador."
)]
#[OA\Server(
    url: "http://localhost:8000",
    description: "Servidor local (php -S localhost:8000)"
)]
class OpenApiSpec {

    // ==========================================
    // AUTENTICACIÓN
    // ==========================================

    #[OA\Post(
        path: "/index.php?action=login",
        summary: "Iniciar sesión",
        tags: ["Autenticación"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "identificador", type: "string", example: "admin"),
                    new OA\Property(property: "password", type: "string", example: "123456")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Login exitoso"),
            new OA\Response(response: 401, description: "Credenciales inválidas")
        ]
    )]
    public function login() {}

    #[OA\Post(
        path: "/index.php?action=logout",
        summary: "Cerrar sesión",
        tags: ["Autenticación"],
        responses: [
            new OA\Response(response: 200, description: "Sesión cerrada correctamente")
        ]
    )]
    public function logout() {}

    // ==========================================
    // CREDENCIALES
    // ==========================================

    #[OA\Get(
        path: "/index.php?action=credential",
        summary: "Listar credenciales activas",
        tags: ["Credenciales"],
        responses: [
            new OA\Response(response: 200, description: "Lista de credenciales")
        ]
    )]
    public function listarCredenciales() {}

    #[OA\Get(
        path: "/index.php?action=credential&sub=alerts",
        summary: "Credenciales próximas a vencer",
        tags: ["Credenciales"],
        responses: [
            new OA\Response(response: 200, description: "Lista de alertas")
        ]
    )]
    public function alertasCredenciales() {}

    #[OA\Post(
        path: "/index.php?action=credential",
        summary: "Emitir nueva credencial",
        tags: ["Credenciales"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "id_usuario", type: "integer", example: 5),
                    new OA\Property(property: "fecha_vencimiento", type: "string", example: "2027-12-31"),
                    new OA\Property(property: "sellos", type: "array", items: new OA\Items(type: "string"), example: ["UNSADA"])
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Credencial emitida")
        ]
    )]
    public function emitirCredencial() {}

    #[OA\Post(
        path: "/index.php?action=credential&sub=renew&id={id}",
        summary: "Renovar credencial conservando historial",
        tags: ["Credenciales"],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Credencial renovada")
        ]
    )]
    public function renovarCredencial() {}

    // ==========================================
    // USUARIOS
    // ==========================================

    #[OA\Get(
        path: "/index.php?action=user",
        summary: "Listar usuarios del sistema",
        tags: ["Usuarios"],
        responses: [
            new OA\Response(response: 200, description: "Lista de usuarios")
        ]
    )]
    public function listarUsuarios() {}

    // ==========================================
    // MENSAJES Y TICKETS
    // ==========================================

    #[OA\Get(
        path: "/index.php?action=message",
        summary: "Ver todos los mensajes",
        tags: ["Mensajes"],
        responses: [
            new OA\Response(response: 200, description: "Lista de mensajes")
        ]
    )]
    public function listarMensajes() {}

    #[OA\Put(
        path: "/index.php?action=message&id={id}",
        summary: "Responder a un mensaje (admin)",
        tags: ["Mensajes"],
        parameters: [
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "respuesta", type: "string", example: "Estimado, su credencial ya se encuentra activa.")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Mensaje respondido")
        ]
    )]
    public function responderMensaje() {}

    // ==========================================
    // HISTORIAL DE AUDITORÍA
    // ==========================================

    #[OA\Get(
        path: "/index.php?action=history",
        summary: "Ver historial de acciones de administradores",
        tags: ["Auditoría"],
        responses: [
            new OA\Response(response: 200, description: "Historial completo")
        ]
    )]
    public function verHistorial() {}
}
