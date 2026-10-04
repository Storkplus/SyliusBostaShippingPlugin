<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Twig;

use Storkplus\SyliusBostaShippingPlugin\Bosta;
use Storkplus\SyliusBostaShippingPlugin\Controller\AdminDeliveryController;
use Storkplus\SyliusBostaShippingPlugin\Entity\BostaDelivery;
use Storkplus\SyliusBostaShippingPlugin\Repository\BostaDeliveryRepository;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class BostaExtension extends AbstractExtension
{
    public function __construct(private readonly BostaDeliveryRepository $repository)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('bosta_is_method', $this->isMethod(...)),
            new TwigFunction('bosta_delivery', $this->delivery(...)),
            new TwigFunction('bosta_merchant_tracking_url', Bosta::merchantTrackingUrl(...)),
            new TwigFunction('bosta_customer_tracking_url', Bosta::customerTrackingUrl(...)),
            new TwigFunction('bosta_csrf_id', static fn (ShipmentInterface $shipment): string => AdminDeliveryController::CSRF_PREFIX . $shipment->getId()),
            new TwigFunction('bosta_is_problem_state', static fn (?int $code): bool => null !== $code && in_array($code, Bosta::PROBLEM_STATES, true)),
        ];
    }

    public function isMethod(?ShippingMethodInterface $method): bool
    {
        return null !== $method && Bosta::CALCULATOR === $method->getCalculator();
    }

    public function delivery(ShipmentInterface $shipment): ?BostaDelivery
    {
        return $this->isMethod($shipment->getMethod()) ? $this->repository->findOneByShipment($shipment) : null;
    }
}
