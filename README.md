# ICB — Credenciales Digitales

API REST para el sistema de Credenciales Digitales de la Iglesia Cristiana Bíblica.

**Stack**: PHP 8.4, Doctrine ORM 3, JWT (HS256), MySQL/MariaDB

---

## Despliegue a Producción

Guía paso a paso para desplegar la API en un servidor Apache con PHP 8.4+.

### Requisitos del servidor

- **PHP 8.4+** con extensiones: `pdo_mysql`, `mbstring`, `json`, `bcmath`
- **MySQL 8+ / MariaDB 10+**
- **Composer 2**
- **Apache** con `mod_rewrite` habilitado
- **SSH** para acceso remoto (Hostinger, DigitalOcean, etc.)

### Estructura en producción

A diferencia del entorno local (donde el Document Root apunta a `public/`), en producción **los archivos van en la raíz del proyecto**:

```
📁 /tu-sitio/icb/
├── .env              # Credenciales reales (NUNCA subir a git)
├── .htaccess         # Front Controller Pattern + bloqueo de archivos sensibles
├── index.php         # Front Controller (Apache rewrite vía .htaccess)
├── swagger.php       # Documentación interactiva con Basic Auth
├── openapi.json      # Especificación OpenAPI
├── src/              # Código fuente
├── vendor/           # Dependencias (Composer)
├── config/           # Configuración de Doctrine
├── bin/              # Scripts de consola (migrations, seed)
└── images/sellos/    # Uploads de sellos institucionales
```

> **Importante**: No existe carpeta `public/` en producción. Apache sirve `index.php` directamente desde la raíz.

### Pasos de instalación

```bash
# 1. Acceder al servidor vía SSH
ssh usuario@tuservidor.com -p 65002

# 2. Navegar al directorio del proyecto
cd ~/domains/tusitio.com/public_html/icb

# 3. Instalar dependencias (SIN dev, con autoloader optimizado)
composer install --no-dev --optimize-autoloader

# 4. Configurar variables de entorno
cp .env.example .env
nano .env
# Editar al menos: DB_NAME, DB_USER, DB_PASS, JWT_SECRET, APP_ENV=prod
```

**Variables de entorno obligatorias en producción:**

| Variable | Ejemplo | Descripción |
|----------|---------|-------------|
| `DB_HOST` | `127.0.0.1` | Host de la base de datos |
| `DB_NAME` | `credenciales_digitales` | Nombre de la base de datos |
| `DB_USER` | `u123456_miuser` | Usuario con prefijo del hosting |
| `DB_PASS` | `••••••••` | Contraseña de la base de datos |
| `JWT_SECRET` | `••••••••` | Clave secreta para firmar tokens JWT |
| `APP_ENV` | `prod` | `prod` oculta información sensible |
| `CORS_ORIGIN` | `https://misitio.com` | Origen permitido (vacío = mismo origen) |
| `SWAGGER_USER` | `miuser` | Usuario para acceder a Swagger UI |
| `SWAGGER_PASS` | `••••••••` | Contraseña para Swagger UI |

```bash
# 5. Ejecutar migraciones de base de datos
php bin/doctrine migrations:migrate

# 6. (Opcional) Sembrar usuario inicial
# Si es la primera vez, crear un usuario administrador:
php bin/seed_icbsw.php

# 7. Generar especificación OpenAPI
./vendor/bin/openapi src/Docs -o openapi.json

# 8. Establecer permisos correctos
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
chmod 755 images/sellos/
chmod 600 .env
```

### Verificación post-despliegue

```bash
# Health check
curl https://tusitio.com/icb/api/health

# Login
curl -X POST https://tusitio.com/icb/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"usuario":"icbSw","password":"Unsa@_26"}'

# Swagger UI (requiere autenticación)
# Abrir en el navegador: https://tusitio.com/icb/swagger.php
```

### Seguridad — CheckList

- [ ] `APP_ENV=prod` — No muestra errores detallados ni tokens de recuperación
- [ ] `APP_DEBUG` comentado o `false` — Errores internos se loggean con correlation ID
- [ ] `JWT_SECRET` cambiado — NO usar el valor por defecto de desarrollo
- [ ] `CORS_ORIGIN` configurado con el dominio exacto (vacío = mismo origen, seguro)
- [ ] `TRUSTED_PROXIES` configurado si hay CDN/proxy adelante
- [ ] `.env` con permisos `600` — Solo el usuario del servidor puede leerlo
- [ ] `.htaccess` presente — Bloquea `.env`, `composer.*`, `openapi.json`
- [ ] `openapi.json` con permisos `644` — Solo accesible vía Swagger (embebido en PHP)
- [ ] Swagger UI protegida con Basic Auth (credenciales en `.env`)
- [ ] `vendor/` no expuesto — Apache no lista directorios por defecto
- [ ] Usuario `admin` por defecto eliminado — Usar el seed para crear uno nuevo

