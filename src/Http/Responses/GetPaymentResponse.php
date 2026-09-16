<?php

namespace PagBank\Http\Responses;

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

class GetPaymentResponse
{
    public function __construct(private array $data) 
    { }

    public function raw(): array
    {
        return $this->data['raw'] ?? $this->data;
    }

    public function orderId(): ?string
    {
        return $this->data['id'] ?? null;
    }

    public function reference(): mixed
    {
        return $this->data['reference'] ?? null;
    }

    public function createdAt(): ?string
    {
        return $this->data['created_at'] ?? null;
    }

    public function status(): ?string
    {
        return $this->data['status'] ?? null;
    }

    public function shipping(): ?Shipping
    {
        return !empty($this->data['shipping']['address']) ? new Shipping(
            address: new Address(
                street: $this->data['shipping']['address']['street'] ?? '',
                number: $this->data['shipping']['address']['number'] ?? '',
                locality: $this->data['shipping']['address']['locality'] ?? '',
                city: $this->data['shipping']['address']['city'] ?? '',
                state_code: $this->data['shipping']['address']['state_code'] ?? '',
                postal_code: $this->data['shipping']['address']['postal_code'] ?? '',
            )
        ) : null;
    }

    public function items(): array
    {
        $items = [];
        foreach($this->data['items'] ?? [] as $item) {
            $items[] = new Item(
                name: $item['name'] ?? '',
                quantity: (int) ($item['quantity'] ?? 0),
                unit_amount: (float) ($item['unit_amount'] ?? 0),
            );
        }

        return $items;
    }

    public function client(): ?Client
    {
        $phones = null;
        if(!empty($this->data['client']['phones'])) {
            foreach($this->data['client']['phones'] as $phone) {
                $phones[] = new Phone(
                    country: (int) ($phone['country'] ?? 55),
                    area: (int) ($phone['area'] ?? 0),
                    number: (int) ($phone['number'] ?? 0)
                );
            }
        }

        return !empty($this->data['client']) ? new Client(
            document: $this->data['client']['document'] ?? '',
            name: $this->data['client']['name'] ?? '',
            email: $this->data['client']['email'] ?? '',
            phones: $phones
        ) : null;
    }

    public function payment(): ?Payment
    {
        $pix = null;
        if(!empty($this->data['payment']['pix'])) {
            $pix = new Pix(
                expiration_date: $this->data['payment']['pix']['expiration_date'] ?? '',
                amount: (float) ($this->data['payment']['pix']['amount'] ?? 0),
                charge_id: $this->data['payment']['pix']['charge_id'] ?? null,
                qrcode: $this->data['payment']['pix']['qrcode'] ?? null,
                qrcode_image: $this->data['payment']['pix']['qrcode_image'] ?? null
            );
        }

        $title = null;
        if(!empty($this->data['payment']['title'])) {
            $title = new Title(
                description: $this->data['payment']['title']['description'] ?? '',
                amount: (float) ($this->data['payment']['title']['amount'] ?? 0),
                due_date: $this->data['payment']['title']['due_date'] ?? '',
                charge_id: $this->data['payment']['title']['charge_id'] ?? null,
                bar_code: $this->data['payment']['title']['bar_code'] ?? null,
                url: $this->data['payment']['title']['url'] ?? null,
                holder: new Holder(
                    name: $this->data['payment']['title']['holder']['name'] ?? '',
                    email: $this->data['payment']['title']['holder']['email'] ?? '',
                    document: $this->data['payment']['title']['holder']['document'] ?? '',
                    address: new Address(
                        street: $this->data['payment']['title']['holder']['address']['street'] ?? '',
                        number: $this->data['payment']['title']['holder']['address']['number'] ?? '',
                        locality: $this->data['payment']['title']['holder']['address']['locality'] ?? '',
                        city: $this->data['payment']['title']['holder']['address']['city'] ?? '',
                        state: $this->data['payment']['title']['holder']['address']['state'] ?? '',
                        state_code: $this->data['payment']['title']['holder']['address']['state_code'] ?? '',
                        postal_code: $this->data['payment']['title']['holder']['address']['postal_code'] ?? '',
                    )
                ),
            );
        }

        $creditCard = null;
        if(!empty($this->data['payment']['credit_card'])) {
            $creditCard = new CreditCard(
                amount: (float) ($this->data['payment']['credit_card']['amount'] ?? 0),
                description: $this->data['payment']['credit_card']['description'] ?? '',
                soft_descriptor: $this->data['payment']['credit_card']['soft_descriptor'] ?? '',
                charge_id: $this->data['payment']['credit_card']['charge_id'] ?? null,
                reference: $this->data['payment']['credit_card']['reference'] ?? null,
                authorization_code: $this->data['payment']['credit_card']['authorization_code'] ?? null,
                nsu: $this->data['payment']['credit_card']['nsu'] ?? null,
                brand: $this->data['payment']['credit_card']['brand'] ?? null,
                card_number: $this->data['payment']['credit_card']['card_number'] ?? null,
                expiration: $this->data['payment']['credit_card']['expiration'] ?? null,
                installments: (int) ($this->data['payment']['credit_card']['installments'] ?? 1),
                holder: new Holder(
                    name: $this->data['payment']['credit_card']['holder']['name'] ?? '',
                    document: $this->data['payment']['credit_card']['holder']['document'] ?? '',
                ),
            );
        }

        return !empty($this->data['payment']) ? new Payment(
            pix: $pix,
            title: $title,
            credit_card: $creditCard,
            paid_at: $this->data['payment']['paid_at'] ?? null,
            status: $this->data['payment']['status'] ?? null,
            full_refunded: $this->data['payment']['full_refunded'] ?? null,
            paid: isset($this->data['payment']['paid']) ? (float) $this->data['payment']['paid'] : null,
            refunded: isset($this->data['payment']['refunded']) ? (float) $this->data['payment']['refunded'] : null,
        ) : null;
    }
}
