<?php

namespace ClubeDev\PagBank\Tests\Feature;

use PHPUnit\Framework\TestCase;
use ClubeDev\PagBank\PagBank;
use ClubeDev\PagBank\Domain\Client;
use ClubeDev\PagBank\Domain\Payment;
use ClubeDev\PagBank\Domain\Payment\Pix;
use ClubeDev\PagBank\Http\Responses\CreatePaymentResponse;
use ClubeDev\PagBank\Http\Responses\GetPaymentResponse;
use ClubeDev\PagBank\Http\Responses\CancelPaymentResponse;
use ClubeDev\PagBank\Exceptions\PagBankException;
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

    public function testCreatePaymentSuccess()
    {
        $responsePayload = [
            'id' => 'ORD-123456',
            'reference' => 'REF-999',
            'created_at' => '2026-08-20T11:00:00Z',
            'status' => 'waiting',
            'client' => [
                'document' => '12345678909',
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ],
            'payment' => [
                'pix' => [
                    'expiration_date' => '2026-08-21T11:00:00Z',
                    'amount' => 150.00,
                    'charge_id' => 'CHG-8888',
                    'qrcode' => 'some_qrcode_text',
                    'qrcode_image' => 'https://link.to/qrcode.png',
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
        $this->assertEquals('waiting', $response->status());
        $this->assertNotNull($response->payment()->pix);
        $this->assertEquals(150.00, $response->payment()->pix->amount);
    }

    public function testGetPaymentSuccess()
    {
        $responsePayload = [
            'id' => 'ORD-123456',
            'reference' => 'REF-999',
            'created_at' => '2026-08-20T11:00:00Z',
            'status' => 'paid',
            'client' => [
                'document' => '12345678909',
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ],
            'payment' => [
                'pix' => [
                    'expiration_date' => '2026-08-21T11:00:00Z',
                    'amount' => 150.00,
                    'charge_id' => 'CHG-8888',
                    'qrcode' => 'some_qrcode_text',
                    'qrcode_image' => 'https://link.to/qrcode.png',
                ],
                'paid_at' => '2026-08-20T11:15:00Z'
            ]
        ];

        $this->mockHandler->append(new Response(200, [], json_encode($responsePayload)));

        $response = $this->pagBank->getPayment('ORD-123456');

        $this->assertInstanceOf(GetPaymentResponse::class, $response);
        $this->assertEquals('ORD-123456', $response->orderId());
        $this->assertEquals('paid', $response->status());
    }

    public function testCancelPaymentSuccess()
    {
        $responsePayload = [
            'canceled' => true,
            'id' => 'CHG-8888',
            'full_refunded' => true,
            'paid' => 150.00,
            'refunded' => 150.00,
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
        $errorBody = json_encode(['error' => 'Invalid document']);
        
        // Mock a ClientException which Guzzle throws on 400 Bad Request
        $request = new Request('POST', 'libraries/pagbank/payment');
        $response = new Response(400, [], $errorBody);
        
        $this->mockHandler->append(new ClientException('Bad Request', $request, $response));

        $client = new Client('12345678909', 'John Doe', 'john@example.com');
        $pix = new Pix('2026-08-21T11:00:00Z', 150.00);
        $payment = new Payment(pix: $pix);

        $this->expectException(PagBankException::class);
        $this->expectExceptionCode(400);

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
