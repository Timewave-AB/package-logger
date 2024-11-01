<?php

namespace Timewave\LaravelLogger\Classes;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeys;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Component\Console\Output\ConsoleOutput;
use Timewave\LaravelLogger\Enums\LogFormat;
use Timewave\LaravelLogger\Enums\LogFormatTextDelimiter;
use Timewave\LaravelLogger\Enums\LogLevel;

class CustomLogger
{
    private const OTEL_INSTRUMENT_VERSION = '1.0.0';

    private const OTEL_SCHEMA_URL = 'https://opentelemetry.io/schemas/1.9.0';

    private ConsoleOutput $output;

    private LogLevel $logLevel;

    private LogFormat $logFormat;

    private LogFormatTextDelimiter $logFormatTextDelimiter;

    private LoggerInterface $otlpLogger;

    private TracerInterface $tracer;

    private bool $otlpEnabled = false;

    public function __construct() {
        $this->output = new ConsoleOutput;

        $this->logLevel = match (strtoupper(Config::get('logging.level', 'debug'))) {
            'ERROR' => LogLevel::ERROR,
            'WARNING' => LogLevel::WARNING,
            'INFO' => LogLevel::INFO,
            'VERBOSE' => LogLevel::VERBOSE,
            default => LogLevel::DEBUG,
        };

        $this->logFormat = LogFormat::tryFrom(Config::get('logging.format', LogFormat::TEXT->value)) ?? LogFormat::TEXT;

        $this->logFormatTextDelimiter = LogFormatTextDelimiter::tryFrom(Config::get('logging.textDelimiter', LogFormatTextDelimiter::TAB->value)) ?? LogFormatTextDelimiter::TAB;

        $otlpCollectorEndpoint = Config::get('logging.otlpCollectorEndpoint');

        if ($otlpCollectorEndpoint) {
            $resource = ResourceInfoFactory::defaultResource();
            $transportFactory = new PsrTransportFactory();
            $logsEndpoint = rtrim($otlpCollectorEndpoint, '/') . '/v1/logs';
            $logsTransport = $transportFactory->create(
                $logsEndpoint,
                'application/json',
                [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]
            );

            $logsExporter = new LogsExporter($logsTransport);
            $loggerProvider = new LoggerProvider(
                new SimpleLogRecordProcessor($logsExporter),
                new InstrumentationScopeFactory(new AttributesFactory()),
                $resource
            );

            $this->otlpLogger = $loggerProvider->getLogger(
                name: 'otel-logger',
                version: SELF::OTEL_INSTRUMENT_VERSION,
                schemaUrl: SELF::OTEL_SCHEMA_URL,
            );

            $tracesEndpoint = rtrim($otlpCollectorEndpoint, '/') . '/v1/traces';
            $tracesTransport = $transportFactory->create(
                $tracesEndpoint,
                'application/json',
                [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]
            );

            $spanExporter = new SpanExporter($tracesTransport);
            $sampler = new ParentBased(new AlwaysOnSampler());
            $tracerProvider = new TracerProvider(
                new SimpleSpanProcessor($spanExporter),
                $sampler,
                $resource
            );

            $this->tracer = $tracerProvider->getTracer(
                name: 'otel-tracer',
                version: SELF::OTEL_INSTRUMENT_VERSION,
                schemaUrl: SELF::OTEL_SCHEMA_URL,
            );

            $this->otlpEnabled = true;

            $this->debug('OTLP collector enabled');
        } else {
            $this->debug('OTLP collector disabled');
        }
    }

    public function createSpan(
        string $name,
        array $context = [],
        ?SpanKind $kind = null,
        ?ContextInterface $parentSpanContext = null
    ): SpanInterface {
        if (!$this->otlpEnabled) {
            return Span::getInvalid();
        }

        $spanBuilder = $this->tracer->spanBuilder($name)
            ->setAttributes($context)
            ->setSpanKind($kind ?? SpanKind::KIND_INTERNAL);

        // If parent span is provided, set it explicitly
        if ($parentSpanContext !== null) {
            $spanBuilder->setParent($parentSpanContext);
        }

        return $spanBuilder->startSpan();
    }

