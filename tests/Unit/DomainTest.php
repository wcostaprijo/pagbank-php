<?php

namespace ClubeDev\PagBank\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClubeDev\PagBank\Domain\Client;
use ClubeDev\PagBank\Domain\Phone;
use ClubeDev\PagBank\Domain\Item;
use ClubeDev\PagBank\Domain\Address;
use ClubeDev\PagBank\Domain\Shipping;
use ClubeDev\PagBank\Domain\Payment;
use ClubeDev\PagBank\Domain\Payment\Pix;
use ClubeDev\PagBank\Domain\Payment\Title;
use ClubeDev\PagBank\Domain\Payment\CreditCard;
use ClubeDev\PagBank\Domain\Payment\Holder;
use ClubeDev\PagBank\Exceptions\ValidationException;

class DomainTest extends TestCase
{
    public function testPhoneToArray()
    {
        $phone = new Phone(55, 11, 999999999);
        $expected = [
            'country' => 55,
            'area' => 11,
            'number' => 999999999,
        ];
        $this->assertEquals($expected, $phone->toArray());
    }

    public function testClientValidationThrowsExceptionOnInvalidPhone()
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cada item em "phones" deve ser uma instância da classe Phone.');

        new Client(
            document: '12345678909',
            name: 'John Doe',
            email: 'john@example.com',
            phones: ['invalid_phone_object']
        );
    }

    public function testClientToArray()
    {
        $phone = new Phone(55, 11, 999999999);
        $client = new Client(
            document: '12345678909',
            name: 'John Doe',
            email: 'john@example.com',
            phones: [$phone]
        );

        $array = $client->toArray();
        $this->assertEquals('12345678909', $array['document']);
        $this->assertEquals('John Doe', $array['name']);
        $this->assertEquals('john@example.com', $array['email']);
        $this->assertCount(1, $array['phones']);
        $this->assertSame($phone, $array['phones'][0]);
    }

    public function testItemToArray()
    {
        $item = new Item(
            name: 'Produto Teste',
            quantity: 2,
            unit_amount: 10.50
        );

        $expected = [
            'name' => 'Produto Teste',
            'quantity' => 2,
            'unit_amount' => 10.50,
        ];
        $this->assertEquals($expected, $item->toArray());
    }

    public function testAddressToArray()
    {
        $address = new Address(
            street: 'Av Paulista',
            locality: 'Bela Vista',
            city: 'São Paulo',
            state_code: 'SP',
            postal_code: '01311000',
            number: '1000',
            complement: 'Apto 12'
        );

        $array = $address->toArray();
        $this->assertEquals('Av Paulista', $array['street']);
        $this->assertEquals('1000', $array['number']);
        $this->assertEquals('Apto 12', $array['complement']);
        $this->assertEquals('Bela Vista', $array['locality']);
        $this->assertEquals('São Paulo', $array['city']);
        $this->assertEquals('SP', $array['state_code']);
        $this->assertEquals('01311000', $array['postal_code']);
    }

    public function testShippingToArray()
    {
        $address = new Address(
            street: 'Av Paulista',
            locality: 'Bela Vista',
            city: 'São Paulo',
            state_code: 'SP',
            postal_code: '01311000'
        );
        $shipping = new Shipping($address);

        $array = $shipping->toArray();
        $this->assertArrayHasKey('address', $array);
        $this->assertEquals($address->toArray(), $array['address']);
    }

    public function testPaymentThrowsExceptionWithoutPaymentMethod()
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Informe uma forma de pagamento.');

        new Payment();
    }

    public function testPaymentWithPixToArray()
    {
        $pix = new Pix(expiration_date: '2026-12-31T23:59:59-03:00', amount: 150.00);
        $payment = new Payment(pix: $pix);

        $array = $payment->toArray();
        $this->assertArrayHasKey('pix', $array);
        $this->assertEquals('2026-12-31T23:59:59-03:00', $array['pix']['expiration_date']);
    }

    public function testPaymentWithBoletoTitleToArray()
    {
        $address = new Address(
            street: 'Av Paulista',
            locality: 'Bela Vista',
            city: 'São Paulo',
            state_code: 'SP',
            postal_code: '01311000'
        );
        $holder = new Holder(
            name: 'John Doe',
            document: '12345678909',
            address: $address
        );
        $title = new Title(
            description: 'Pagamento Teste',
            amount: 150.00,
            due_date: '2026-12-31',
            holder: $holder
        );
        $payment = new Payment(title: $title);

        $array = $payment->toArray();
        $this->assertArrayHasKey('title', $array);
        $this->assertEquals('2026-12-31', $array['title']['due_date']);
        $this->assertEquals('Pagamento Teste', $array['title']['description']);
    }

    public function testPaymentWithCreditCardToArray()
    {
        $address = new Address(
            street: 'Av Paulista',
            locality: 'Bela Vista',
            city: 'São Paulo',
            state_code: 'SP',
            postal_code: '01311000'
        );
        $holder = new Holder(
            name: 'John Doe',
            document: '12345678909',
            address: $address
        );
        $creditCard = new CreditCard(
            amount: 150.00,
            description: 'Compra Teste',
            soft_descriptor: 'Loja Teste',
            holder: $holder,
            card_token: 'card_token_hash_here',
            installments: 1
        );
        $payment = new Payment(credit_card: $creditCard);

        $array = $payment->toArray();
        $this->assertArrayHasKey('credit_card', $array);
        $this->assertEquals('card_token_hash_here', $array['credit_card']['card_token']);
        $this->assertEquals(1, $array['credit_card']['installments']);
    }
}
