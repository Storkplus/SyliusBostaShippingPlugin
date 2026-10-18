<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Payload;

use PHPUnit\Framework\TestCase;
use Storkplus\SyliusBostaShippingPlugin\Address\BostaAddressResolver;
use Storkplus\SyliusBostaShippingPlugin\BostaConfiguration;
use Storkplus\SyliusBostaShippingPlugin\Entity\BostaDelivery;
use Storkplus\SyliusBostaShippingPlugin\Payload\DeliveryPayloadFactory;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\FakeBostaClient;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\OrderBuilder;

final class DeliveryPayloadFactoryTest extends TestCase
{
    public function test_cash_on_delivery_order(): void
    {
        $shipment = OrderBuilder::shipment();
        $payload = $this->payload($shipment);

        self::assertSame(10, $payload['type']);
        self::assertSame(360.0, $payload['cod']);
        self::assertSame('SHOP-000042-77', $payload['uniqueBusinessReference']);
        self::assertSame($payload['uniqueBusinessReference'], $payload['businessReference']);
        self::assertSame('01001234567', $payload['receiver']['phone']);
        self::assertSame('Aya', $payload['receiver']['firstName']);
        self::assertSame('Cairo', $payload['dropOffAddress']['city']);
        self::assertSame(2, $payload['specs']['packageDetails']['itemsCount']);
        self::assertSame('2 × Smash Burger', $payload['specs']['packageDetails']['description']);
        self::assertSame('https://shop.example.com/shipping/bosta/webhook', $payload['webhookUrl']);
        self::assertSame(['X-Bosta-Secret' => 'secret-0123456789abcdef'], $payload['webhookCustomHeaders']);
    }

    public function test_prepaid_order_has_no_cod(): void
    {
        $payload = $this->payload(OrderBuilder::shipment(paymentMethodCode: 'xpay', paymentState: PaymentInterface::STATE_COMPLETED));

        self::assertSame(0.0, $payload['cod']);
    }

    public function test_recreated_delivery_gets_a_new_reference(): void
    {
        $delivery = new BostaDelivery(OrderBuilder::shipment([BostaConfigurationKeys::PREFIX => 'shop one']));
        $delivery->startRecreation();

        self::assertSame('SHOPONE-000042-77-R2', $this->factory()->reference($delivery, $this->config($delivery->getShipment())));
    }

    public function test_a_missing_mobile_number_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->payload(OrderBuilder::shipment(address: ['phone' => '12345']));
    }

    public function test_addresses_outside_egypt_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Egypt');
        $this->payload(OrderBuilder::shipment(address: ['country' => 'SA']));
    }

    /** @return array<string, mixed> */
    private function payload(ShipmentInterface $shipment): array
    {
        return $this->factory()->create(new BostaDelivery($shipment), $this->config($shipment));
    }

    private function config(ShipmentInterface $shipment): BostaConfiguration
    {
        $method = $shipment->getMethod();
        self::assertNotNull($method);
        $config = BostaConfiguration::of($method);
        self::assertNotNull($config);

        return $config;
    }

    private function factory(): DeliveryPayloadFactory
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('https://shop.example.com/shipping/bosta/webhook');

        return new DeliveryPayloadFactory(new BostaAddressResolver(new FakeBostaClient()), $router);
    }
}

final class BostaConfigurationKeys
{
    public const PREFIX = 'reference_prefix';
}
