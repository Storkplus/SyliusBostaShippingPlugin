<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Address;

use Storkplus\SyliusBostaShippingPlugin\Api\BostaApiException;
use Storkplus\SyliusBostaShippingPlugin\Api\BostaClientInterface;
use Sylius\Component\Addressing\Model\AddressInterface;

/**
 * Sylius addresses are free text; Bosta needs one of its 28 cities and, ideally,
 * one of its districts. The governorate is read from the province (name or ISO
 * 3166-2:EG code) or the city field; the district from the city and street fields.
 * When no district matches, the district is sent by name with the city id and Bosta
 * decides; if it refuses, the shipment fails visibly and the admin fixes the address
 * and retries.
 */
final class BostaAddressResolver implements BostaAddressResolverInterface
{
    /** ISO 3166-2:EG codes and common spellings => Bosta city name. */
    private const CITY_ALIASES = [
        'eg-c' => 'Cairo', 'cairo' => 'Cairo', 'al qahirah' => 'Cairo', 'القاهره' => 'Cairo',
        'eg-gz' => 'Giza', 'giza' => 'Giza', 'gizeh' => 'Giza', 'al jizah' => 'Giza', 'الجيزه' => 'Giza',
        'eg-alx' => 'Alexandria', 'alexandria' => 'Alexandria', 'alex' => 'Alexandria', 'الاسكندريه' => 'Alexandria',
        'eg-kb' => 'El Kalioubia', 'qalyubia' => 'El Kalioubia', 'qaliubiya' => 'El Kalioubia', 'kalyoubia' => 'El Kalioubia', 'qalyubiyah' => 'El Kalioubia', 'القليوبيه' => 'El Kalioubia',
        'eg-dk' => 'Dakahlia', 'dakahlia' => 'Dakahlia', 'daqahliyah' => 'Dakahlia', 'الدقهليه' => 'Dakahlia',
        'eg-shr' => 'Sharqia', 'sharqia' => 'Sharqia', 'sharkia' => 'Sharqia', 'sharqiyah' => 'Sharqia', 'الشرقيه' => 'Sharqia',
        'eg-gh' => 'Gharbia', 'gharbia' => 'Gharbia', 'gharbiyah' => 'Gharbia', 'الغربيه' => 'Gharbia',
        'eg-mnf' => 'Monufia', 'monufia' => 'Monufia', 'menoufia' => 'Monufia', 'minufiyah' => 'Monufia', 'المنوفيه' => 'Monufia',
        'eg-bh' => 'Behira', 'beheira' => 'Behira', 'behira' => 'Behira', 'buhayrah' => 'Behira', 'البحيره' => 'Behira',
        'eg-kfs' => 'Kafr Alsheikh', 'kafr el sheikh' => 'Kafr Alsheikh', 'kafr alsheikh' => 'Kafr Alsheikh', 'كفر الشيخ' => 'Kafr Alsheikh',
        'eg-dt' => 'Damietta', 'damietta' => 'Damietta', 'dumyat' => 'Damietta', 'دمياط' => 'Damietta',
        'eg-pts' => 'Port Said', 'port said' => 'Port Said', 'بورسعيد' => 'Port Said', 'بور سعيد' => 'Port Said',
        'eg-is' => 'Ismailia', 'ismailia' => 'Ismailia', 'الاسماعيليه' => 'Ismailia',
        'eg-suz' => 'Suez', 'suez' => 'Suez', 'السويس' => 'Suez',
        'eg-fym' => 'Fayoum', 'fayoum' => 'Fayoum', 'faiyum' => 'Fayoum', 'الفيوم' => 'Fayoum',
        'eg-bns' => 'Bani Suif', 'beni suef' => 'Bani Suif', 'bani suif' => 'Bani Suif', 'beni sweif' => 'Bani Suif', 'بني سويف' => 'Bani Suif',
        'eg-mn' => 'Menya', 'minya' => 'Menya', 'menya' => 'Menya', 'minia' => 'Menya', 'المنيا' => 'Menya',
        'eg-ast' => 'Assuit', 'asyut' => 'Assuit', 'assiut' => 'Assuit', 'assuit' => 'Assuit', 'اسيوط' => 'Assuit',
        'eg-shg' => 'Sohag', 'sohag' => 'Sohag', 'suhaj' => 'Sohag', 'سوهاج' => 'Sohag',
        'eg-kn' => 'Qena', 'qena' => 'Qena', 'qina' => 'Qena', 'قنا' => 'Qena',
        'eg-lx' => 'Luxor', 'luxor' => 'Luxor', 'الاقصر' => 'Luxor',
        'eg-asn' => 'Aswan', 'aswan' => 'Aswan', 'اسوان' => 'Aswan',
        'eg-ba' => 'Red Sea', 'red sea' => 'Red Sea', 'البحر الاحمر' => 'Red Sea',
        'eg-wad' => 'New Valley', 'new valley' => 'New Valley', 'الوادي الجديد' => 'New Valley',
        'eg-mt' => 'Matrouh', 'matrouh' => 'Matrouh', 'matruh' => 'Matrouh', 'مطروح' => 'Matrouh',
        'eg-sin' => 'North Sinai', 'north sinai' => 'North Sinai', 'شمال سيناء' => 'North Sinai',
        'eg-js' => 'South Sinai', 'south sinai' => 'South Sinai', 'جنوب سيناء' => 'South Sinai',
    ];

