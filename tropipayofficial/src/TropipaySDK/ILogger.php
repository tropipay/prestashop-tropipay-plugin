<?php

interface ILogger
{   
    public function info(string $message): void;
    public function error(string $message): void;
}