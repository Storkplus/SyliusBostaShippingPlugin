<?php

declare(strict_types=1);

namespace Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Address;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Storkplus\SyliusBostaShippingPlugin\Address\AddressResolutionException;
use Storkplus\SyliusBostaShippingPlugin\Address\BostaAddressResolver;
use Sylius\Component\Core\Model\Address;
use Tests\Storkplus\SyliusBostaShippingPlugin\Unit\Fixture\FakeBostaClient;

final class BostaAddressResolverTest extends TestCase
{
    /** @return iterable<string, array{array<string, string|null>, string, string}> */
    public static function addresses(): iterable
    {
        yield 'province name + zone' => [['province' => 'Cairo', 'city' => 'Nasr City'], 'Cairo', 'ElHay 06 (Nasr City)'];
        yield 'glued El prefix' => [['province' => 'Cairo', 'city' => 'Maadi'], 'Cairo', 'ElMaadi'];
        yield 'arabic governorate' => [['province' => 'القاهرة', 'city' => 'الزمالك'], 'Cairo', 'ElZamalek'];
        yield 'iso code' => [['provinceCode' => 'EG-GZ', 'province' => null, 'city' => 'Dokki'], 'Giza', 'Dokki'];
        yield '6th of October' => [['province' => 'Giza', 'city' => '6th of October'], 'Giza', 'District 01 (06 October)'];
        yield 'district without governorate' => [['province' => null, 'city' => 'Sheikh Zayed'], 'Giza', 'ElSheikh Zayed'];
        yield 'governorate suffix' => [['province' => 'Alexandria Governorate', 'city' => 'Smouha'], 'Alexandria', 'Smouha'];
    }

    /** @param array<string, string|null> $input */
    #[DataProvider('addresses')]
    public function test_it_maps_sylius_addresses_to_bosta(array $input, string $city, string $district): void
    {
        $result = $this->resolver()->resolve($this->address($input));

        self::assertSame($city, $result['city']);
        self::assertSame($district, $this->districtName($result['districtId'] ?? ''));
        self::assertSame('12 Abbas El Akkad St', $result['firstLine']);
    }

    public function test_an_unknown_district_is_sent_by_name_with_the_city(): void
    {
        $result = $this->resolver()->resolve($this->address(['province' => 'Cairo', 'city' => 'Garden City']));

        self::assertSame('Cairo', $result['city']);
        self::assertSame('FceDyHXwpSYYF9zGW', $result['cityId']);
        self::assertSame('Garden City', $result['districtName']);
        self::assertArrayNotHasKey('districtId', $result);
    }

    public function test_an_address_outside_bosta_cities_fails_with_a_clear_message(): void
    {
        $this->expectException(AddressResolutionException::class);
        $this->expectExceptionMessage('Bosta city');

        $this->resolver()->resolve($this->address(['province' => 'Narnia', 'city' => 'Cair Paravel']));
    }

    public function test_a_too_short_street_borrows_the_city(): void
    {
        $result = $this->resolver()->resolve($this->address(['province' => 'Cairo', 'city' => 'Maadi', 'street' => '5']));

        self::assertSame('5 Maadi', $result['firstLine']);
    }

    private function resolver(): BostaAddressResolver
    {
        return new BostaAddressResolver(new FakeBostaClient());
    }

    /** @param array<string, string|null> $input */
    private function address(array $input): Address
    {
        $address = new Address();
        $address->setCountryCode('EG');
        $address->setProvinceName(array_key_exists('province', $input) ? $input['province'] : 'Cairo');
        $address->setProvinceCode($input['provinceCode'] ?? null);
        $address->setCity($input['city'] ?? null);
        $address->setStreet($input['street'] ?? '12 Abbas El Akkad St');

        return $address;
    }

    private function districtName(string $districtId): string
    {
        foreach ((new FakeBostaClient())->citiesWithDistricts() as $city) {
            foreach ($city['districts'] as $district) {
                if ($district['districtId'] === $districtId) {
                    return $district['districtName'];
                }
            }
        }

        return '(none)';
    }
}
