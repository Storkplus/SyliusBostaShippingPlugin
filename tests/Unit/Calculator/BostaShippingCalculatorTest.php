<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Calculator;

use PHPUnit\Framework\TestCase;
use Storkplus\SyliusBostaShippingPlugin\Address\BostaAddressResolver;
use Storkplus\SyliusBostaShippingPlugin\Api\BostaApiException;
use Storkplus\SyliusBostaShippingPlugin\Calculator\BostaShippingCalculator;
use Sylius\Component\Core\Exception\MissingChannelConfigurationException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\FakeBostaClient;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\OrderBuilder;

final class BostaShippingCalculatorTest extends TestCase
{
    public function test_flat_mode_charges_the_channel_amount(): void
    {
        $shipment = OrderBuilder::shipment();

        self::assertSame(6000, $this->calculator(new FakeBostaClient())->calculate($shipment, (array) $shipment->getMethod()?->getConfiguration()));
    }

    public function test_estimate_mode_uses_the_bosta_fee(): void
    {
        $client = new FakeBostaClient();
        $client->estimate = ['data' => ['priceAfterVat' => 72.5, 'priceBeforeVat' => 63.6]];
        $shipment = OrderBuilder::shipment(['fee_mode' => 'estimate']);

        self::assertSame(7250, $this->calculator($client)->calculate($shipment, (array) $shipment->getMethod()?->getConfiguration()));
    }

    public function test_estimate_mode_falls_back_to_flat_when_bosta_fails(): void
    {
        $client = new FakeBostaClient();
        $client->estimate = new BostaApiException('timeout', 0, null, true);
        $shipment = OrderBuilder::shipment(['fee_mode' => 'estimate']);

        self::assertSame(6000, $this->calculator($client)->calculate($shipment, (array) $shipment->getMethod()?->getConfiguration()));
    }

    public function test_estimate_mode_falls_back_to_flat_on_an_unknown_response(): void
    {
        $client = new FakeBostaClient();
        $client->estimate = ['data' => ['something' => 'else']];
        $shipment = OrderBuilder::shipment(['fee_mode' => 'estimate']);

        self::assertSame(6000, $this->calculator($client)->calculate($shipment, (array) $shipment->getMethod()?->getConfiguration()));
    }

    public function test_a_channel_without_amount_is_reported(): void
    {
        $shipment = OrderBuilder::shipment(['channels' => []]);

        $this->expectException(MissingChannelConfigurationException::class);
        $this->calculator(new FakeBostaClient())->calculate($shipment, (array) $shipment->getMethod()?->getConfiguration());
    }

    private function calculator(FakeBostaClient $client): BostaShippingCalculator
    {
        return new BostaShippingCalculator($client, new BostaAddressResolver($client), new ArrayAdapter());
    }
}
