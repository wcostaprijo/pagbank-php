<?php

namespace PagBank\Domain\Payment;

use PagBank\Domain\Address;

class Holder
{
    public function __construct(
        public string $name,
        public string $document,
        public ?string $email = null,
        public ?Address $address = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'document' => $this->document,
            'email' => $this->email,
            'address' => $this->address?->toArray(),
        ]);
    }
}
