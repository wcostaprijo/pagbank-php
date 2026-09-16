<?php

namespace PagBank;

use Carbon\Carbon;
use PagBank\Domain\Client;
use PagBank\Domain\Payment;
use PagBank\Domain\Shipping;
use PagBank\Http\PagBankClient;
use PagBank\Http\Requests\CreatePaymentRequest;
use PagBank\Http\Responses\CancelPaymentResponse;
use PagBank\Http\Responses\CreatePaymentResponse;
use PagBank\Http\Responses\GetPaymentResponse;
use PagBank\Http\Webhooks\PaymentWebhook;

class PagBank
{
    protected PagBankClient $client;
    private string $pagBankToken;

    public function __construct(string $pagBankToken, bool $sandbox = false)
    {
        $this->pagBankToken = $pagBankToken;
        $this->client = new PagBankClient($pagBankToken, $sandbox);
    }

    public function createPayment(mixed $reference, Client $client, Payment $payment, ?array $items = null, ?Shipping $shipping = null, ?string $webhook_url = null): CreatePaymentResponse
    {
        $request = new CreatePaymentRequest(
            reference: $reference,
            client: $client,
            payment: $payment,
            items: $items,
            shipping: $shipping,
            webhook_url: $webhook_url
        );

        $response = $this->client->post('orders', $request->toPagBankPayload());
        return new CreatePaymentResponse($this->prepareResponse($response));
    }

    public function getPayment(mixed $orderId): GetPaymentResponse
    {
        $response = $this->client->get("orders/{$orderId}");
        return new GetPaymentResponse($this->prepareResponse($response));
    }

    public function cancelPayment(mixed $charge_id, float $amount): CancelPaymentResponse
    {
        $response = $this->client->post("charges/{$charge_id}/cancel", [
            'amount' => [
                'value' => (int) bcmul((string) $amount, '100', 0),
            ],
        ]);

        $cancelData = [
            'canceled' => true,
            'id' => $response['id'] ?? $charge_id,
            'full_refunded' => ($response['amount']['summary']['paid'] ?? 1) <= ($response['amount']['summary']['refunded'] ?? 0),
            'paid' => (double) bcdiv((string) ($response['amount']['summary']['paid'] ?? 0), '100', 2),
            'refunded' => (double) bcdiv((string) ($response['amount']['summary']['refunded'] ?? 0), '100', 2),
            'raw' => $response,
        ];

        return new CancelPaymentResponse($cancelData);
    }

    public function webhook(array $data): PaymentWebhook
    {
        return new PaymentWebhook($data, $this->pagBankToken);
    }

    public function getClient(): PagBankClient
    {
        return $this->client;
    }

