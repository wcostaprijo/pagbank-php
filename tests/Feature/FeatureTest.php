<?php

namespace PagBank\Tests\Feature;

use PHPUnit\Framework\TestCase;
use PagBank\PagBank;
use PagBank\Domain\Address;
use PagBank\Domain\Client;
use PagBank\Domain\Payment;
use PagBank\Domain\Payment\CreditCard;
use PagBank\Domain\Payment\Holder;
use PagBank\Domain\Payment\Pix;
use PagBank\Domain\Payment\Title;
use PagBank\Http\Responses\CreatePaymentResponse;
use PagBank\Http\Responses\GetPaymentResponse;
use PagBank\Http\Responses\CancelPaymentResponse;
use PagBank\Exceptions\PagBankException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;

class FeatureTest extends TestCase
{
    private PagBank $pagBank;
    private MockHandler $mockHandler;

    protected function setUp(): void
    {
        $this->pagBank = new PagBank('my-pagbank-token', true);
        
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $client = new GuzzleClient(['handler' => $handlerStack]);

        $this->pagBank->getClient()->setHttpClient($client);
    }

    public function testCreatePaymentPixSuccess()
    {
        $responsePayload = [
            'id' => 'ORD-123456',
            'reference_id' => 'REF-999',
            'created_at' => '2026-08-20T11:00:00Z',
            'customer' => [
                'tax_id' => '12345678909',
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ],
            'charges' => [
                [
                    'id' => 'CHG-8888',
                    'status' => 'WAITING',
                ]
            ],
            'qr_codes' => [
                [
                    'expiration_date' => '2026-08-21T11:00:00Z',
                    'amount' => ['value' => 15000],
                    'text' => 'some_qrcode_text',
                    'links' => [
                        ['rel' => 'QRCODE.PNG', 'href' => 'https://link.to/qrcode.png']
                    ]
                ]
            ]
        ];

        $this->mockHandler->append(new Response(200, [], json_encode($responsePayload)));

        $client = new Client('12345678909', 'John Doe', 'john@example.com');
        $pix = new Pix('2026-08-21T11:00:00Z', 150.00);
        $payment = new Payment(pix: $pix);

        $response = $this->pagBank->createPayment('REF-999', $client, $payment);

        $this->assertInstanceOf(CreatePaymentResponse::class, $response);
        $this->assertEquals('ORD-123456', $response->orderId());
        $this->assertEquals('REF-999', $response->reference());
        $this->assertEquals('WAITING', $response->status());
        $this->assertNotNull($response->payment()->pix);
        $this->assertEquals(150.00, $response->payment()->pix->amount);
        $this->assertEquals('some_qrcode_text', $response->payment()->pix->qrcode);
        $this->assertEquals('https://link.to/qrcode.png', $response->payment()->pix->qrcode_image);
    }

