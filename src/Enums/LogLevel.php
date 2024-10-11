<?php

namespace Timewave\LaravelLogger\Enums;

enum LogLevel: int
{
    case DEBUG = 4;
    case VERBOSE = 3;
    case INFO = 2;
    case WARNING = 1;
    case ERROR = 0;
}
