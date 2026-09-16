<?php

namespace PagBank\Domain;

use PagBank\Exceptions\ValidationException;

class Shipping
{
    public function __construct(
        public Address $address,
    ) {
        if(!($this->address instanceof Address)) {
            throw new ValidationException('"address" deve ser uma instância da classe Address.');
        }
    }

    public function toArray(): array
    {
        return [
            'address' => $this->address->toArray(),
        ];
    }
}
