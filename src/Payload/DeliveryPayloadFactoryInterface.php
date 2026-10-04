<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Payload;

use Storkplus\SyliusBostaShippingPlugin\Address\AddressResolutionException;
use Storkplus\SyliusBostaShippingPlugin\BostaConfiguration;
use Storkplus\SyliusBostaShippingPlugin\Entity\BostaDelivery;

interface DeliveryPayloadFactoryInterface
{
    /**
     * @return array<string, mixed> the POST /deliveries body
     *
     * @throws AddressResolutionException
     * @throws \InvalidArgumentException when the order cannot be shipped with Bosta
     */
    public function create(BostaDelivery $delivery, BostaConfiguration $configuration): array;

    /** The uniqueBusinessReference (and businessReference) of the delivery's current attempt. */
    public function reference(BostaDelivery $delivery, BostaConfiguration $configuration): string;
}
