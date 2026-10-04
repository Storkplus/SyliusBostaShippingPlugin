<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Manager;

use Storkplus\SyliusBostaShippingPlugin\Api\BostaApiException;
use Storkplus\SyliusBostaShippingPlugin\Entity\BostaDelivery;
use Sylius\Component\Core\Model\ShipmentInterface;

interface BostaDeliveryManagerInterface
{
    public function supports(ShipmentInterface $shipment): bool;

    public function get(ShipmentInterface $shipment): ?BostaDelivery;

    /** Creates the Bosta delivery, or retries a failed attempt. No-op once created. */
    public function create(ShipmentInterface $shipment): BostaDelivery;

    /**
     * Cancels the current Bosta delivery (while still cancellable) and creates a new one.
     *
     * @throws RecreationNotAllowedException when the parcel already left or Bosta refuses to cancel
     */
    public function recreate(ShipmentInterface $shipment): BostaDelivery;

    /** @throws BostaApiException */
    public function refresh(BostaDelivery $delivery): void;
}
