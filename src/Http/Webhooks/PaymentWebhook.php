<?php

namespace PagBank\Http\Webhooks;

use Carbon\Carbon;
use PagBank\Domain\Address;
use PagBank\Domain\Client;
use PagBank\Domain\Item;
use PagBank\Domain\Payment;
use PagBank\Domain\Payment\CreditCard;
use PagBank\Domain\Payment\Holder;
use PagBank\Domain\Payment\Pix;
use PagBank\Domain\Payment\Title;
use PagBank\Domain\Phone;
use PagBank\Domain\Shipping;

class PaymentWebhook
{
    public function __construct(private array $data, private string $token) 
    { }

    public function raw(): array
    {
        return $this->data;
    }

    public function orderId(): ?string
    {
        return $this->data['id'] ?? null;
    }

    public function reference(): mixed
    {
        return $this->data['reference_id'] ?? null;
    }

    public function createdAt(): ?string
    {
        return $this->data['created_at'] ?? null;
    }

    public function status(): ?string
    {
        return $this->data['charges'][0]['status'] ?? null;
    }

    public function paid(): bool
    {
        return mb_strtolower($this->data['charges'][0]['status'] ?? '') === 'paid';
    }

    public function canceled(): bool
    {
        return mb_strtolower($this->data['charges'][0]['status'] ?? '') === 'canceled';
    }

    public function waiting(): bool
    {
        return mb_strtolower($this->data['charges'][0]['status'] ?? '') === 'waiting';
    }

    public function inAnalysis(): bool
    {
        return mb_strtolower($this->data['charges'][0]['status'] ?? '') === 'in_analysis';
    }

    public function declined(): bool
    {
        return mb_strtolower($this->data['charges'][0]['status'] ?? '') === 'declined';
    }

    public function authorized(): bool
    {
        return mb_strtolower($this->data['charges'][0]['status'] ?? '') === 'authorized';
    }

    public function shipping(): ?Shipping
    {
        return !empty($this->data['shipping']['address']) ? new Shipping(
            address: new Address(
                street: $this->data['shipping']['address']['street'] ?? '',
                number: $this->data['shipping']['address']['number'] ?? '',
                locality: $this->data['shipping']['address']['locality'] ?? '',
                city: $this->data['shipping']['address']['city'] ?? '',
                state_code: $this->data['shipping']['address']['region_code'] ?? '',
                postal_code: $this->data['shipping']['address']['postal_code'] ?? '',
            )
        ) : null;
    }

    public function items(): array
    {
        $items = [];
        foreach($this->data['items'] ?? [] as $item) {
            $items[] = new Item(
                name: $item['name'],
                quantity: (int) $item['quantity'],
                unit_amount: (double) bcdiv((string) $item['unit_amount'], '100', 2),
            );
        }

        return $items;
    }

    public function client(): ?Client
    {
        $phones = null;
        if(!empty($this->data['customer']['phones'])) {
            foreach($this->data['customer']['phones'] as $phone) {
                $phones[] = new Phone(
                    country: (int) $phone['country'],
                    area: (int) $phone['area'],
                    number: (int) $phone['number']
                );
            }
        }

        return !empty($this->data['customer']) ? new Client(
            document: $this->data['customer']['tax_id'] ?? '',
            name: $this->data['customer']['name'] ?? '',
            email: $this->data['customer']['email'] ?? '',
            phones: $phones
        ) : null;
    }

