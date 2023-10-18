<?php

namespace Hiyori\Models\Common\Identifiers;

/**
 *
 */
abstract class Identifier implements IdentifierInterface
{
    /**
     * @var string|int
     */
    private string|int $id;

    /**
     * @return int|string
     */
    public function getId(): int|string
    {
        return $this->id;
    }

    /**
     * @param int|string $id
     * @return Identifier
     */
    public function setId(int|string $id): self
    {
        $this->id = $id;
        return $this;
    }
}