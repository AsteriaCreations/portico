<?php

namespace App\Enums;

enum AdmissionOutcome: string
{
    case Block = 'block';
    case Warn = 'warn';
    case Capture = 'capture';
    case Flag = 'flag';
    case Ok = 'ok';
}
