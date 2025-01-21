<?php

require_once(dirname(__DIR__).'/TropipaySDK/ILogger.php');

class Logger implements Ilogger
{    
    private function log(string $level, string $message): void
    {
        try {
            $id = IdGenerator::generate();
            $path = dirname(__DIR__).'/Logs/log';
            $line = "[" .date('M d Y G:i:s') ."] " . $level .": ". $id . ' -- ' . $message . "\r\n";
            file_put_contents($path, $line, FILE_APPEND);
        } catch (\Exception $th) {
            echo $th->getMessage();
        }
        
    }

    public function info(string $message): void
    {
        $this->log("INFO", $message);
    }
    
    public function error(string $message): void
    {
        $this->log("ERROR", $message);
    }
}


class IdGenerator
{
    public static function generate() {
        $vars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $stringLength = strlen($vars);
        $result = '';
        for ($i = 0; $i < 20; $i++) {
            $result .= $vars[rand(0, $stringLength - 1)];
        }
        return $result;
    }
}