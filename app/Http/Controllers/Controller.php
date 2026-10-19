<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
