<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Checker;

use PHPUnit\Framework\TestCase;
use Storkplus\SyliusBostaShippingPlugin\Address\BostaAddressResolver;
use Storkplus\SyliusBostaShippingPlugin\Calculator\BostaShippingCalculator;
use Storkplus\SyliusBostaShippingPlugin\Checker\BostaFeeEligibilityChecker;
use Sylius\Component\Core\Model\ShippingMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\FakeBostaClient;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\OrderBuilder;

final class BostaFeeEligibilityCheckerTest extends TestCase
{
    public function test_a_method_with_a_flat_amount_is_offered(): void
    {
        $shipment = OrderBuilder::shipment();

        self::assertTrue($this->checker(new FakeBostaClient())->isEligible($shipment, $this->method($shipment)));
    }

    public function test_an_estimate_without_flat_fallback_is_offered_when_bosta_prices_the_city(): void
    {
        $client = new FakeBostaClient();
        $client->estimate = ['data' => ['priceAfterVat' => 72.5]];
        $shipment = OrderBuilder::shipment(['fee_mode' => 'estimate', 'channels' => []]);

        self::assertTrue($this->checker($client)->isEligible($shipment, $this->method($shipment)));
    }

    public function test_an_unknown_city_without_flat_fallback_hides_the_method(): void
    {
        $shipment = OrderBuilder::shipment(['fee_mode' => 'estimate', 'channels' => []], ['city' => 'Tallinn', 'province' => 'Harju']);

        self::assertFalse($this->checker(new FakeBostaClient())->isEligible($shipment, $this->method($shipment)));
    }

    public function test_other_calculators_are_left_alone(): void
    {
        $shipment = OrderBuilder::shipment(['channels' => []]);
        $method = new ShippingMethod();
        $method->setCalculator('flat_rate');

        self::assertTrue($this->checker(new FakeBostaClient())->isEligible($shipment, $method));
    }

    private function method(\Sylius\Component\Core\Model\Shipment $shipment): ShippingMethod
    {
        $method = $shipment->getMethod();
        self::assertInstanceOf(ShippingMethod::class, $method);

        return $method;
    }

    private function checker(FakeBostaClient $client): BostaFeeEligibilityChecker
    {
        return new BostaFeeEligibilityChecker(new BostaShippingCalculator($client, new BostaAddressResolver($client), new ArrayAdapter()));
    }
}