    protected function prepareResponse(array $data): array
    {
        if (!empty($data['client']) && !empty($data['reference']) && empty($data['customer']) && empty($data['reference_id'])) {
            return $data;
        }

        $result = [];
        $result['id'] = $data['id'] ?? null;
        $result['reference'] = $data['reference_id'] ?? ($data['reference'] ?? null);
        $result['created_at'] = !empty($data['created_at']) ? Carbon::parse($data['created_at'])->format('Y-m-d H:i:s') : null;
        $result['status'] = $data['charges'][0]['status'] ?? ($data['status'] ?? 'WAITING');

        $result['client'] = [
            'name' => $data['customer']['name'] ?? ($data['client']['name'] ?? null),
            'email' => $data['customer']['email'] ?? ($data['client']['email'] ?? null),
            'document' => $data['customer']['tax_id'] ?? ($data['client']['document'] ?? null),
        ];

        $phones = $data['customer']['phones'] ?? ($data['client']['phones'] ?? []);
        if(!empty($phones)) {
            $result['client']['phones'] = array_map(function($item) {
                if (is_array($item)) {
                    unset($item['type']);
                }
                return $item;
            }, $phones);
        }

        $shippingAddr = $data['shipping']['address'] ?? null;
        if(!empty($shippingAddr)) {
            $result['shipping']['address'] = [
                'street' => $shippingAddr['street'] ?? null,
                'number' => $shippingAddr['number'] ?? null,
                'complement' => $shippingAddr['complement'] ?? null,
                'locality' => $shippingAddr['locality'] ?? null,
                'city' => $shippingAddr['city'] ?? null,
                'state_code' => $shippingAddr['region_code'] ?? ($shippingAddr['state_code'] ?? null),
                'postal_code' => $shippingAddr['postal_code'] ?? null,
            ];
        }

        if(!empty($data['items'])) {
            $result['items'] = [];
            foreach($data['items'] as $item) {
                $unitAmount = $item['unit_amount'] ?? 0;
                $calculatedAmount = is_int($unitAmount) || (is_numeric($unitAmount) && $unitAmount >= 100 && floor($unitAmount) == $unitAmount)
                    ? (double) bcdiv((string) $unitAmount, '100', 2)
                    : (double) $unitAmount;

                $result['items'][] = [
                    'name' => $item['name'] ?? null,
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'unit_amount' => $calculatedAmount,
                ];
            }
        }

        if(!empty($data['qr_codes'])) {
            $qrLinks = $data['qr_codes'][0]['links'] ?? [];
            $qrLinkIndex = array_search('QRCODE.PNG', array_column($qrLinks, 'rel'));
            $qrAmount = $data['qr_codes'][0]['amount']['value'] ?? ($data['payment']['pix']['amount'] ?? 0);
            $parsedAmount = is_int($qrAmount) || (is_numeric($qrAmount) && $qrAmount >= 100 && floor($qrAmount) == $qrAmount)
                ? (double) bcdiv((string) $qrAmount, '100', 2)
                : (double) $qrAmount;

            $result['payment']['pix']['charge_id'] = $data['charges'][0]['id'] ?? null;
            $result['payment']['pix']['expiration_date'] = !empty($data['qr_codes'][0]['expiration_date']) ? Carbon::parse($data['qr_codes'][0]['expiration_date'])->format('Y-m-d H:i:s') : null;
            $result['payment']['pix']['amount'] = $parsedAmount;
            $result['payment']['pix']['qrcode'] = $data['qr_codes'][0]['text'] ?? null;
            $result['payment']['pix']['qrcode_image'] = ($qrLinkIndex !== false) ? ($qrLinks[$qrLinkIndex]['href'] ?? null) : null;
        }

        $paidAt = $data['charges'][0]['paid_at'] ?? ($data['payment']['paid_at'] ?? null);
        $result['payment']['paid_at'] = $paidAt ? Carbon::parse($paidAt)->format('Y-m-d H:i:s') : null;

        if(!empty($data['charges'][0]['payment_method']['boleto'] ?? null)) {
            $boleto = $data['charges'][0]['payment_method']['boleto'];
            $charge = $data['charges'][0];
            $links = $charge['links'] ?? [];
            $pdfLinkIndex = array_search('application/pdf', array_column($links, 'media'));
            $titleAmount = $charge['amount']['value'] ?? 0;
            $parsedTitleAmount = (double) bcdiv((string) $titleAmount, '100', 2);

            $result['payment']['title']['charge_id'] = $charge['id'] ?? null;
            $result['payment']['title']['bar_code'] = $boleto['formatted_barcode'] ?? null;
            $result['payment']['title']['url'] = ($pdfLinkIndex !== false) ? ($links[$pdfLinkIndex]['href'] ?? null) : null;
            $result['payment']['title']['description'] = $charge['description'] ?? null;
            $result['payment']['title']['amount'] = $parsedTitleAmount;
            $result['payment']['title']['due_date'] = $boleto['due_date'] ?? null;

            if(!empty($boleto['holder'])) {
                $holder = $boleto['holder'];
                $result['payment']['title']['holder']['name'] = $holder['name'] ?? null;
                $result['payment']['title']['holder']['email'] = $holder['email'] ?? null;
                $result['payment']['title']['holder']['document'] = $holder['tax_id'] ?? null;

                if(!empty($holder['address'])) {
                    $result['payment']['title']['holder']['address']['street'] = $holder['address']['street'] ?? null;
                    $result['payment']['title']['holder']['address']['number'] = $holder['address']['number'] ?? null;
                    $result['payment']['title']['holder']['address']['locality'] = $holder['address']['locality'] ?? null;
                    $result['payment']['title']['holder']['address']['city'] = $holder['address']['city'] ?? null;
                    $result['payment']['title']['holder']['address']['state'] = $holder['address']['region'] ?? ($holder['address']['state'] ?? null);
                    $result['payment']['title']['holder']['address']['state_code'] = $holder['address']['region_code'] ?? ($holder['address']['state_code'] ?? null);
                    $result['payment']['title']['holder']['address']['postal_code'] = $holder['address']['postal_code'] ?? null;
                }
            }
        }

        if(!empty($data['charges'][0]['payment_method']['card'] ?? null)) {
            $charge = $data['charges'][0];
            $card = $charge['payment_method']['card'];
            $cardAmount = $charge['amount']['value'] ?? 0;
            $parsedCardAmount = (double) bcdiv((string) $cardAmount, '100', 2);

            $result['payment']['credit_card']['charge_id'] = $charge['id'] ?? null;
            $result['payment']['credit_card']['description'] = $charge['description'] ?? null;
            $result['payment']['credit_card']['amount'] = $parsedCardAmount;
            $result['payment']['credit_card']['soft_descriptor'] = $charge['payment_method']['soft_descriptor'] ?? null;
            $result['payment']['credit_card']['reference'] = $charge['payment_response']['reference'] ?? null;
            $result['payment']['credit_card']['authorization_code'] = $charge['payment_response']['raw_data']['authorization_code'] ?? null;
            $result['payment']['credit_card']['nsu'] = $charge['payment_response']['raw_data']['nsu'] ?? null;
            $result['payment']['credit_card']['installments'] = $charge['payment_method']['installments'] ?? 1;
            $result['payment']['credit_card']['brand'] = $card['brand'] ?? null;
            $result['payment']['credit_card']['card_number'] = ($card['first_digits'] ?? '') . '***' . ($card['last_digits'] ?? '');
            $result['payment']['credit_card']['expiration'] = ($card['exp_month'] ?? '') . '/' . ($card['exp_year'] ?? '');
            $result['payment']['credit_card']['holder']['name'] = $card['holder']['name'] ?? null;
            $result['payment']['credit_card']['holder']['document'] = $card['holder']['tax_id'] ?? null;
        }

        $result['payment']['status'] = $data['charges'][0]['status'] ?? ($data['payment']['status'] ?? null);

        if(!empty($data['charges'][0]['amount']['summary'] ?? null)) {
            $summary = $data['charges'][0]['amount']['summary'];
            $result['payment']['full_refunded'] = ($summary['paid'] ?? 1) <= ($summary['refunded'] ?? 0);
            $result['payment']['paid'] = (double) bcdiv((string) ($summary['paid'] ?? 0), '100', 2);
            $result['payment']['refunded'] = (double) bcdiv((string) ($summary['refunded'] ?? 0), '100', 2);
        }

        $result['raw'] = $data;

        return $result;
    }
}
