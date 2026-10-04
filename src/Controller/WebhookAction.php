<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusBostaShippingPlugin\Bosta;
use Storkplus\SyliusBostaShippingPlugin\BostaConfiguration;
use Storkplus\SyliusBostaShippingPlugin\Entity\BostaDelivery;
use Storkplus\SyliusBostaShippingPlugin\Manager\DeliveryStateApplier;
use Storkplus\SyliusBostaShippingPlugin\Repository\BostaDeliveryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Static per-store endpoint Bosta posts delivery state changes to:
 * https://<store>/shipping/bosta/webhook
 *
 * Bosta does not sign webhooks. Every delivery is created with this URL and a
 * per-shipping-method secret in the X-Bosta-Secret header (Bosta echoes custom
 * headers back); the same header can be configured on the account-level webhook in
 * the Bosta dashboard. Requests whose secret does not match the delivery's shipping
 * method are rejected. Unknown tracking numbers (deliveries not created by this
 * store) are acknowledged and ignored so Bosta does not retry them.
 */
final class WebhookAction
{
    public function __construct(
        private readonly BostaDeliveryRepository $repository,
        private readonly DeliveryStateApplier $stateApplier,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return new JsonResponse(['status' => 'ok', 'hint' => 'Bosta webhook endpoint; POST delivery updates here.']);
        }

        try {
            $payload = json_decode($request->getContent(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'invalid json'], Response::HTTP_BAD_REQUEST);
        }
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $delivery = $this->find($payload);
        if (null === $delivery) {
            return new JsonResponse(['status' => 'ignored'], Response::HTTP_OK);
        }

        $method = $delivery->getShipment()->getMethod();
        $configuration = null !== $method ? BostaConfiguration::of($method) : null;
        $expected = $configuration?->webhookSecret() ?? '';
        $given = (string) ($request->headers->get(Bosta::WEBHOOK_SECRET_HEADER) ?? $request->headers->get('Authorization') ?? '');
        $given = (string) preg_replace('/^(Bearer|Basic)\s+/i', '', trim($given));

        if ('' === $expected || !hash_equals($expected, $given)) {
            $this->logger->warning('Rejected Bosta webhook: secret mismatch', ['tracking' => $delivery->getTrackingNumber()]);

            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $state = $payload['state'] ?? null;
        if (is_array($state)) {
            $state = $state['code'] ?? null;
        }
        if (!is_numeric($state)) {
            return new JsonResponse(['error' => 'missing state'], Response::HTTP_BAD_REQUEST);
        }

        $delivery->markWebhookReceived();
        $this->stateApplier->apply(
            $delivery,
            (int) $state,
            null,
            is_string($payload['exceptionReason'] ?? null) ? $payload['exceptionReason'] : null,
        );
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok']);
    }

    /** @param array<array-key, mixed> $payload */
    private function find(array $payload): ?BostaDelivery
    {
        $trackingNumber = $payload['trackingNumber'] ?? null;
        if (is_scalar($trackingNumber) && '' !== (string) $trackingNumber) {
            $delivery = $this->repository->findOneByTrackingNumber((string) $trackingNumber);
            if (null !== $delivery) {
                return $delivery;
            }
        }

        $deliveryId = $payload['_id'] ?? null;

        return is_string($deliveryId) && '' !== $deliveryId ? $this->repository->findOneByDeliveryId($deliveryId) : null;
    }
}
