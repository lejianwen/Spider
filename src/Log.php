<?php


namespace Ljw\Spider;


class Log
{
    static $show = false;
    static $filename = '';

    public static function write($msg)
    {
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        if (self::$filename) {
            file_put_contents(self::$filename, $msg, FILE_APPEND);
        }
    }

    public static function show($msg)
    {
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        echo $msg;
    }

    public static function debug($msg)
    {
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        $msg = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
        self::write($msg);
        if (self::$show) {
            self::show($msg);
        }
    }
}