### Notas para Hostinger

- **Acceso SSH**: `ssh u216166114@147.93.39.13 -p 65002`
- **Llave SSH**: Usar `~/.ssh/opencode_hostinger`
- **Ruta del proyecto**: `~/domains/tutallerenlinea.com/public_html/icb/`
- **Usuario DB**: Prefijado con `u216166114_` (ej: `u216166114_credigitales`)
- **PHP**: Versión 8.4.19, configurable desde el panel de Hostinger
- **Composer**: Disponible en `/usr/local/bin/composer`
- **error_log**: Apache loggea errores PHP según configuración del hosting
- **No usar `public/`**: En Hostinger la raíz del proyecto es `public_html/`, y el subdirectorio `icb/` contiene los archivos directamente

---

## Requisitos

- PHP 8.1+
- MySQL 8+ / MariaDB 10+
- Composer 2
- Extensiones PHP: `pdo_mysql`, `mbstring`, `json`, `bcmath`

## Instalación

```bash
# 1. Clonar el proyecto
cd /var/www/html/icb

# 2. Instalar dependencias
composer install

# 3. Copiar y configurar variables de entorno
cp .env.example .env
# Editar .env con los datos de tu base de datos

# 4. Crear la base de datos
mysql -u root -e "CREATE DATABASE credenciales_digitales"

# 5. Ejecutar migrations
php bin/doctrine migrations:migrate

# 6. Generar especificación OpenAPI
./vendor/bin/openapi src/Docs -o public/openapi.json
```

## Usuario por defecto

| Usuario | Contraseña | Roles |
|---------|-----------|-------|
| `admin` | `admin123` | Admin, Usuario |

## Iniciar servidor de desarrollo

```bash
php -S localhost:8000 -t public
```

> **Atención**: El servidor built-in de PHP **no sirve archivos estáticos automáticamente** (como las imágenes de sellos). En producción, Apache con `.htaccess` las sirve correctamente.

---

## Endpoints

### 1. Health Check

```bash
# Verificar que el servidor y la DB respondan
curl http://localhost:8000/api/health
```

Respuesta:
```json
{ "status": "ok", "database": "connected", "timestamp": "2026-06-03T00:00:00+00:00" }
```

### 2. Autenticación

```bash
# Login con usuario
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"usuario": "admin", "password": "admin123"}'

# Login con DNI (alternativo)
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"dni": "12345678", "password": "admin123"}'
```

Respuesta:
```json
{
  "data": {
    "access_token": "eyJ0eXAiOiJKV1Qi...",
    "refresh_token": "a1b2c3d4e5f6...",
    "expires_in": 900,
    "usuario": { ... }
  },
  "message": "Inicio de sesión exitoso"
}
```

Guardar tokens para usar en los siguientes ejemplos:

```bash
TOKEN="eyJ0eXAiOiJKV1Qi..."
REFRESH="a1b2c3d4e5f6..."
```

```bash
# Renovar tokens (con rotación: el viejo se invalida)
curl -X POST http://localhost:8000/api/auth/refresh \
  -H "Content-Type: application/json" \
  -d "{\"refresh_token\": \"$REFRESH\"}"

# Obtener mi perfil
curl http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer $TOKEN"

# Mi credencial activa
curl http://localhost:8000/api/auth/mi-credencial \
  -H "Authorization: Bearer $TOKEN"
```

### 3. Recuperación de contraseña

```bash
# Solicitar recuperación (devuelve token en desarrollo)
curl -X POST http://localhost:8000/api/auth/recuperar-solicitar \
  -H "Content-Type: application/json" \
  -d '{"email": "admin@icb.com"}'

# Confirmar recuperación con el token recibido
curl -X POST http://localhost:8000/api/auth/recuperar-confirmar \
  -H "Content-Type: application/json" \
  -d '{"token": "TOKEN_RECIBIDO", "password": "nuevaPass123"}'
```

