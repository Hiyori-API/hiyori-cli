<?php

namespace Hiyori\Service;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Prometheus\RenderTextFormat;

final class MetricsCollector
{
    private CollectorRegistry $registry;
    private array $counters = [];
    private array $histograms = [];
    private array $gauges = [];

    public function __construct()
    {
        $this->registry = new CollectorRegistry(new InMemory());
    }

    public function getRegistry(): CollectorRegistry
    {
        return $this->registry;
    }

    public function incrementCounter(string $name, array $labels = [], float $value = 1.0): void
    {
        $key = $name . ':' . json_encode($labels);
        
        if (!isset($this->counters[$key])) {
            $this->counters[$key] = $this->registry->getOrRegisterCounter(
                'hiyori',
                $name,
                'Counter for ' . $name,
                array_keys($labels)
            );
        }
        
        $this->counters[$key]->incBy($value, $labels);
    }

    public function observeHistogram(string $name, float $value, array $labels = []): void
    {
        $key = $name . ':' . json_encode($labels);
        
        if (!isset($this->histograms[$key])) {
            $this->histograms[$key] = $this->registry->getOrRegisterHistogram(
                'hiyori',
                $name,
                'Histogram for ' . $name,
                array_keys($labels),
                [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]
            );
        }
        
        $this->histograms[$key]->observe($value, $labels);
    }

    public function setGauge(string $name, float $value, array $labels = []): void
    {
        $key = $name . ':' . json_encode($labels);
        
        if (!isset($this->gauges[$key])) {
            $this->gauges[$key] = $this->registry->getOrRegisterGauge(
                'hiyori',
                $name,
                'Gauge for ' . $name,
                array_keys($labels)
            );
        }
        
        $this->gauges[$key]->set($value, $labels);
    }

    public function startTimer(string $name, array $labels = []): callable
    {
        $start = microtime(true);
        
        return function () use ($name, $labels, $start) {
            $duration = microtime(true) - $start;
            $this->observeHistogram($name, $duration, $labels);
            return $duration;
        };
    }

    public function render(): string
    {
        $renderer = new RenderTextFormat();
        return $renderer->render($this->registry->getMetricFamilySamples());
    }

    public function getContentType(): string
    {
        return RenderTextFormat::MIME_TYPE;
    }
}
