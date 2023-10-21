<?php

namespace Hiyori\Service;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ConsoleFactory
{
    private SymfonyStyle $console;

    public static function create(): self
    {
        $self = new self();
        $self->console = new SymfonyStyle(new ArgvInput(), new ConsoleOutput());
        return $self;
    }

    /**
     * @return SymfonyStyle
     */
    public function io(): SymfonyStyle
    {
        return $this->console;
    }
}
