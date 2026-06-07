<?php

declare(strict_types=1);

namespace ICB\Entity;

/*
 * =========================================================================
 * ENTIDAD: RecuperacionToken
 * =========================================================================
 *
 * Token de recuperación de contraseña de un solo uso.
 * Cada token tiene un tiempo de expiración y se marca como usado
 * después de confirmar el cambio de contraseña.
 *
 * FLUJO:
 *   1. Usuario solicita recuperación → se genera token de 64 hex chars
 *   2. Token se envía al email del usuario (o se devuelve en respuesta para testing)
 *   3. Usuario envía token + nueva contraseña → se valida y se cambia
 *   4. Token se marca como usado (no se puede reutilizar)
 *
 * SEGURIDAD:
 *   - Tokens expiran a los 60 minutos
 *   - Un solo uso (usado = true evita reutilización)
 *   - Se genera con random_bytes() criptográficamente seguro
 *   - Se almacena hasheado en la DB (password_hash) para que
 *     un leak de la DB no permita recuperar tokens activos
 * =========================================================================
 */

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'recuperacion_tokens')]
class RecuperacionToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id_token', type: 'integer')]
    private int $idToken;

    // Usuario que solicitó la recuperación
    #[ORM\ManyToOne(targetEntity: Usuario::class)]
    #[ORM\JoinColumn(name: 'id_usuario', referencedColumnName: 'id_usuario', nullable: false, onDelete: 'CASCADE')]
    private Usuario $usuario;

    // Token hasheado (guardamos hash, no el token plano)
    #[ORM\Column(name: 'token_hash', type: 'string', length: 255)]
    private string $tokenHash;

    // Fecha de expiración del token
    #[ORM\Column(name: 'expires_at', type: 'datetime')]
    private \DateTimeInterface $expiresAt;

    // Indica si el token ya fue usado
    #[ORM\Column(type: 'boolean')]
    private bool $usado = false;

    // Fecha de creación
    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getIdToken(): int
    {
        return $this->idToken;
    }

    public function getUsuario(): Usuario
    {
        return $this->usuario;
    }

    public function setUsuario(Usuario $usuario): self
    {
        $this->usuario = $usuario;
        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;
        return $this;
    }

    public function getExpiresAt(): \DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeInterface $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function isUsado(): bool
    {
        return $this->usado;
    }

    public function setUsado(bool $usado): self
    {
        $this->usado = $usado;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    /*
     * Verifica si el token plano (sin hashear) coincide con el hash almacenado.
     */
    public function verificarToken(string $tokenPlano): bool
    {
        return password_verify($tokenPlano, $this->tokenHash);
    }

    /*
     * Verifica si el token aún no ha expirado.
     */
    public function estaExpirado(): bool
    {
        return new \DateTime() > $this->expiresAt;
    }
}
