<?php

namespace Timewave\LaravelLogger\Enums;

enum LogLevel: int
{
    case DEBUG = 5;
    case VERBOSE = 4;
    case INFO = 3;
    case WARNING = 2;
    case ERROR = 1;
}