### 4. Sellos públicos

```bash
# Listar sellos activos (sin autenticación)
curl http://localhost:8000/api/sellos
```

### 5. Conversaciones (usuario)

```bash
# Listar mis conversaciones
curl http://localhost:8000/api/conversaciones \
  -H "Authorization: Bearer $TOKEN"

# Crear conversación con primer mensaje
curl -X POST http://localhost:8000/api/conversaciones \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"contenido": "Hola, necesito información sobre mi credencial"}'

# Ver mensajes de una conversación
curl http://localhost:8000/api/conversaciones/1/mensajes \
  -H "Authorization: Bearer $TOKEN"

# Enviar mensaje en conversación
curl -X POST http://localhost:8000/api/conversaciones/1/mensajes \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"contenido": "Gracias por la ayuda"}'
```

### 6. Administración de usuarios

```bash
# Listar usuarios
curl http://localhost:8000/api/admin/usuarios \
  -H "Authorization: Bearer $TOKEN"

# Listar con filtros
curl "http://localhost:8000/api/admin/usuarios?busqueda=Pérez&estado=Activo" \
  -H "Authorization: Bearer $TOKEN"

# Obtener usuario por ID
curl http://localhost:8000/api/admin/usuarios/1 \
  -H "Authorization: Bearer $TOKEN"

# Crear usuario
curl -X POST http://localhost:8000/api/admin/usuarios \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "dni": "87654321",
    "usuario": "jperez",
    "password": "pass123",
    "nombre": "Juan",
    "apellido": "Pérez",
    "email": "jperez@icb.com",
    "roles": ["Usuario"]
  }'

# Actualizar usuario (parcial)
curl -X PUT http://localhost:8000/api/admin/usuarios/2 \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"telefono": "1122334455", "funcion": "Diácono"}'

# Baja lógica (DELETE)
curl -X DELETE http://localhost:8000/api/admin/usuarios/2 \
  -H "Authorization: Bearer $TOKEN"

# Restaurar usuario
curl -X POST http://localhost:8000/api/admin/usuarios/2/restaurar \
  -H "Authorization: Bearer $TOKEN"

# Historial de cambios de un usuario
curl http://localhost:8000/api/admin/usuarios/2/historial \
  -H "Authorization: Bearer $TOKEN"
```

### 7. Administración de credenciales

```bash
# Listar credenciales
curl http://localhost:8000/api/admin/credenciales \
  -H "Authorization: Bearer $TOKEN"

# Filtrar por usuario
curl "http://localhost:8000/api/admin/credenciales?usuario_id=1" \
  -H "Authorization: Bearer $TOKEN"

# Emitir credencial
curl -X POST http://localhost:8000/api/admin/credenciales \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "id_usuario": 1,
    "fecha_vencimiento": "2027-06-03"
  }'

# Renovar credencial (la anterior se desactiva)
curl -X POST http://localhost:8000/api/admin/credenciales/1/renovar \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"fecha_vencimiento": "2028-06-03"}'
```

### 8. Administración de sellos

```bash
# Listar todos los sellos (activos e inactivos)
curl http://localhost:8000/api/admin/sellos \
  -H "Authorization: Bearer $TOKEN"

# Obtener sello por ID
curl http://localhost:8000/api/admin/sellos/1 \
  -H "Authorization: Bearer $TOKEN"

# Crear sello (con URL de imagen)
curl -X POST http://localhost:8000/api/admin/sellos \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "nombre": "Sello Oficial ICB",
    "imagen_url": "/images/sellos/ICB-ABC123.png",
    "activo": true
  }'

# Subir sello con imagen (multipart)
curl -X POST http://localhost:8000/api/admin/sellos/upload \
  -H "Authorization: Bearer $TOKEN" \
  -F "nombre=Sello Oficial" \
  -F "imagen=@/ruta/a/mi-imagen.png" \
  -F "activo=true"

# Actualizar sello
curl -X PUT http://localhost:8000/api/admin/sellos/1 \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"nombre": "Nuevo nombre", "activo": false}'

# Desactivar sello (baja lógica)
curl -X DELETE http://localhost:8000/api/admin/sellos/1 \
  -H "Authorization: Bearer $TOKEN"
```

### 9. Bandeja de conversaciones (admin)

