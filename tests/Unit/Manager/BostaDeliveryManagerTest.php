<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Manager;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Storkplus\SyliusBostaShippingPlugin\Address\BostaAddressResolver;
use Storkplus\SyliusBostaShippingPlugin\Api\BostaApiException;
use Storkplus\SyliusBostaShippingPlugin\Entity\BostaDelivery;
use Storkplus\SyliusBostaShippingPlugin\Manager\BostaDeliveryManager;
use Storkplus\SyliusBostaShippingPlugin\Manager\DeliveryStateApplier;
use Storkplus\SyliusBostaShippingPlugin\Manager\RecreationNotAllowedException;
use Storkplus\SyliusBostaShippingPlugin\Payload\DeliveryPayloadFactory;
use Storkplus\SyliusBostaShippingPlugin\Repository\BostaDeliveryRepository;
use Sylius\Component\Core\Model\Shipment;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\FakeBostaClient;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\InMemoryStateMachine;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\OrderBuilder;

final class BostaDeliveryManagerTest extends TestCase
{
    private FakeBostaClient $client;

    private ?BostaDelivery $stored = null;

    protected function setUp(): void
    {
        $this->client = new FakeBostaClient();
    }

    public function test_it_creates_the_delivery_and_uses_the_bosta_tracking_number(): void
    {
        $shipment = OrderBuilder::shipment();

        $delivery = $this->manager()->create($shipment);

        self::assertTrue($delivery->isCreated());
        self::assertSame('7000001', $delivery->getTrackingNumber());
        self::assertSame('7000001', $shipment->getTracking());
        self::assertSame(10, $delivery->getStateCode());
        self::assertCount(1, $this->client->created);
    }

    public function test_creating_twice_is_a_no_op(): void
    {
        $shipment = OrderBuilder::shipment();
        $this->manager()->create($shipment);
        $this->manager()->create($shipment);

        self::assertCount(1, $this->client->created);
    }

    public function test_a_bosta_error_is_recorded_and_a_retry_succeeds(): void
    {
        $shipment = OrderBuilder::shipment();
        $this->client->createFailures = [new BostaApiException('District Not Found', 400, 3003)];

        $failed = $this->manager()->create($shipment);
        self::assertTrue($failed->isFailed());
        self::assertStringContainsString('District Not Found', (string) $failed->getLastError());
        self::assertNull($shipment->getTracking());

        $retried = $this->manager()->create($shipment);
        self::assertTrue($retried->isCreated());
        self::assertSame(2, $retried->getAttempts());
        self::assertNull($retried->getLastError());
    }

    public function test_a_lost_response_is_recovered_without_creating_a_duplicate(): void
    {
        $shipment = OrderBuilder::shipment();
        $this->client->loseCreateResponse = true;

        $delivery = $this->manager()->create($shipment);

        self::assertTrue($delivery->isCreated(), 'the delivery Bosta created is adopted by reference');
        self::assertSame('7000001', $shipment->getTracking());
        self::assertCount(1, $this->client->created);
    }

    public function test_a_bad_address_fails_without_calling_bosta(): void
    {
        $shipment = OrderBuilder::shipment(address: ['province' => 'Narnia', 'city' => 'Nowhere']);

        $delivery = $this->manager()->create($shipment);

        self::assertTrue($delivery->isFailed());
        self::assertSame([], $this->client->created);
    }

    public function test_recreate_cancels_the_pending_delivery_and_creates_a_new_one(): void
    {
        $shipment = OrderBuilder::shipment();
        $this->manager()->create($shipment);

        $delivery = $this->manager()->recreate($shipment);

        self::assertSame(['7000001'], $this->client->terminated);
        self::assertSame('7000002', $delivery->getTrackingNumber());
        self::assertSame('7000002', $shipment->getTracking());
        self::assertSame('SMASH-000042-77-R2', $this->client->created[1]['uniqueBusinessReference']);
    }

    public function test_a_delivery_already_on_its_way_cannot_be_recreated(): void
    {
        $shipment = OrderBuilder::shipment();
        $delivery = $this->manager()->create($shipment);
        $delivery->updateState(41, 'Out for delivery');

        $this->expectException(RecreationNotAllowedException::class);
        $this->manager()->recreate($shipment);
    }

    private function manager(): BostaDeliveryManager
    {
        $repository = $this->createMock(BostaDeliveryRepository::class);
        $repository->method('findOneByShipment')->willReturnCallback(fn () => $this->stored);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof BostaDelivery) {
                $this->stored = $entity;
            }
        });

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('https://smash.storkplus.com/shipping/bosta/webhook');

        return new BostaDeliveryManager(
            $this->client,
            new DeliveryPayloadFactory(new BostaAddressResolver($this->client), $router),
            new DeliveryStateApplier(new InMemoryStateMachine()),
            $repository,
            $entityManager,
        );
    }
}
