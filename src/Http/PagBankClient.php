<?php

namespace PagBank\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use PagBank\Exceptions\PagBankException;

class PagBankClient
{
    protected Client $http;

    public function __construct(string $pagBankToken, bool $sandbox = false)
    {
        $baseUri = $sandbox ? 'https://sandbox.api.pagseguro.com' : 'https://api.pagseguro.com';

        $this->http = new Client([
            'base_uri' => $baseUri,
            'timeout'  => 30,
            'headers'  => [
                'Authorization' => 'Bearer ' . $pagBankToken,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ]);
    }

    public function post(string $endpoint, array $data): array
    {
        try {
            $response = $this->http->post($endpoint, ['json' => $data]);
            $decoded = json_decode((string) $response->getBody(), true) ?? [];
            $this->checkResponseErrors($decoded, $response->getStatusCode());
            return $decoded;
        } catch (BadResponseException $e) {
            $this->handleException($e);
        } catch (GuzzleException $e) {
            throw new PagBankException($e->getMessage(), $e->getCode(), [], $e);
        }

        return [];
    }

    public function get(string $endpoint, array $data = []): array
    {
        try {
            $response = $this->http->get($endpoint, ['query' => $data]);
            $decoded = json_decode((string) $response->getBody(), true) ?? [];
            $this->checkResponseErrors($decoded, $response->getStatusCode());
            return $decoded;
        } catch (BadResponseException $e) {
            $this->handleException($e);
        } catch (GuzzleException $e) {
            throw new PagBankException($e->getMessage(), $e->getCode(), [], $e);
        }

        return [];
    }

    public function delete(string $endpoint, array $data = []): array
    {
        try {
            $response = $this->http->delete($endpoint, ['query' => $data]);
            $decoded = json_decode((string) $response->getBody(), true) ?? [];
            $this->checkResponseErrors($decoded, $response->getStatusCode());
            return $decoded;
        } catch (BadResponseException $e) {
            $this->handleException($e);
        } catch (GuzzleException $e) {
            throw new PagBankException($e->getMessage(), $e->getCode(), [], $e);
        }

        return [];
    }

    public function setHttpClient(Client $client): void
    {
        $this->http = $client;
    }

    private function checkResponseErrors(array $decoded, int $statusCode): void
    {
        if (!empty($decoded['error_messages'])) {
            $message = $decoded['error_messages'][0]['description'] ?? 'Erro inesperado da API PagBank.';
            throw new PagBankException($message, $statusCode, $decoded['error_messages']);
        }
    }

    private function handleException(BadResponseException $e): void
    {
        $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : $e->getCode();
        $body = $e->getResponse() ? (string) $e->getResponse()->getBody() : '';
        $decoded = json_decode($body, true);

        if (is_array($decoded) && !empty($decoded['error_messages'])) {
            $message = $decoded['error_messages'][0]['description'] ?? 'Erro inesperado da API PagBank.';
            throw new PagBankException($message, $statusCode, $decoded['error_messages'], $e);
        }

        $message = !empty($decoded['message']) ? $decoded['message'] : ($body ?: $e->getMessage());
        throw new PagBankException($message, $statusCode, is_array($decoded) ? $decoded : [], $e);
    }
}
