<?php

namespace App\Exceptions;

use RuntimeException;

class BillingUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El servicio de facturación no está disponible.');
    }
}
