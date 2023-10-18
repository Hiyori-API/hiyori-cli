<?php

namespace Hiyori\Models\Common\Identifiers;

interface IdentifierInterface
{
    public function getId(): string|int;
    public function setId(string|int $id): self;
}