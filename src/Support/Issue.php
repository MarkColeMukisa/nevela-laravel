<?php

namespace Nevela\Laravel\Support;

/** @internal A rejected query value. */
final class Issue
{
    public function __construct(public readonly string $message) {}
}
