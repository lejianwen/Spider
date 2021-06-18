<?php


namespace Ljw\Spider;


class Log
{
    static $show = false;
    static $filename = '';
    static $task_id = 0;

    public static function write($msg)
    {
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        if (self::$filename) {
            file_put_contents(self::$filename, $msg . PHP_EOL, FILE_APPEND);
        }
    }

    public static function show($msg)
    {
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        echo $msg . PHP_EOL;
    }

    public static function debug($msg)
    {
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        $msg = '[' . self::$task_id . '][' . date('Y-m-d H:i:s') . '] ' . $msg;
        self::write($msg);
        if (self::$show) {
            self::show($msg);
        }
    }
}