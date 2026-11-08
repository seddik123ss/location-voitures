<?php

namespace Rental\Eureka;

/** Eureka est injoignable, ou aucune instance UP n'existe pour le service demandé. À traduire en 503. */
final class EurekaUnavailable extends \RuntimeException
{
}
