<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Checker;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusBostaShippingPlugin\BostaConfiguration;
use Sylius\Component\Core\Exception\MissingChannelConfigurationException;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Checker\Eligibility\ShippingMethodEligibilityCheckerInterface;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;
use Sylius\Component\Shipping\Model\ShippingSubjectInterface;

/**
 * Hides a Bosta method that cannot price the shipment.
 *
 * In estimate mode with no flat amount for the channel, a drop-off city Bosta
 * does not know leaves the calculator with nothing to charge. Sylius prices
 * every available method once the address is submitted, so letting that
 * exception through fails the whole checkout, even for a buyer who would have
 * picked another method. Not eligible means it is simply not offered.
 */
final class BostaFeeEligibilityChecker implements ShippingMethodEligibilityCheckerInterface
{
    public function __construct(
        private readonly CalculatorInterface $calculator,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function isEligible(ShippingSubjectInterface $shippingSubject, ShippingMethodInterface $shippingMethod): bool
    {
        if (null === BostaConfiguration::of($shippingMethod) || !$shippingSubject instanceof ShipmentInterface || null === $shippingSubject->getOrder()?->getChannel()) {
            return true;
        }

        try {
            // Estimates are cached, so pricing here and again for the order costs one Bosta call.
            $this->calculator->calculate($shippingSubject, $shippingMethod->getConfiguration());

            return true;
        } catch (MissingChannelConfigurationException $exception) {
            $this->logger->warning('Bosta method hidden: no fee for this shipment', [
                'method' => $shippingMethod->getCode(),
                'order' => $shippingSubject->getOrder()->getId(),
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
