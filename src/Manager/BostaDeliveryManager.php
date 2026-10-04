<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusBostaShippingPlugin\Address\AddressResolutionException;
use Storkplus\SyliusBostaShippingPlugin\Api\BostaApiException;
use Storkplus\SyliusBostaShippingPlugin\Api\BostaClientInterface;
use Storkplus\SyliusBostaShippingPlugin\Bosta;
use Storkplus\SyliusBostaShippingPlugin\BostaConfiguration;
use Storkplus\SyliusBostaShippingPlugin\Entity\BostaDelivery;
use Storkplus\SyliusBostaShippingPlugin\Payload\DeliveryPayloadFactoryInterface;
use Storkplus\SyliusBostaShippingPlugin\Repository\BostaDeliveryRepository;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * Creates the Bosta delivery of a shipment. Never throws for Bosta-side problems:
 * every outcome is recorded on the BostaDelivery row (created, or failed with the
 * reason) so checkout and admin pages keep working and the admin can retry.
 *
 * Retries are safe: the uniqueBusinessReference is stable across retries, so if a
 * previous attempt reached Bosta but its answer was lost, the existing delivery is
 * found by reference and adopted instead of creating a second one.
 */
final class BostaDeliveryManager implements BostaDeliveryManagerInterface
{
    public function __construct(
        private readonly BostaClientInterface $client,
        private readonly DeliveryPayloadFactoryInterface $payloadFactory,
        private readonly DeliveryStateApplier $stateApplier,
        private readonly BostaDeliveryRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(ShipmentInterface $shipment): bool
    {
        $method = $shipment->getMethod();

        return null !== $method && Bosta::CALCULATOR === $method->getCalculator();
    }

    public function get(ShipmentInterface $shipment): ?BostaDelivery
    {
        return $this->repository->findOneByShipment($shipment);
    }

    public function create(ShipmentInterface $shipment): BostaDelivery
    {
        $configuration = $this->configuration($shipment);
        $delivery = $this->getOrInit($shipment);

        if ($delivery->isCreated()) {
            return $delivery;
        }

        $this->attempt($delivery, $configuration);
        $this->entityManager->flush();

        return $delivery;
    }

    public function recreate(ShipmentInterface $shipment): BostaDelivery
    {
        $configuration = $this->configuration($shipment);
        $delivery = $this->getOrInit($shipment);

        if ($delivery->isCreated() && null !== $delivery->getTrackingNumber()) {
            if (null !== $delivery->getStateCode() && !in_array($delivery->getStateCode(), Bosta::TERMINABLE_STATES, true) && !in_array($delivery->getStateCode(), Bosta::PROBLEM_STATES, true)) {
                throw new RecreationNotAllowedException(sprintf(
                    'Delivery %s is already "%s" at Bosta and can no longer be replaced.',
                    $delivery->getTrackingNumber(),
                    (string) $delivery->getStateLabel(),
                ));
            }

            if (!in_array($delivery->getStateCode(), Bosta::PROBLEM_STATES, true)) {
                try {
                    $this->client->terminateDelivery($configuration->apiKey(), $delivery->getTrackingNumber());
                } catch (BostaApiException $exception) {
                    throw new RecreationNotAllowedException('Could not cancel the current Bosta delivery: ' . $exception->describe(), 0, $exception);
                }
            }

            $shipment->setTracking(null);
            $delivery->startRecreation();
        }

        $this->attempt($delivery, $configuration);
        $this->entityManager->flush();

        return $delivery;
    }

    public function refresh(BostaDelivery $delivery): void
    {
        $configuration = $this->configuration($delivery->getShipment());
        if (!$delivery->isCreated() || null === $delivery->getTrackingNumber()) {
            return;
        }

        $data = $this->client->getDelivery($configuration->apiKey(), $delivery->getTrackingNumber());
        [$code, $label] = self::state($data);
        if (null !== $code) {
            $this->stateApplier->apply($delivery, $code, $label);
            $this->entityManager->flush();
        }
    }

    private function attempt(BostaDelivery $delivery, BostaConfiguration $configuration): void
    {
        $delivery->markAttempt();
        $reference = $this->payloadFactory->reference($delivery, $configuration);

        try {
            $payload = $this->payloadFactory->create($delivery, $configuration);
        } catch (AddressResolutionException|\InvalidArgumentException $exception) {
            $delivery->markFailed($exception->getMessage());

            return;
        }

        try {
            $data = $this->client->createDelivery($configuration->apiKey(), $payload);
        } catch (BostaApiException $exception) {
            if (in_array($exception->getStatusCode(), [401, 403], true) || null !== $this->adopt($delivery, $configuration, $reference)) {
                if (!$delivery->isCreated()) {
                    $delivery->markFailed($exception->describe());
                }

                return;
            }

            $this->logger->error('Bosta delivery creation failed', [
                'reference' => $reference,
                'error' => $exception->describe(),
            ]);
            $delivery->markFailed($exception->describe());

            return;
        }

        $this->accept($delivery, $data);
    }

    /** Looks the reference up at Bosta and adopts the delivery an earlier attempt created. */
    private function adopt(BostaDelivery $delivery, BostaConfiguration $configuration, string $reference): ?BostaDelivery
    {
        try {
            $existing = $this->client->findDeliveriesByReference($configuration->apiKey(), $reference);
        } catch (BostaApiException) {
            return null;
        }

        foreach ($existing as $data) {
            if (($data['businessReference'] ?? null) === $reference || ($data['uniqueBusinessReference'] ?? null) === $reference) {
                $this->logger->info('Adopted an existing Bosta delivery after a failed create call', ['reference' => $reference]);
                $this->accept($delivery, $data);

                return $delivery;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function accept(BostaDelivery $delivery, array $data): void
    {
        $trackingNumber = $data['trackingNumber'] ?? null;
        $deliveryId = $data['_id'] ?? null;
        if (!is_scalar($trackingNumber) || !is_scalar($deliveryId)) {
            $delivery->markFailed('Bosta answered without a tracking number.');

            return;
        }

        [$code, $label] = self::state($data);
        $delivery->markCreated((string) $deliveryId, (string) $trackingNumber, $code, $label);
        // The Sylius tracking code is the Bosta tracking number, from creation on.
        $delivery->getShipment()->setTracking((string) $trackingNumber);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: int|null, 1: string|null}
     */
    public static function state(array $data): array
    {
        $state = $data['state'] ?? null;
        if (is_array($state)) {
            $code = $state['code'] ?? null;
            $label = $state['value'] ?? null;

            return [is_numeric($code) ? (int) $code : null, is_string($label) ? $label : null];
        }

        return [is_numeric($state) ? (int) $state : null, null];
    }

    private function configuration(ShipmentInterface $shipment): BostaConfiguration
    {
        $method = $shipment->getMethod();
        $configuration = null !== $method ? BostaConfiguration::of($method) : null;
        if (null === $configuration) {
            throw new \LogicException('This shipment is not shipped with a Bosta shipping method.');
        }

        return $configuration;
    }

    private function getOrInit(ShipmentInterface $shipment): BostaDelivery
    {
        $delivery = $this->repository->findOneByShipment($shipment);
        if (null === $delivery) {
            $delivery = new BostaDelivery($shipment);
            $this->entityManager->persist($delivery);
        }

        return $delivery;
    }
}