    /** @var array<string, string>|null */
    private ?array $aliases = null;

    public function __construct(private readonly BostaClientInterface $client)
    {
    }

    public function resolve(AddressInterface $address): array
    {
        $cities = $this->loadCities();
        $city = $this->findCity($cities, $address);
        $district = null;

        if (null !== $city) {
            $district = $this->findDistrict([$city], $address);
        } else {
            // No governorate given: a district name alone can still identify the city.
            $match = $this->findDistrict($cities, $address);
            if (null !== $match) {
                [$city, $district] = [$match['city'], $match];
            }
        }

        if (null === $city) {
            throw new AddressResolutionException(sprintf(
                'Could not match "%s" to a Bosta city. Set the province (governorate) of the shipping address, e.g. Cairo or Giza.',
                trim(implode(', ', array_filter([$address->getCity(), $address->getProvinceName(), $address->getProvinceCode()]))),
            ));
        }

        $street = trim((string) $address->getStreet());
        $firstLine = mb_strlen($street) > 5 ? $street : trim($street . ' ' . (string) $address->getCity());
        if (mb_strlen($firstLine) <= 5) {
            throw new AddressResolutionException('The street address is too short for Bosta (more than 5 characters are required).');
        }

        $result = [
            'city' => self::str($city['cityName']),
            'cityId' => self::str($city['cityId']),
            'firstLine' => $firstLine,
        ];

        if (null !== $district) {
            $result['districtId'] = self::str($district['district']['districtId']);
            if (isset($district['district']['zoneId']) && is_string($district['district']['zoneId'])) {
                $result['zoneId'] = $district['district']['zoneId'];
            }
        } else {
            $result['districtName'] = trim((string) $address->getCity()) ?: self::str($city['cityName']);
        }

        $secondLine = trim(implode(', ', array_filter([
            $this->differs($address->getCity(), $result['city']) ? (string) $address->getCity() : null,
            $address->getPostcode(),
        ])));
        if ('' !== $secondLine) {
            $result['secondLine'] = $secondLine;
        }

        return $result;
    }

    public function resolveCityName(AddressInterface $address): ?string
    {
        try {
            $city = $this->findCity($this->loadCities(), $address);
        } catch (AddressResolutionException) {
            return null;
        }

        return null !== $city ? self::str($city['cityName']) : null;
    }

