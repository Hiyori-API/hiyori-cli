<?php

namespace Hiyori\Service;

use JMS\Serializer\SerializerBuilder;

class Serializer
{
    private \JMS\Serializer\Serializer $serializer;

    public function __construct(
        \JMS\Serializer\Serializer $serializer = null
    ) {
        $this->serializer = $serializer ?? SerializerBuilder::create()->build();
    }

    /**
     * @return \JMS\Serializer\Serializer
     */
    public function getSerializer(): \JMS\Serializer\Serializer
    {
        return $this->serializer;
    }
}
