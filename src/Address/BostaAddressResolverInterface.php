<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Address;

use Sylius\Component\Addressing\Model\AddressInterface;

interface BostaAddressResolverInterface
{
    /**
     * Maps a Sylius address onto Bosta's address format (city, cityId, districtId or
     * districtName, firstLine, secondLine).
     *
     * @return array<string, string>
     *
     * @throws AddressResolutionException when no Bosta city can be matched
     */
    public function resolve(AddressInterface $address): array;

    /**
     * Bosta city name for an address, or null when it cannot be matched. Used by the
     * fee estimate, which must never throw.
     */
    public function resolveCityName(AddressInterface $address): ?string;
}
