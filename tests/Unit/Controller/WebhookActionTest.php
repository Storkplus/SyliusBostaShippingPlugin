<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Storkplus\SyliusBostaShippingPlugin\Controller\WebhookAction;
use Storkplus\SyliusBostaShippingPlugin\Entity\BostaDelivery;
use Storkplus\SyliusBostaShippingPlugin\Manager\DeliveryStateApplier;
use Storkplus\SyliusBostaShippingPlugin\Repository\BostaDeliveryRepository;
use Symfony\Component\HttpFoundation\Request;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\InMemoryStateMachine;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\OrderBuilder;

final class WebhookActionTest extends TestCase
{
    private BostaDelivery $delivery;

    protected function setUp(): void
    {
        $this->delivery = new BostaDelivery(OrderBuilder::shipment());
        $this->delivery->markCreated('15LmfpKYp0YILntA3jbyd', '48089608', 10, 'Pickup requested');
    }

    public function test_a_valid_update_is_applied(): void
    {
        $response = $this->call(['_id' => '15LmfpKYp0YILntA3jbyd', 'trackingNumber' => 48089608, 'state' => 21, 'type' => 'SEND', 'timeStamp' => 1689252908261], 'secret-0123456789abcdef');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(21, $this->delivery->getStateCode());
        self::assertSame('shipped', $this->delivery->getShipment()->getState());
        self::assertNotNull($this->delivery->getLastWebhookAt());
    }

    public function test_a_wrong_secret_is_rejected(): void
    {
        $response = $this->call(['trackingNumber' => '48089608', 'state' => 45], 'nope');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(10, $this->delivery->getStateCode());
    }

    public function test_a_missing_secret_is_rejected(): void
    {
        self::assertSame(401, $this->call(['trackingNumber' => '48089608', 'state' => 45], null)->getStatusCode());
    }

    public function test_unknown_deliveries_are_acknowledged(): void
    {
        self::assertSame(200, $this->call(['trackingNumber' => '1', 'state' => 45], 'whatever')->getStatusCode());
    }

    public function test_exception_reason_is_kept(): void
    {
        $this->call(['trackingNumber' => '48089608', 'state' => 47, 'exceptionReason' => 'Postponed - the customer requested postponement for another day.', 'exceptionCode' => 3], 'secret-0123456789abcdef');

        self::assertSame('Postponed - the customer requested postponement for another day.', $this->delivery->getExceptionReason());
    }

    /** @param array<string, mixed> $payload */
    private function call(array $payload, ?string $secret): \Symfony\Component\HttpFoundation\Response
    {
        $repository = $this->createMock(BostaDeliveryRepository::class);
        $repository->method('findOneByTrackingNumber')->willReturnCallback(fn (string $tn) => '48089608' === $tn ? $this->delivery : null);
        $repository->method('findOneByDeliveryId')->willReturn(null);

        $request = Request::create('/shipping/bosta/webhook', 'POST', [], [], [], [], (string) json_encode($payload));
        if (null !== $secret) {
            $request->headers->set('X-Bosta-Secret', $secret);
        }

        $action = new WebhookAction($repository, new DeliveryStateApplier(new InMemoryStateMachine()), $this->createMock(EntityManagerInterface::class));

        return $action($request);
    }
}