    public function testCreatePaymentCreditCardSuccess()
    {
        $responsePayload = [
            'id' => 'ORD-CARD-123',
            'reference_id' => 'REF-CARD',
            'created_at' => '2026-08-20T11:00:00Z',
            'customer' => [
                'tax_id' => '12345678909',
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
            ],
            'charges' => [
                [
                    'id' => 'CHG-CARD-1',
                    'status' => 'PAID',
                    'amount' => [
                        'value' => 20000,
                        'currency' => 'BRL',
                        'summary' => ['paid' => 20000, 'refunded' => 0],
                    ],
                    'payment_response' => [
                        'reference' => 'REF-AUTH',
                        'raw_data' => [
                            'authorization_code' => 'AUTH123',
                            'nsu' => 'NSU456',
                        ]
                    ],
                    'payment_method' => [
                        'type' => 'CREDIT_CARD',
                        'installments' => 2,
                        'soft_descriptor' => 'MINHALOJA',
                        'card' => [
                            'brand' => 'VISA',
                            'first_digits' => '411111',
                            'last_digits' => '1111',
                            'exp_month' => '12',
                            'exp_year' => '2028',
                            'holder' => [
                                'name' => 'Jane Doe',
                                'tax_id' => '12345678909',
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $this->mockHandler->append(new Response(200, [], json_encode($responsePayload)));

        $client = new Client('12345678909', 'Jane Doe', 'jane@example.com');
        $holder = new Holder('Jane Doe', '12345678909');
        $card = new CreditCard(
            amount: 200.00,
            description: 'Assinatura',
            soft_descriptor: 'MINHALOJA',
            holder: $holder,
            card_token: 'ENCRYPTED_CARD_TOKEN',
            installments: 2
        );
        $payment = new Payment(credit_card: $card);

        $response = $this->pagBank->createPayment('REF-CARD', $client, $payment);

        $this->assertInstanceOf(CreatePaymentResponse::class, $response);
        $this->assertEquals('ORD-CARD-123', $response->orderId());
        $this->assertEquals('PAID', $response->status());
        $this->assertNotNull($response->payment()->credit_card);
        $this->assertEquals(200.00, $response->payment()->credit_card->amount);
        $this->assertEquals('VISA', $response->payment()->credit_card->brand);
        $this->assertEquals('411111***1111', $response->payment()->credit_card->card_number);
        $this->assertEquals(2, $response->payment()->credit_card->installments);
    }

    public function testCreatePaymentBoletoSuccess()
    {
        $responsePayload = [
            'id' => 'ORD-BOL-123',
            'reference_id' => 'REF-BOL',
            'created_at' => '2026-08-20T11:00:00Z',
            'customer' => [
                'tax_id' => '12345678909',
                'name' => 'Carlos Silva',
                'email' => 'carlos@example.com',
            ],
            'charges' => [
                [
                    'id' => 'CHG-BOL-1',
                    'status' => 'WAITING',
                    'amount' => [
                        'value' => 8500,
                        'currency' => 'BRL',
                    ],
                    'payment_method' => [
                        'type' => 'BOLETO',
                        'boleto' => [
                            'due_date' => '2026-08-25',
                            'formatted_barcode' => '03399.12345 67890.123456 78901.234567 1 890000008500',
                            'holder' => [
                                'name' => 'Carlos Silva',
                                'tax_id' => '12345678909',
                                'address' => [
                                    'street' => 'Rua Exemplo',
                                    'number' => '100',
                                    'locality' => 'Centro',
                                    'city' => 'São Paulo',
                                    'region_code' => 'SP',
                                    'postal_code' => '01001000',
                                ]
                            ]
                        ]
                    ],
                    'links' => [
                        [
                            'rel' => 'PAY',
                            'media' => 'application/pdf',
                            'href' => 'https://link.to/boleto.pdf'
                        ]
                    ]
                ]
            ]
        ];

        $this->mockHandler->append(new Response(200, [], json_encode($responsePayload)));

        $client = new Client('12345678909', 'Carlos Silva', 'carlos@example.com');
        $address = new Address(
            street: 'Rua Exemplo',
            locality: 'Centro',
            city: 'São Paulo',
            state_code: 'SP',
            postal_code: '01001-000',
            number: '100'
        );
        $holder = new Holder('Carlos Silva', '12345678909', 'carlos@example.com', $address);
        $title = new Title(
            description: 'Mensalidade',
            amount: 85.00,
            due_date: '2026-08-25',
            holder: $holder,
            instruction_line_1: 'Não receber após vencimento'
        );
        $payment = new Payment(title: $title);

        $response = $this->pagBank->createPayment('REF-BOL', $client, $payment);

        $this->assertInstanceOf(CreatePaymentResponse::class, $response);
        $this->assertEquals('ORD-BOL-123', $response->orderId());
        $this->assertNotNull($response->payment()->title);
        $this->assertEquals(85.00, $response->payment()->title->amount);
        $this->assertEquals('https://link.to/boleto.pdf', $response->payment()->title->url);
        $this->assertEquals('03399.12345 67890.123456 78901.234567 1 890000008500', $response->payment()->title->bar_code);
    }

    public function testGetPaymentSuccess()
    {
        $responsePayload = [
            'id' => 'ORD-123456',
            'reference_id' => 'REF-999',
            'created_at' => '2026-08-20T11:00:00Z',
            'customer' => [
                'tax_id' => '12345678909',
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ],
            'charges' => [
                [
                    'id' => 'CHG-8888',
                    'status' => 'PAID',
                    'paid_at' => '2026-08-20T11:15:00Z',
                ]
            ],
            'qr_codes' => [
                [
                    'expiration_date' => '2026-08-21T11:00:00Z',
                    'amount' => ['value' => 15000],
                    'text' => 'some_qrcode_text',
                    'links' => [
                        ['rel' => 'QRCODE.PNG', 'href' => 'https://link.to/qrcode.png']
                    ]
                ]
            ]
        ];

        $this->mockHandler->append(new Response(200, [], json_encode($responsePayload)));

        $response = $this->pagBank->getPayment('ORD-123456');

        $this->assertInstanceOf(GetPaymentResponse::class, $response);
        $this->assertEquals('ORD-123456', $response->orderId());
        $this->assertEquals('PAID', $response->status());
        $this->assertEquals(150.00, $response->payment()->pix->amount);
    }

    public function testCancelPaymentSuccess()
    {
        $responsePayload = [
            'id' => 'CHG-8888',
            'status' => 'CANCELED',
            'amount' => [
                'value' => 15000,
                'summary' => [
                    'paid' => 15000,
                    'refunded' => 15000,
                ]
            ]
        ];

        $this->mockHandler->append(new Response(200, [], json_encode($responsePayload)));

        $response = $this->pagBank->cancelPayment('CHG-8888', 150.00);

        $this->assertInstanceOf(CancelPaymentResponse::class, $response);
        $this->assertTrue($response->canceled());
        $this->assertEquals('CHG-8888', $response->id());
        $this->assertTrue($response->fullRefunded());
        $this->assertEquals(150.00, $response->paid());
        $this->assertEquals(150.00, $response->refunded());
    }

    public function testRequestThrowsPagBankExceptionOnClientError()
    {
        $errorBody = json_encode([
            'error_messages' => [
                ['description' => 'Invalid document', 'code' => '40001']
            ]
        ]);
        
        $request = new Request('POST', 'orders');
        $response = new Response(400, [], $errorBody);
        
        $this->mockHandler->append(new ClientException('Bad Request', $request, $response));

        $client = new Client('12345678909', 'John Doe', 'john@example.com');
        $pix = new Pix('2026-08-21T11:00:00Z', 150.00);
        $payment = new Payment(pix: $pix);

        $this->expectException(PagBankException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('Invalid document');

        $this->pagBank->createPayment('REF-999', $client, $payment);
    }

    public function testWebhookProcessing()
    {
        $webhookData = [
            'id' => 'ORD-123456',
            'reference_id' => 'REF-999',
            'created_at' => '2026-08-20T11:00:00Z',
            'charges' => [
                [
                    'id' => 'CHG-8888',
                    'status' => 'paid',
                    'paid_at' => '2026-08-20T11:15:00Z',
                    'amount' => [
                        'value' => 15000,
                        'summary' => [
                            'paid' => 15000,
                            'refunded' => 0
                        ]
                    ]
                ]
            ],
            'qr_codes' => [
                [
                    'text' => 'some_qrcode_text',
                    'expiration_date' => '2026-08-21T11:00:00Z',
                    'amount' => [
                        'value' => 15000
                    ]
                ]
            ]
        ];

        $webhook = $this->pagBank->webhook($webhookData);

        $this->assertEquals('ORD-123456', $webhook->orderId());
        $this->assertEquals('REF-999', $webhook->reference());
        $this->assertEquals('paid', $webhook->status());
        $this->assertTrue($webhook->paid());
        $this->assertFalse($webhook->canceled());
        
        $payment = $webhook->payment();
        $this->assertNotNull($payment);
        $this->assertEquals('paid', $payment->status);
        $this->assertEquals(150.00, $payment->paid);
        $this->assertEquals(150.00, $payment->pix->amount);
    }
}
