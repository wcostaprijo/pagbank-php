<?php

namespace PagBank\Http\Responses;

class CancelPaymentResponse
{
    public function __construct(private array $data) 
    { }

    public function raw(): array
    {
        return $this->data['raw'] ?? $this->data;
    }

    public function canceled(): bool
    {
        return (bool) ($this->data['canceled'] ?? false);
    }

    public function id(): mixed
    {
        return $this->data['id'] ?? null;
    }

    public function fullRefunded(): bool
    {
        return (bool) ($this->data['full_refunded'] ?? false);
    }

    public function paid(): float
    {
        return (float) ($this->data['paid'] ?? 0);
    }

    public function refunded(): float
    {
        return (float) ($this->data['refunded'] ?? 0);
    }
}
