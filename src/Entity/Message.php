<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad Doctrine que representa un mensaje entre usuario y administración.
 */
#[ORM\Entity(repositoryClass: "App\Repository\MessageRepository")]
class Message {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\Column(type: "string", length: 255)]
    private string $contenido;

    #[ORM\Column(type: "datetime")]
    private \DateTimeInterface $fecha;

    #[ORM\Column(type: "string", length: 50)]
    private string $remitente; // puede ser 'usuario' o 'admin'

    #[ORM\Column(type: "string", length: 50)]
    private string $destinatario;

    // Getters y setters
    public function getId(): int { return $this->id; }
    public function getContenido(): string { return $this->contenido; }
    public function setContenido(string $contenido): void { $this->contenido = $contenido; }
    public function getFecha(): \DateTimeInterface { return $this->fecha; }
    public function setFecha(\DateTimeInterface $fecha): void { $this->fecha = $fecha; }
    public function getRemitente(): string { return $this->remitente; }
    public function setRemitente(string $remitente): void { $this->remitente = $remitente; }
    public function getDestinatario(): string { return $this->destinatario; }
    public function setDestinatario(string $destinatario): void { $this->destinatario = $destinatario; }
}