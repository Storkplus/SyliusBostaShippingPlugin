<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Entity;

use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * The Bosta side of a Sylius shipment: one row per shipment, kept across retries
 * and recreations so the admin always sees the latest attempt and its error.
 */
class BostaDelivery
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CREATED = 'created';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TERMINATED = 'terminated';

    protected ?int $id = null;

    protected ShipmentInterface $shipment;

    protected string $status = self::STATUS_PENDING;

    protected ?string $deliveryId = null;

    protected ?string $trackingNumber = null;

    /** Bumped on every recreation so Bosta accepts a new uniqueBusinessReference. */
    protected int $sequence = 1;

    protected ?int $stateCode = null;

    protected ?string $stateLabel = null;

    protected ?string $exceptionReason = null;

    protected ?string $lastError = null;

    protected int $attempts = 0;

    protected ?\DateTimeImmutable $lastAttemptAt = null;

    protected ?\DateTimeImmutable $lastWebhookAt = null;

    protected \DateTimeImmutable $createdAt;

    protected \DateTimeImmutable $updatedAt;

    public function __construct(ShipmentInterface $shipment)
    {
        $this->shipment = $shipment;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShipment(): ShipmentInterface
    {
        return $this->shipment;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isCreated(): bool
    {
        return self::STATUS_CREATED === $this->status;
    }

    public function isFailed(): bool
    {
        return self::STATUS_FAILED === $this->status;
    }

    public function getDeliveryId(): ?string
    {
        return $this->deliveryId;
    }

    public function getTrackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getStateCode(): ?int
    {
        return $this->stateCode;
    }

    public function getStateLabel(): ?string
    {
        return $this->stateLabel;
    }

    public function getExceptionReason(): ?string
    {
        return $this->exceptionReason;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getLastWebhookAt(): ?\DateTimeImmutable
    {
        return $this->lastWebhookAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markAttempt(): void
    {
        ++$this->attempts;
        $this->lastAttemptAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function markCreated(string $deliveryId, string $trackingNumber, ?int $stateCode, ?string $stateLabel): void
    {
        $this->status = self::STATUS_CREATED;
        $this->deliveryId = $deliveryId;
        $this->trackingNumber = $trackingNumber;
        $this->lastError = null;
        $this->exceptionReason = null;
        $this->updateState($stateCode, $stateLabel);
    }

    public function markFailed(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->lastError = mb_substr($error, 0, 2000);
        $this->touch();
    }

    /** Starts a new delivery under a new reference; the old one is kept only in Bosta. */
    public function startRecreation(): void
    {
        ++$this->sequence;
        $this->status = self::STATUS_PENDING;
        $this->deliveryId = null;
        $this->trackingNumber = null;
        $this->stateCode = null;
        $this->stateLabel = null;
        $this->exceptionReason = null;
        $this->lastError = null;
        $this->touch();
    }

    public function markTerminated(): void
    {
        $this->status = self::STATUS_TERMINATED;
        $this->touch();
    }

    public function updateState(?int $stateCode, ?string $stateLabel, ?string $exceptionReason = null): void
    {
        $this->stateCode = $stateCode;
        $this->stateLabel = $stateLabel;
        if (null !== $exceptionReason) {
            $this->exceptionReason = mb_substr($exceptionReason, 0, 500);
        }
        $this->touch();
    }

    public function markWebhookReceived(): void
    {
        $this->lastWebhookAt = new \DateTimeImmutable();
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
