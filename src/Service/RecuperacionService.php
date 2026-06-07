<?php

declare(strict_types=1);

namespace ICB\Service;

/*
 * =========================================================================
 * SERVICIO: RecuperacionService
 * =========================================================================
 *
 * Servicio de recuperación de contraseña mediante tokens de un solo uso.
 *
 * FLUJO:
 *   1. Usuario envía su email → generamos token → lo devolvemos en respuesta
 *   2. Usuario envía token + nueva password → validamos token → cambiamos password
 *
 * SEGURIDAD:
 *   - Token de 64 caracteres hex generado con random_bytes() (criptográficamente seguro)
 *   - Se almacena hasheado con password_hash() (nunca el token plano en DB)
 *   - Expira a los 60 minutos
 *   - Un solo uso (se marca como usado al confirmar)
 * =========================================================================
 */

use Doctrine\ORM\EntityManagerInterface;
use ICB\Entity\RecuperacionToken;
use ICB\Entity\Usuario;
use ICB\Exception\ValidationException;
use ICB\Validation\ValidationHelper;

class RecuperacionService
{
    /** Minutos hasta que expira un token de recuperación */
    private const TOKEN_EXPIRACION_MINUTOS = 60;

    /** Longitud del token en bytes (64 hex chars = 32 bytes) */
    private const TOKEN_BYTES = 32;

    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /*
     * =====================================================================
     * solicitar — Genera un token de recuperación para el email indicado
     * =====================================================================
     *
     * Busca al usuario por email, genera un token criptográficamente seguro,
     * lo hashea y lo persiste.
     *
     * IMPORTANTE: En producción, el token se envía por email al usuario.
     * En desarrollo/testing, se devuelve en la respuesta para facilitar
     * las pruebas desde el frontend.
     *
     * @param string $email Email del usuario
     * @return string       Token plano (para devolver al frontend en desarrollo)
     *
     * @throws ValidationException si el email no existe o está vacío
     * =====================================================================
     */
    public function solicitar(string $email): string
    {
        ValidationHelper::acumular([
            ValidationHelper::requerido('email', $email),
            ValidationHelper::email('email', $email),
        ]);

        $email = ValidationHelper::sanitizar($email);

        // Buscar usuario por email
        $usuario = $this->em->getRepository(Usuario::class)->findOneBy(['email' => $email]);

        if (!$usuario) {
            // Por seguridad, no revelamos si el email existe o no
            throw new ValidationException(
                'Si el email está registrado, recibirás un enlace de recuperación'
            );
        }

        // Invalidar tokens anteriores no usados del mismo usuario
        $tokensAnteriores = $this->em->getRepository(RecuperacionToken::class)->findBy([
            'usuario' => $usuario,
            'usado'   => false,
        ]);
        foreach ($tokensAnteriores as $tokenAnterior) {
            $this->em->remove($tokenAnterior);
        }

        // Generar token criptográficamente seguro
        $tokenPlano = bin2hex(random_bytes(self::TOKEN_BYTES));

        // Crear y persistir nuevo token
        $expiracion = new \DateTime('+' . self::TOKEN_EXPIRACION_MINUTOS . ' minutes');

        $token = new RecuperacionToken();
        $token->setUsuario($usuario);
        $token->setTokenHash(password_hash($tokenPlano, PASSWORD_BCRYPT));
        $token->setExpiresAt($expiracion);

        $this->em->persist($token);
        $this->em->flush();

        // En desarrollo, devolvemos el token plano para testing
        return $tokenPlano;
    }

    /*
     * =====================================================================
     * confirmar — Valida el token y cambia la contraseña
     * =====================================================================
     *
     * Busca un token no usado y no expirado que tenga el hash dado,
     * verifica el token plano contra el hash, cambia la password y
     * marca el token como usado.
     *
     * @param string $token    Token plano recibido del usuario
     * @param string $password Nueva contraseña
     *
     * @throws ValidationException si el token es inválido, expiró o ya fue usado
     * =====================================================================
     */
    public function confirmar(string $token, string $password): void
    {
        ValidationHelper::acumular([
            ValidationHelper::requerido('token', $token),
            ValidationHelper::requerido('password', $password),
            ValidationHelper::password('password', $password),
        ]);

        // Buscar tokens activos (este es un approach limitado: debemos buscar
        // todos los tokens activos y verificar el hash uno por uno)
        $tokensActivos = $this->em->getRepository(RecuperacionToken::class)->findBy([
            'usado' => false,
        ]);

        $tokenValido = null;
        foreach ($tokensActivos as $t) {
            if ($t->verificarToken($token)) {
                $tokenValido = $t;
                break;
            }
        }

        if (!$tokenValido) {
            throw new ValidationException('Token inválido o expirado');
        }

        if ($tokenValido->estaExpirado()) {
            $tokenValido->setUsado(true);
            $this->em->flush();
            throw new ValidationException('El token ha expirado. Solicita uno nuevo');
        }

        // Cambiar la contraseña del usuario
        $usuario = $tokenValido->getUsuario();
        $usuario->setPasswordHash(password_hash($password, PASSWORD_BCRYPT));

        // Invalidar todos los tokens de sesión del usuario
        $usuario->setRefreshToken(null);
        $usuario->setRefreshTokenExpira(null);
        $usuario->setResetToken(null);
        $usuario->setResetTokenExpira(null);

        // Marcar token como usado
        $tokenValido->setUsado(true);

        $this->em->flush();
    }
}
