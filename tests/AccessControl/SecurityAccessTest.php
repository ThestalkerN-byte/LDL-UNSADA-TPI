<?php

declare(strict_types=1);

namespace App\Tests\AccessControl;

use App\Entity\History;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Pruebas de integración HTTP sobre los endpoints de control de acceso:
 *
 *   POST /index.php?action=login
 *   GET  /index.php?action=user
 *   GET  /index.php?action=credential&id=...
 *
 * La infraestructura (servidor embebido, BD, .env, rate limit)
 * es heredada de IntegrationTestCase.
 *
 * Diferencia respecto a la versión original:
 *   - Ya no apunta a Render (URL remota). Usa el servidor PHP embebido
 *     que levanta IntegrationTestCase, igual que Authentication y UserSearch.
 *   - Se eliminan todos los helpers cURL (login, getStatusCode,
 *     asegurarUsuarioComunDeTesting): se reemplazan por los métodos
 *     heredados postJson(), getJson() y crearUsuarioDePrueba().
 *   - El tearDown especial que limpiaba History antes de borrar el User
 *     se conserva sobreescribiendo tearDown(), ya que IntegrationTestCase
 *     base no conoce esa FK y fallaría con un error de constraint.
 *
 * Mapeo con la planilla QA (Seguridad):
 *   SEGURIDAD_001 → testUsuarioComunEnPanelAdminRetorna403
 *   SEGURIDAD_002 → testAccesoCredencialSinAutenticarRetorna401
 *
 * Requisitos cubiertos: RNF02 (Control de Acceso, Validación de Roles).
 */
final class SecurityAccessTest extends IntegrationTestCase
{
    // =====================================================================
    // Limpieza extendida: History → User
    // =====================================================================

    /**
     * Sobreescribe el tearDown de IntegrationTestCase para limpiar primero
     * los registros de History que referencian al usuario QA antes de
     * intentar borrarlo. Sin esto, la FK id_admin de la tabla historial
     * lanzaría un error de integridad referencial en Aiven.
     *
     * Después de la limpieza de History delega al padre para que haga
     * el borrado estándar del User y vacíe $createdUserIds.
     */
    protected function tearDown(): void
    {
        // Accedemos al EM a través de la propiedad estática del padre.
        // Usamos reflection para no romper la encapsulación (es privada).
        $em = $this->getEntityManager();

        if ($em !== null) {
            foreach ($this->createdUserIds as $id) {
                $historiales = $em->getRepository(History::class)
                    ->findBy(['admin' => $id]);

                foreach ($historiales as $historial) {
                    $em->remove($historial);
                }
            }

            if ($this->createdUserIds !== []) {
                $em->flush();
            }
        }

        // El padre borra los Users y vacía $createdUserIds
        parent::tearDown();
    }

    // =====================================================================
    // SEGURIDAD_002 - Protección de endpoints contra usuarios anónimos
    // =====================================================================

    #[Test]
    #[TestDox('SEGURIDAD_002 - Acceso a credenciales sin JWT retorna 401 Unauthorized')]
    public function testAccesoCredencialSinAutenticarRetorna401(): void
    {
        [$httpCode] = $this->getJson('credential', ['id' => 1]);

        $this->assertSame(
            401,
            $httpCode,
            'Fallo (SEGURIDAD_002): se permitió acceso a la credencial sin estar autenticado.'
        );
    }

    // =====================================================================
    // SEGURIDAD_001 - Autorización y Roles (Escalamiento de privilegios)
    // =====================================================================

    #[Test]
    #[TestDox('SEGURIDAD_001 - Usuario con rol común accediendo a endpoints de Admin retorna 403 Forbidden')]
    public function testUsuarioComunEnPanelAdminRetorna403(): void
    {
        // Crea un usuario rol "user" directamente en la BD (sin pasar por la API).
        // Se registra en $createdUserIds para limpieza automática en tearDown.
        $usuarioComun = $this->crearUsuarioDePrueba('ClaveSegura123!', rol: 'user');
        $token        = $this->login($usuarioComun->getUsuario(), 'ClaveSegura123!');

        [$httpCode] = $this->getJson('user', [], $token);

        $this->assertSame(
            403,
            $httpCode,
            'Fallo Crítico (SEGURIDAD_001): un usuario común accedió al panel admin.'
        );
    }

    #[Test]
    #[TestDox('Acceso legítimo de un Administrador al panel de usuarios retorna 200 OK')]
    public function testAdminConPermisosAccedeCorrectamente(): void
    {
        $admin = $this->crearUsuarioDePrueba('ClaveSegura123!', rol: 'admin');
        $token = $this->login($admin->getUsuario(), 'ClaveSegura123!');

        [$httpCode] = $this->getJson('user', [], $token);

        $this->assertSame(
            200,
            $httpCode,
            'El admin con credenciales válidas no pudo acceder al panel. Código obtenido: ' . $httpCode
        );
    }
}
