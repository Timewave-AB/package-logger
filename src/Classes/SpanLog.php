<?php

namespace Timewave\LaravelLogger\Classes;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use Timewave\LaravelLogger\Enums\LogLevel;
use OpenTelemetry\API\Trace\Span;

$span = Span::getCurrent();

class SpanLog
{
    public SpanInterface $span;

    private CustomLogger $logger;

    public function __construct(string $name, array $context = [], ?SpanInterface $parentSpan = null, ?SpanKind $kind = null) {
        $this->logger = new CustomLogger();

        if ($parentSpan) {
            // BROKEN!!! / Lilleman 2024-11-01
            $parentSpanContext = Context::getCurrent();
        }

        $this->span = $this->logger->createSpan($name, $context, $kind, $parentSpanContext);
    }

    public function debug(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->logger->log(LogLevel::DEBUG, $message, $context, $exception, $this->span);
    }

    public function verbose(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->logger->log(LogLevel::VERBOSE, $message, $context, $exception, $this->span);
    }

    public function info(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->logger->log(LogLevel::INFO, $message, $context, $exception, $this->span);
    }

    public function warning(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->logger->log(LogLevel::WARNING, $message, $context, $exception, $this->span);
    }

    public function error(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->logger->log(LogLevel::ERROR, $message, $context, $exception, $this->span);
    }
}
