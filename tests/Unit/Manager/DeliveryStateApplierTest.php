<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Storkplus\SyliusBostaShippingPlugin\Entity\BostaDelivery;
use Storkplus\SyliusBostaShippingPlugin\Manager\DeliveryStateApplier;
use Sylius\Component\Core\Model\PaymentInterface;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\InMemoryStateMachine;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\OrderBuilder;

final class DeliveryStateApplierTest extends TestCase
{
    public function test_pickup_requested_does_not_ship(): void
    {
        $delivery = $this->delivery();
        (new DeliveryStateApplier(new InMemoryStateMachine()))->apply($delivery, 10);

        self::assertSame('ready', $delivery->getShipment()->getState());
        self::assertSame('Pickup requested', $delivery->getStateLabel());
    }

    public function test_picked_up_ships_the_sylius_shipment(): void
    {
        $delivery = $this->delivery();
        (new DeliveryStateApplier(new InMemoryStateMachine()))->apply($delivery, 21);

        self::assertSame('shipped', $delivery->getShipment()->getState());
        self::assertSame('7000001', $delivery->getShipment()->getTracking());
    }

    public function test_delivered_ships_and_completes_the_cash_on_delivery_payment(): void
    {
        $delivery = $this->delivery();
        (new DeliveryStateApplier(new InMemoryStateMachine()))->apply($delivery, 45);

        self::assertSame('shipped', $delivery->getShipment()->getState());
        $payment = $delivery->getShipment()->getOrder()?->getPayments()->first();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    public function test_cod_completion_can_be_disabled(): void
    {
        $delivery = $this->delivery(['complete_cod_on_delivery' => false]);
        (new DeliveryStateApplier(new InMemoryStateMachine()))->apply($delivery, 45);

        $payment = $delivery->getShipment()->getOrder()?->getPayments()->first();
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
    }

    public function test_exceptions_are_recorded_and_never_move_the_shipment_back(): void
    {
        $delivery = $this->delivery();
        $applier = new DeliveryStateApplier(new InMemoryStateMachine());
        $applier->apply($delivery, 41);
        $applier->apply($delivery, 47, null, 'Customer is not answering.');

        self::assertSame('shipped', $delivery->getShipment()->getState());
        self::assertSame('Exception', $delivery->getStateLabel());
        self::assertSame('Customer is not answering.', $delivery->getExceptionReason());
    }

    /** @param array<string, mixed> $config */
    private function delivery(array $config = []): BostaDelivery
    {
        $delivery = new BostaDelivery(OrderBuilder::shipment($config));
        $delivery->markCreated('id_1', '7000001', 10, 'Pickup requested');

        return $delivery;
    }
}