    public function payment(): ?Payment
    {
        $pix = null;
        if(!empty($this->data['qr_codes'])) {
            $qrLinkIndex = array_search('QRCODE.PNG', array_column($data['qr_codes'][0]['links'] ?? [], 'rel'));
            $pix = new Pix(
                expiration_date: !empty($this->data['qr_codes'][0]['expiration_date']) ? Carbon::parse($this->data['qr_codes'][0]['expiration_date'])->format('Y-m-d H:i:s') : '',
                amount: (float) bcdiv((string) ($this->data['qr_codes'][0]['amount']['value'] ?? 0), '100', 2),
                charge_id: $this->data['charges'][0]['id'] ?? null,
                qrcode: $this->data['qr_codes'][0]['text'] ?? null,
                qrcode_image: ($qrLinkIndex !== false) ? ($this->data['qr_codes'][0]['links'][$qrLinkIndex]['href'] ?? null) : null
            );
        }

        $title = null;
        if(!empty($this->data['charges'][0]['payment_method']['boleto'] ?? null)) {
            $pdfLinkIndex = array_search('application/pdf', array_column($this->data['charges'][0]['links'] ?? [], 'media'));
            $title = new Title(
                description: $this->data['charges'][0]['description'] ?? '',
                amount: (float) bcdiv((string) ($this->data['charges'][0]['amount']['value'] ?? 0), '100', 2),
                due_date: $this->data['charges'][0]['payment_method']['boleto']['due_date'] ?? '',
                charge_id: $this->data['charges'][0]['id'] ?? null,
                bar_code: $this->data['charges'][0]['payment_method']['boleto']['formatted_barcode'] ?? null,
                url: ($pdfLinkIndex !== false) ? ($this->data['charges'][0]['links'][$pdfLinkIndex]['href'] ?? null) : null,
                holder: new Holder(
                    name: $this->data['charges'][0]['payment_method']['boleto']['holder']['name'] ?? '',
                    email: $this->data['charges'][0]['payment_method']['boleto']['holder']['email'] ?? null,
                    document: $this->data['charges'][0]['payment_method']['boleto']['holder']['tax_id'] ?? '',
                    address: new Address(
                        street: $this->data['charges'][0]['payment_method']['boleto']['holder']['address']['street'] ?? '',
                        number: $this->data['charges'][0]['payment_method']['boleto']['holder']['address']['number'] ?? '',
                        locality: $this->data['charges'][0]['payment_method']['boleto']['holder']['address']['locality'] ?? '',
                        city: $this->data['charges'][0]['payment_method']['boleto']['holder']['address']['city'] ?? '',
                        state: $this->data['charges'][0]['payment_method']['boleto']['holder']['address']['region'] ?? null,
                        state_code: $this->data['charges'][0]['payment_method']['boleto']['holder']['address']['region_code'] ?? '',
                        postal_code: $this->data['charges'][0]['payment_method']['boleto']['holder']['address']['postal_code'] ?? '',
                    )
                ),
            );
        }

        $creditCard = null;
        if(!empty($this->data['charges'][0]['payment_method']['card'] ?? null)) {
            $creditCard = new CreditCard(
                amount: (float) bcdiv((string) ($this->data['charges'][0]['amount']['value'] ?? 0), '100', 2),
                description: $this->data['charges'][0]['description'] ?? '',
                soft_descriptor: $this->data['charges'][0]['payment_method']['soft_descriptor'] ?? '',
                charge_id: $this->data['charges'][0]['id'] ?? null,
                reference: $this->data['charges'][0]['payment_response']['reference'] ?? null,
                authorization_code: $this->data['charges'][0]['payment_response']['raw_data']['authorization_code'] ?? null,
                nsu: $this->data['charges'][0]['payment_response']['raw_data']['nsu'] ?? null,
                brand: $this->data['charges'][0]['payment_method']['card']['brand'] ?? null,
                card_number: ($this->data['charges'][0]['payment_method']['card']['first_digits'] ?? '').'***'.($this->data['charges'][0]['payment_method']['card']['last_digits'] ?? ''),
                expiration: ($this->data['charges'][0]['payment_method']['card']['exp_month'] ?? '').'/'.($this->data['charges'][0]['payment_method']['card']['exp_year'] ?? ''),
                holder: new Holder(
                    name: $this->data['charges'][0]['payment_method']['card']['holder']['name'] ?? '',
                    document: $this->data['charges'][0]['payment_method']['card']['holder']['tax_id'] ?? '',
                ),
            );
        }

        return !empty($this->data['charges']) ? new Payment(
            pix: $pix,
            title: $title,
            credit_card: $creditCard,
            paid_at: !empty($this->data['charges'][0]['paid_at'] ?? null) ? Carbon::parse($this->data['charges'][0]['paid_at'])->format('Y-m-d H:i:s') : null,
            status: $this->data['charges'][0]['status'] ?? null,
            full_refunded: ($this->data['charges'][0]['amount']['summary']['paid'] ?? 1) <= ($this->data['charges'][0]['amount']['summary']['refunded'] ?? 0),
            paid: !empty($this->data['charges'][0]['amount']['summary']['paid']) ? (float) bcdiv((string) $this->data['charges'][0]['amount']['summary']['paid'], '100', 2) : null,
            refunded: !empty($this->data['charges'][0]['amount']['summary']['refunded']) ? (float) bcdiv((string) $this->data['charges'][0]['amount']['summary']['refunded'], '100', 2) : null
        ) : null;
    }
}