<?php

namespace App\Enums;

enum RunState: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
