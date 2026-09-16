<?php

namespace PagBank\Http\Requests;

use Carbon\Carbon;
use PagBank\Domain\Client;
use PagBank\Domain\Item;
use PagBank\Domain\Payment;
use PagBank\Domain\Shipping;
use PagBank\Exceptions\ValidationException;

class CreatePaymentRequest
{
    public function __construct(
        private mixed $reference,
        private Client $client,
        private Payment $payment,
        private ?array $items = null,
        private ?Shipping $shipping = null,
        private ?string $webhook_url = null
    ) {
        foreach($this->items ?? [] as $item) {
            if(!($item instanceof Item)) {
                throw new ValidationException('Cada item em "items" deve ser uma instância da classe Item.');
            }
        }
    }

    public function toPagBankPayload(): array
    {
        $clientArray = $this->client->toArray();
        $paymentArray = $this->payment->toArray();
        $shippingArray = $this->shipping?->toArray();

        $body = [];
        $body['reference_id'] = (string) $this->reference;

        $body['customer'] = [
            'name' => $clientArray['name'] ?? '',
            'email' => $clientArray['email'] ?? '',
            'tax_id' => str_replace(['.', '/', '-', ' '], '', $clientArray['document'] ?? ''),
        ];

        if(!empty($clientArray['phones'])) {
            $body['customer']['phones'] = [];
            foreach($clientArray['phones'] as $phone) {
                $phoneArray = is_array($phone) ? $phone : $phone->toArray();
                $body['customer']['phones'][] = [
                    'type' => 'MOBILE',
                    'country' => (int) $phoneArray['country'],
                    'area' => (int) $phoneArray['area'],
                    'number' => (int) $phoneArray['number'],
                ];
            }
        }

        if(!empty($this->items)) {
            $body['items'] = [];
            foreach($this->items as $item) {
                $itemArray = $item->toArray();
                $body['items'][] = [
                    'quantity' => (int) $itemArray['quantity'],
                    'name' => (string) $itemArray['name'],
                    'unit_amount' => (int) bcmul((string) $itemArray['unit_amount'], '100', 0),
                ];
            }
        }

        if(!empty($shippingArray['address'])) {
            $addr = $shippingArray['address'];
            $body['shipping'] = [
                'address' => [
                    'street' => $addr['street'] ?? '',
                    'number' => $addr['number'] ?? '01',
                    'complement' => $addr['complement'] ?? '',
                    'locality' => $addr['locality'] ?? '',
                    'city' => $addr['city'] ?? '',
                    'region_code' => $addr['state_code'] ?? '',
                    'postal_code' => str_replace(['-', ' ', '.'], '', $addr['postal_code'] ?? ''),
                    'country' => 'BRA',
                ]
            ];
        }

        if(!empty($this->webhook_url)) {
            $body['notification_urls'] = [$this->webhook_url];
        }

        if(!empty($paymentArray['pix'])) {
            $pix = $paymentArray['pix'];
            $body['qr_codes'] = [
                [
                    'expiration_date' => Carbon::parse($pix['expiration_date'])->format('c'),
                    'amount' => [
                        'value' => (int) bcmul((string) $pix['amount'], '100', 0),
                    ],
                ]
            ];
        }

        if(!empty($paymentArray['title'])) {
            $title = $paymentArray['title'];
            $charge = [
                'reference_id' => (string) $this->reference,
                'description' => $title['description'] ?? '',
                'amount' => [
                    'value' => (int) bcmul((string) ($title['amount'] ?? 0), '100', 0),
                    'currency' => 'BRL',
                ],
                'payment_method' => [
                    'type' => 'BOLETO',
                    'boleto' => [
                        'due_date' => $title['due_date'],
                    ],
                ],
            ];

            $instructionLines = [];
            if(!empty($title['instruction_line_1'])) {
                $instructionLines['line_1'] = $title['instruction_line_1'];
            }
            if(!empty($title['instruction_line_2'])) {
                $instructionLines['line_2'] = $title['instruction_line_2'];
            }
            if(!empty($title['instruction_lines'])) {
                $instructionLines = array_merge($instructionLines, $title['instruction_lines']);
            }
            if(!empty($instructionLines)) {
                $charge['payment_method']['boleto']['instruction_lines'] = $instructionLines;
            }

            if(!empty($title['holder'])) {
                $holder = $title['holder'];
                $holderAddr = $holder['address'] ?? [];
                $charge['payment_method']['boleto']['holder'] = [
                    'name' => $holder['name'] ?? '',
                    'email' => $holder['email'] ?? '',
                    'tax_id' => str_replace(['.', '/', '-', ' '], '', $holder['document'] ?? ''),
                    'address' => [
                        'street' => $holderAddr['street'] ?? '',
                        'number' => $holderAddr['number'] ?? '01',
                        'locality' => $holderAddr['locality'] ?? '',
                        'city' => $holderAddr['city'] ?? '',
                        'region' => $holderAddr['state'] ?? '',
                        'region_code' => $holderAddr['state_code'] ?? '',
                        'postal_code' => str_replace(['-', ' ', '.'], '', $holderAddr['postal_code'] ?? ''),
                        'country' => 'BRA',
                    ],
                ];
            }

            $body['charges'] = [$charge];
        }

        if(!empty($paymentArray['credit_card'])) {
            $card = $paymentArray['credit_card'];
            $charge = [
                'reference_id' => (string) $this->reference,
                'description' => $card['description'] ?? '',
                'amount' => [
                    'value' => (int) bcmul((string) ($card['amount'] ?? 0), '100', 0),
                    'currency' => 'BRL',
                ],
                'payment_method' => [
                    'type' => 'CREDIT_CARD',
                    'installments' => $card['installments'] ?? 1,
                    'capture' => true,
                    'soft_descriptor' => $card['soft_descriptor'] ?? '',
                    'card' => [
                        'encrypted' => $card['card_token'] ?? '',
                        'store' => false,
                    ],
                ],
            ];

            if(!empty($card['holder'])) {
                $holder = is_array($card['holder']) ? $card['holder'] : (method_exists($card['holder'], 'toArray') ? $card['holder']->toArray() : []);
                $charge['payment_method']['card']['holder'] = [
                    'name' => $holder['name'] ?? '',
                    'email' => $holder['email'] ?? '',
                    'tax_id' => str_replace(['.', '/', '-', ' '], '', $holder['document'] ?? ''),
                ];
            }

            $body['charges'] = [$charge];
        }

        return $body;
    }

    public function toArray(): array
    {
        return $this->toPagBankPayload();
    }
}
