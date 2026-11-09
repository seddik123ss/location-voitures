<?php

namespace App\Messaging;

/** Message non confirmé par RabbitMQ : broker injoignable, file absente (message retourné) ou refus (nack). */
final class PublishFailed extends \RuntimeException
{
}
