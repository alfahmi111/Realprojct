<?php

namespace App\Integrations;

/** Thrown when an integration has no provider chosen yet (owner tasks REQ-0565…REQ-0567): BLOCKED for production. */
class IntegrationNotConfigured extends \RuntimeException
{
}