    public function debug(string $message, ?array $context = null, ?\Throwable $exception = null, ?SpanInterface $span = null): void
    {
        $this->log(LogLevel::DEBUG, $message, $context, $exception, $span);
    }

    public function verbose(string $message, ?array $context = null, ?\Throwable $exception = null, ?SpanInterface $span = null): void
    {
        $this->log(LogLevel::VERBOSE, $message, $context, $exception, $span);
    }

    public function info(string $message, ?array $context = null, ?\Throwable $exception = null, ?SpanInterface $span = null): void
    {
        $this->log(LogLevel::INFO, $message, $context, $exception, $span);
    }

    public function warning(string $message, ?array $context = null, ?\Throwable $exception = null, ?SpanInterface $span = null): void
    {
        $this->log(LogLevel::WARNING, $message, $context, $exception, $span);
    }

    public function error(string $message, ?array $context = null, ?\Throwable $exception = null, ?SpanInterface $span = null): void
    {
        $this->log(LogLevel::ERROR, $message, $context, $exception, $span);
    }

    public function log(
        LogLevel $level,
        string $message,
        ?array $context = null,
        ?\Throwable $exception = null,
        ?SpanInterface $span = null
    ): void {
        if ($level->value > $this->logLevel->value) {
            return;
        }

        $now = time();

        if ($this->otlpEnabled) {
            $record = new LogRecord($message);
            $record->setTimestamp($now * 1000000000);
            $record->setSeverityNumber(match ($level->value) {
                4 => 5,  // Debug => DEBUG
                3 => 8,  // Verbose => DEBUG4
                2 => 9,  // Info => INFO
                1 => 13, // Warning => WARN
                0 => 17, // ERROR
            });
            $record->setSeverityText(match ($level->value) {
                4 => 'DEBUG',
                3 => 'DEBUG4',
                2 => 'INFO',
                1 => 'WARNING',
                0 => 'ERROR',
            });

            // If span is provided, add its context to the log
            if ($span !== null) {
                $otlpContext = Context::getRoot();
                $record->setContext($otlpContext->with(ContextKeys::span(), $span));
            }

            if ($context) {
                $record->setAttributes($context);
            }

            if ($exception) {
                $record->setAttributes(array_merge($context ?? [], [
                    'exception.type' => get_class($exception),
                    'exception.message' => $exception->getMessage(),
                    'exception.stacktrace' => $exception->getTraceAsString(),
                ]));
            }

            $this->otlpLogger->emit($record);
        }

        // Add trace context to console output if span is provided
        if ($span !== null) {
            $spanContext = $span->getContext();
            $context['traceId'] = $spanContext->getTraceId();
            $context['spanId'] = $spanContext->getSpanId();
        }

        // Format console output
        $line = array_filter([
            'level' => $level->name,
            'datetime' => Carbon::createFromTimeStamp($now)->format('Y-m-d H:i:s'),
            'message' => $message,
            'context' => $context,
            'exception' => $exception,
        ]);

        $outputStr = match ($this->logFormat->value) {
            LogFormat::JSON->value => $this->toJson($line),
            default => $this->toText($line),
        };

        $this->output->writeln($outputStr);
    }

    private function toJson(array $line): string
    {
        return json_encode($line, JSON_PARTIAL_OUTPUT_ON_ERROR, 128);
    }

    private function toText(array $line): string
    {
        $delimiter = match ($this->logFormatTextDelimiter->value) {
            LogFormatTextDelimiter::SPACE->value => ' ',
            default => "\t",
        };

        if (array_key_exists('context', $line)) {
            $line['context'] = http_build_query(data: $line['context'], arg_separator: ' ');
        }

        if (array_key_exists('exception', $line)) {
            $line['exception'] = $line['exception']->__toString();
        }

        return implode($delimiter, $line);
    }
}