```bash
# Listar todas las conversaciones
curl http://localhost:8000/api/admin/conversaciones \
  -H "Authorization: Bearer $TOKEN"

# Filtrar por estado
curl "http://localhost:8000/api/admin/conversaciones?estado=Abierta" \
  -H "Authorization: Bearer $TOKEN"

# Ver mensajes de cualquier conversación
curl http://localhost:8000/api/admin/conversaciones/1/mensajes \
  -H "Authorization: Bearer $TOKEN"

# Responder conversación
curl -X POST http://localhost:8000/api/admin/conversaciones/1/mensajes \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"contenido": "Claro, te ayudamos con eso"}'

# Cerrar conversación
curl -X POST http://localhost:8000/api/admin/conversaciones/1/cerrar \
  -H "Authorization: Bearer $TOKEN"
```

### 10. Auditoría (historial)

```bash
# Historial global
curl http://localhost:8000/api/admin/historial \
  -H "Authorization: Bearer $TOKEN"

# Con filtros
curl "http://localhost:8000/api/admin/historial?tabla=usuarios&accion=CREAR&page=1&limit=20" \
  -H "Authorization: Bearer $TOKEN"
```

---

## Rate Limiting

Los endpoints de autenticación tienen límites de tasa para mitigar ataques de fuerza bruta:

| Endpoint | Límite | Ventana |
|----------|--------|---------|
| `POST /api/auth/login` | 5 intentos | 1 minuto |
| `POST /api/auth/refresh` | 10 intentos | 1 minuto |
| `POST /api/auth/recuperar-solicitar` | 3 solicitudes | 5 minutos |
| `POST /api/auth/recuperar-confirmar` | 5 intentos | 5 minutos |

Cuando se excede el límite, la API responde con HTTP 429 y un mensaje indicando el tiempo de espera.

---

## Autenticación

La API usa **JWT stateless** con:

- **Access token** (JWT HS256): válido 15 minutos
- **Refresh token** (64 hex chars): válido 30 días, con **rotación** (cada uso invalida el anterior)

Los tokens se envían en el header `Authorization: Bearer <token>`.

---

## Formato de respuestas

| Situación | Formato |
|-----------|---------|
| Éxito con datos | `{ "data": {...}, "message": "..." }` |
| Error | `{ "error": "...", "code": 400 }` |
| Lista | `{ "data": [...], "total": N }` |
| Sin contenido | `{ "message": "..." }` |
| Rate limit | `{ "error": "...", "code": 429, "retry_after": N }` |

---

## Documentación OpenAPI

La especificación OpenAPI 3.1 completa se genera desde atributos PHP 8:

```bash
./vendor/bin/openapi src/Docs -o public/openapi.json
```

El archivo generado `public/openapi.json` se puede importar en:
- Swagger UI
- Postman
- Insomnia
- Stoplight

---

## Estructura del proyecto

```
├── bin/                  # Scripts CLI (doctrine)
├── config/               # Configuración (Doctrine, .env)
├── migrations/           # Migraciones de base de datos
├── public/
│   ├── .htaccess         # Rewrite rules para Apache
│   ├── index.php         # Front controller (punto de entrada)
│   ├── openapi.json      # Especificación OpenAPI generada
│   └── images/sellos/    # Imágenes de sellos subidas
├── src/
│   ├── Controller/       # Controladores (Auth, Admin, Conversacion, Sello)
│   ├── Docs/             # Atributos OpenAPI para generación de spec
│   ├── Entity/           # Entidades Doctrine (Usuario, Credencial, Sello, etc.)
│   ├── Exception/        # Excepciones HTTP custom (Auth, Validation, NotFound, Forbidden)
│   ├── Middleware/        # AuthMiddleware (JWT + roles)
│   ├── RateLimiting/     # Rate limiter sliding window basado en archivos
│   ├── Repository/       # Repositorios Doctrine
│   ├── Request/          # Wrapper de request HTTP
│   ├── Router/           # Router REST propio
│   ├── Service/          # Lógica de negocio (Auth, Usuario, Credencial, etc.)
│   └── Validation/       # Validación de datos (ValidationHelper)
└── tests/
    ├── bootstrap.php     # Setup de tests
    └── Unit/             # Tests unitarios
```

---

## Scripts útiles

```bash
# Ejecutar tests
php vendor/bin/phpunit

# Generar OpenAPI spec
./vendor/bin/openapi src/Docs -o public/openapi.json

# Ejecutar migrations
php bin/doctrine migrations:migrate

# Generar migration desde entities
php bin/doctrine migrations:diff
```
