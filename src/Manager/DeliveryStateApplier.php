<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Manager;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusBostaShippingPlugin\Bosta;
use Storkplus\SyliusBostaShippingPlugin\BostaConfiguration;
use Storkplus\SyliusBostaShippingPlugin\Entity\BostaDelivery;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Shipping\ShipmentTransitions;

/**
 * Moves the Sylius side along with Bosta:
 * - picked up / in transit / out for delivery / delivered => shipment "ship"
 *   (Sylius sends the shipping confirmation with the Bosta tracking number);
 * - delivered => cash-on-delivery payments "complete" (the courier collected the
 *   money), which lets the order become fulfilled.
 * Problem states (returned, lost, damaged, terminated, canceled) are only recorded
 * for the merchant to act on. Nothing is ever moved backwards.
 */
final class DeliveryStateApplier
{
    public function __construct(
        private readonly StateMachineInterface $stateMachine,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function apply(BostaDelivery $delivery, int $stateCode, ?string $stateLabel = null, ?string $exceptionReason = null): void
    {
        $label = $stateLabel ?? Bosta::STATE_LABELS[$stateCode] ?? (string) $stateCode;
        $delivery->updateState($stateCode, $label, $exceptionReason);

        $shipment = $delivery->getShipment();

        if (in_array($stateCode, Bosta::SHIPPED_STATES, true)) {
            if (null !== $delivery->getTrackingNumber() && null === $shipment->getTracking()) {
                $shipment->setTracking($delivery->getTrackingNumber());
            }
            if ($this->stateMachine->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)) {
                $this->stateMachine->apply($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP);
            }
        }

        if (Bosta::STATE_DELIVERED === $stateCode) {
            $this->completeCashOnDelivery($delivery);
        }

        if (in_array($stateCode, Bosta::PROBLEM_STATES, true)) {
            $this->logger->warning('Bosta delivery needs attention', [
                'tracking' => $delivery->getTrackingNumber(),
                'state' => $label,
                'order' => $shipment->getOrder()?->getNumber(),
            ]);
        }
    }

    private function completeCashOnDelivery(BostaDelivery $delivery): void
    {
        $method = $delivery->getShipment()->getMethod();
        $configuration = null !== $method ? BostaConfiguration::of($method) : null;
        $order = $delivery->getShipment()->getOrder();
        if (null === $configuration || !$configuration->completeCodOnDelivery() || !$order instanceof OrderInterface) {
            return;
        }

        foreach ($order->getPayments() as $payment) {
            if (!$payment instanceof PaymentInterface || !in_array($payment->getMethod()?->getCode(), $configuration->codPaymentMethods(), true)) {
                continue;
            }
            if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)) {
                $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
            }
        }
    }
}
