<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Api;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Storkplus\SyliusBostaShippingPlugin\Bosta;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BostaClient implements BostaClientInterface
{
    private const CITIES_CACHE_KEY = 'storkplus_bosta_cities_with_districts_v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly string $baseUrl = Bosta::API_BASE_URL,
        private readonly float $timeout = 20.0,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function createDelivery(string $apiKey, array $payload): array
    {
        return $this->data($this->request('POST', '/deliveries?apiVersion=1', $apiKey, $payload));
    }

    public function getDelivery(string $apiKey, string $trackingNumber): array
    {
        return $this->data($this->request('GET', '/deliveries/business/' . rawurlencode($trackingNumber), $apiKey));
    }

    public function findDeliveriesByReference(string $apiKey, string $businessReference): array
    {
        $body = $this->request('POST', '/deliveries/search', $apiKey, ['businessReference' => $businessReference]);
        $data = $body['data'] ?? [];
        $list = is_array($data) ? ($data['deliveries'] ?? $data['list'] ?? $data) : [];

        $deliveries = [];
        foreach (is_array($list) ? $list : [] as $delivery) {
            if (is_array($delivery) && isset($delivery['trackingNumber'])) {
                /** @var array<string, mixed> $found */
                $found = $delivery;
                $deliveries[] = $found;
            }
        }

        return $deliveries;
    }

    public function terminateDelivery(string $apiKey, string $trackingNumber): void
    {
        $this->request('DELETE', '/deliveries/business/' . rawurlencode($trackingNumber) . '/terminate', $apiKey);
    }

    public function estimateShippingFees(string $apiKey, array $query): array
    {
        $body = $this->request('GET', '/pricing/shipment/calculator?' . http_build_query($query), $apiKey, timeout: 5.0);
        $data = $body['data'] ?? $body;

        return is_array($data) ? $data : [];
    }

    public function airwayBill(string $apiKey, string $trackingNumber, string $lang): string
    {
        $body = $this->request('POST', '/deliveries/mass-awb', $apiKey, [
            'lang' => 'ar' === $lang ? 'ar' : 'en',
            'requestedAwbType' => 'A6',
            'trackingNumbers' => $trackingNumber,
        ]);

        $encoded = $body['data'] ?? null;
        if (is_array($encoded)) {
            $encoded = $encoded['file'] ?? $encoded['data'] ?? null;
        }
        $pdf = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (false === $pdf || !str_starts_with($pdf, '%PDF')) {
            throw new BostaApiException('Bosta did not return an airway bill PDF.');
        }

        return $pdf;
    }

    public function citiesWithDistricts(): array
    {
        /** @var list<array<string, mixed>> $cities */
        $cities = $this->cache->get(self::CITIES_CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(86400);

            $body = $this->request('GET', '/cities/getAllDistricts?countryId=' . Bosta::EGYPT_COUNTRY_ID, null);
            $data = $body['data'] ?? [];

            return array_values(array_filter(is_array($data) ? $data : [], 'is_array'));
        });

        return $cities;
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<array-key, mixed>
     */
    private function request(string $method, string $path, ?string $apiKey, ?array $json = null, ?float $timeout = null): array
    {
        $headers = ['Accept' => 'application/json'];
        if (null !== $apiKey) {
            if ('' === trim($apiKey)) {
                throw new BostaApiException('The Bosta API key is not configured on this shipping method.');
            }
            // Bosta expects the raw key, not "Bearer <key>".
            $headers['Authorization'] = trim($apiKey);
        }

        $options = ['headers' => $headers, 'timeout' => $timeout ?? $this->timeout];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/') . $path, $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->error('Bosta API transport error', ['method' => $method, 'path' => $this->redact($path), 'exception' => $exception]);

            throw new BostaApiException(sprintf('Could not reach Bosta: %s', $exception->getMessage()), 0, null, true, $exception);
        }

        try {
            /** @var mixed $body */
            $body = '' === $content ? [] : json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new BostaApiException(sprintf('Bosta returned a non-JSON response (HTTP %d).', $statusCode), $statusCode, null, $statusCode >= 500, $exception);
        }
        $body = is_array($body) ? $body : [];

        if ($statusCode >= 400 || false === ($body['success'] ?? true)) {
            $exception = BostaApiException::fromResponse($statusCode, $body);
            $this->logger->warning('Bosta API error', ['method' => $method, 'path' => $this->redact($path), 'error' => $exception->describe()]);

            throw $exception;
        }

        return $body;
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function data(array $body): array
    {
        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            throw new BostaApiException('Bosta returned no delivery data.');
        }

        /** @var array<string, mixed> $result */
        $result = $data;

        return $result;
    }

    private function redact(string $path): string
    {
        return (string) preg_replace('/\?.*/', '?…', $path);
    }
}
