<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Controller;

use Storkplus\SyliusBostaShippingPlugin\Api\BostaApiException;
use Storkplus\SyliusBostaShippingPlugin\Api\BostaClientInterface;
use Storkplus\SyliusBostaShippingPlugin\BostaConfiguration;
use Storkplus\SyliusBostaShippingPlugin\Entity\BostaDelivery;
use Storkplus\SyliusBostaShippingPlugin\Manager\BostaDeliveryManagerInterface;
use Storkplus\SyliusBostaShippingPlugin\Manager\RecreationNotAllowedException;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Admin actions on the Bosta delivery of a shipment (order page, "Bosta" card). */
final class AdminDeliveryController
{
    public const CSRF_PREFIX = 'storkplus_bosta_shipment_';

    public function __construct(
        private readonly RepositoryInterface $shipmentRepository,
        private readonly BostaDeliveryManagerInterface $manager,
        private readonly BostaClientInterface $client,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function create(Request $request, int $id): Response
    {
        $shipment = $this->shipment($request, $id);
        $delivery = $this->manager->create($shipment);
        $this->flashResult($request, $delivery, 'created');

        return $this->back($shipment);
    }

    public function recreate(Request $request, int $id): Response
    {
        $shipment = $this->shipment($request, $id);

        try {
            $delivery = $this->manager->recreate($shipment);
            $this->flashResult($request, $delivery, 'recreated');
        } catch (RecreationNotAllowedException $exception) {
            $this->flash($request, 'error', 'storkplus_sylius_bosta_shipping.flash.failed', ['%reason%' => $exception->getMessage()]);
        }

        return $this->back($shipment);
    }

    public function refresh(Request $request, int $id): Response
    {
        $shipment = $this->shipment($request, $id);
        $delivery = $this->manager->get($shipment);

        if (null !== $delivery) {
            try {
                $this->manager->refresh($delivery);
                $this->flash($request, 'success', 'storkplus_sylius_bosta_shipping.flash.refreshed', ['%state%' => (string) $delivery->getStateLabel()]);
            } catch (BostaApiException $exception) {
                $this->flash($request, 'error', 'storkplus_sylius_bosta_shipping.flash.failed', ['%reason%' => $exception->describe()]);
            }
        }

        return $this->back($shipment);
    }

    public function airwayBill(Request $request, int $id): Response
    {
        $shipment = $this->find($id);
        $delivery = $this->manager->get($shipment);
        $method = $shipment->getMethod();
        $configuration = null !== $method ? BostaConfiguration::of($method) : null;

        if (null === $delivery || null === $configuration || null === $delivery->getTrackingNumber()) {
            throw new NotFoundHttpException('This shipment has no Bosta delivery.');
        }

        try {
            $pdf = $this->client->airwayBill($configuration->apiKey(), $delivery->getTrackingNumber(), $request->getLocale());
        } catch (BostaApiException $exception) {
            $this->flash($request, 'error', 'storkplus_sylius_bosta_shipping.flash.failed', ['%reason%' => $exception->describe()]);

            return $this->back($shipment);
        }

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="bosta-%s.pdf"', $delivery->getTrackingNumber()),
        ]);
    }

    private function shipment(Request $request, int $id): ShipmentInterface
    {
        $token = new CsrfToken(self::CSRF_PREFIX . $id, (string) $request->request->get('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        return $this->find($id);
    }

    private function find(int $id): ShipmentInterface
    {
        $shipment = $this->shipmentRepository->find($id);
        if (!$shipment instanceof ShipmentInterface || !$this->manager->supports($shipment)) {
            throw new NotFoundHttpException('Bosta shipment not found.');
        }

        return $shipment;
    }

    private function flashResult(Request $request, BostaDelivery $delivery, string $action): void
    {
        if ($delivery->isCreated()) {
            $this->flash($request, 'success', 'storkplus_sylius_bosta_shipping.flash.' . $action, ['%tracking%' => (string) $delivery->getTrackingNumber()]);

            return;
        }

        $this->flash($request, 'error', 'storkplus_sylius_bosta_shipping.flash.failed', ['%reason%' => (string) $delivery->getLastError()]);
    }

    /** @param array<string, string> $parameters */
    private function flash(Request $request, string $type, string $message, array $parameters): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, ['message' => $message, 'parameters' => $parameters]);
        }
    }

    private function back(ShipmentInterface $shipment): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('sylius_admin_order_show', ['id' => $shipment->getOrder()?->getId()]) . '#bosta');
    }
}
