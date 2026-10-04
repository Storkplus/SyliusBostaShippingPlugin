<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusBostaShippingPlugin\BostaConfiguration;
use Storkplus\SyliusBostaShippingPlugin\Manager\BostaDeliveryManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Creates the Bosta delivery once the order is ready to be shipped:
 * - at checkout completion when the customer pays cash on delivery (or nothing);
 * - when the order becomes paid, for online payments.
 *
 * The call to Bosta is deferred to kernel.terminate / console.terminate: it runs
 * after the order is flushed and after the response is sent, so the customer never
 * waits for Bosta and a Bosta outage can never break checkout. Failures stay on the
 * BostaDelivery row for the admin to retry.
 */
final class AutoCreateDeliveryListener
{
    /** @var array<int, true> */
    private array $pending = [];

    public function __construct(
        private readonly BostaDeliveryManagerInterface $manager,
        private readonly RepositoryInterface $shipmentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function onCheckoutCompleted(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof OrderInterface) {
            return;
        }

        $readyNow = 0 === $order->getTotal() ||
            OrderPaymentStates::STATE_PAID === $order->getPaymentState() ||
            $this->paysCashOnDelivery($order);

        if ($readyNow) {
            $this->queue($order);
        }
    }

    public function onOrderPaid(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        if ($order instanceof OrderInterface) {
            $this->queue($order);
        }
    }

    public function flush(): void
    {
        $ids = array_keys($this->pending);
        $this->pending = [];

        foreach ($ids as $id) {
            try {
                if (!$this->entityManager->isOpen()) {
                    return;
                }
                $shipment = $this->shipmentRepository->find($id);
                if ($shipment instanceof ShipmentInterface && $this->manager->supports($shipment)) {
                    $this->manager->create($shipment);
                }
            } catch (\Throwable $exception) {
                $this->logger->error('Automatic Bosta delivery creation failed', ['shipment' => $id, 'exception' => $exception]);
            }
        }
    }

    private function queue(OrderInterface $order): void
    {
        foreach ($order->getShipments() as $shipment) {
            $method = $shipment->getMethod();
            $configuration = null !== $method ? BostaConfiguration::of($method) : null;
            if (null !== $configuration && $configuration->autoCreate() && null !== $shipment->getId()) {
                $this->pending[(int) $shipment->getId()] = true;
            }
        }
    }

    private function paysCashOnDelivery(OrderInterface $order): bool
    {
        foreach ($order->getShipments() as $shipment) {
            $method = $shipment->getMethod();
            $configuration = null !== $method ? BostaConfiguration::of($method) : null;
            if (null === $configuration) {
                continue;
            }

            foreach ($order->getPayments() as $payment) {
                if ($payment instanceof PaymentInterface && in_array($payment->getMethod()?->getCode(), $configuration->codPaymentMethods(), true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