    /** @return list<array<string, mixed>> */
    private function loadCities(): array
    {
        try {
            return $this->client->citiesWithDistricts();
        } catch (BostaApiException $exception) {
            throw new AddressResolutionException('Could not load the Bosta city list: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param list<array<string, mixed>> $cities
     *
     * @return array<string, mixed>|null
     */
    private function findCity(array $cities, AddressInterface $address): ?array
    {
        foreach ([$address->getProvinceCode(), $address->getProvinceName(), $address->getCity()] as $candidate) {
            $key = self::normalize((string) $candidate);
            if ('' === $key) {
                continue;
            }

            $key = self::normalize(str_replace(['governorate', 'محافظه'], '', $key));
            $alias = $this->aliases()[$key] ?? null;

            foreach ($cities as $city) {
                $names = [$city['cityName'] ?? '', $city['cityOtherName'] ?? '', $city['cityCode'] ?? ''];
                foreach ($names as $name) {
                    $normalized = self::normalize(self::str($name));
                    if ('' !== $normalized && ($normalized === $key || (null !== $alias && $normalized === self::normalize($alias)))) {
                        return $city;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $cities
     *
     * @return array{city: array<string, mixed>, district: array<string, mixed>}|null
     */
    private function findDistrict(array $cities, AddressInterface $address): ?array
    {
        $haystacks = array_values(array_filter([
            self::normalize((string) $address->getCity()),
            self::normalize((string) $address->getStreet()),
        ]));
        if ([] === $haystacks) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($cities as $city) {
            $districts = $city['districts'] ?? [];
            foreach (is_array($districts) ? $districts : [] as $district) {
                if (!is_array($district) || false === ($district['dropOffAvailability'] ?? true) || !isset($district['districtId'])) {
                    continue;
                }

                foreach (['districtName', 'districtOtherName', 'zoneName', 'zoneOtherName'] as $field) {
                    $name = self::normalize((string) ($district[$field] ?? ''));
                    if (mb_strlen($name) < 3) {
                        continue;
                    }

                    foreach ($haystacks as $index => $haystack) {
                        // Exact match on the city field wins; otherwise the longest name found in the text.
                        $score = match (true) {
                            $haystack === $name => 1000 - $index,
                            str_contains(' ' . $haystack . ' ', ' ' . $name . ' ') => mb_strlen($name) * ('district' === substr($field, 0, 8) ? 2 : 1) - $index,
                            default => 0,
                        };
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $best = ['city' => $city, 'district' => $district];
                        }
                    }
                }
            }
        }

        return $best;
    }

    /** @return array<string, string> */
    private function aliases(): array
    {
        if (null === $this->aliases) {
            $this->aliases = [];
            foreach (self::CITY_ALIASES as $alias => $city) {
                $this->aliases[self::normalize($alias)] = $city;
            }
        }

        return $this->aliases;
    }

    private function differs(?string $a, string $b): bool
    {
        return null !== $a && '' !== trim($a) && self::normalize($a) !== self::normalize($b);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function normalize(string $value): string
    {
        // Bosta glues the article on ("ElMaadi", "ElSheikh Zayed"): drop it before lowercasing.
        $value = (string) preg_replace('/\b(?:El|Al)(?=\p{Lu})/u', '', trim($value));
        $value = mb_strtolower($value);
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ـ' => '']);
        if (class_exists(\Transliterator::class)) {
            $ascii = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')?->transliterate($value);
            $value = is_string($ascii) ? $ascii : $value;
        }
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        $value = (string) preg_replace('/(^| )(el|al|ال) /u', '$1', $value);
        // "6th of October" / "06 October" / "6 october" all become "6 october".
        $value = (string) preg_replace('/\b0+(\d)/', '$1', $value);
        $value = (string) preg_replace('/\b(\d+)(st|nd|rd|th)\b/', '$1', $value);
        $value = (string) preg_replace('/ of /', ' ', ' ' . $value . ' ');

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
