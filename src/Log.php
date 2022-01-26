<?php


namespace Ljw\Spider;


class Log
{
    const LEVEL_OFF = 0;
    const LEVEL_DEBUG = 1;
    const LEVEL_INFO = 2;
    const LEVEL_ERROR = 3;
    protected $show = false;
    protected $filename = '';
    protected $task_id = 0;
    protected $level = 1;

    public function __construct($task_id, $filename, $level = self::LEVEL_DEBUG, $show = false)
    {
        $this->setTaskId($task_id);
        $this->setFilename($filename);
        $this->setLevel($level);
        $this->setShow($show);
    }

    public function setTaskId($task_id)
    {
        $this->task_id = $task_id;
    }

    public function setFilename($filename)
    {
        $this->filename = $filename;
    }

    public function setLevel($level = self::LEVEL_DEBUG)
    {
        $this->level = $level;
    }

    public function setShow($show = false)
    {
        $this->show = $show;
    }

    public function write($msg)
    {
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        if ($this->filename) {
            file_put_contents($this->filename, $msg . PHP_EOL, FILE_APPEND);
        }
    }

    public function show($msg)
    {
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        echo $msg . PHP_EOL;
    }

    public function log($msg)
    {
        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        $msg = '[' . $this->task_id . '][' . date('Y-m-d H:i:s') . '] ' . $msg;
        $this->write($msg);
        if ($this->show) {
            $this->show($msg);
        }
    }

    public function debug($msg)
    {
        if ($this->level < self::LEVEL_DEBUG) {
            return;
        }
        $this->log($msg);
    }

    public function info($msg)
    {
        if ($this->level < self::LEVEL_INFO) {
            return;
        }
        $this->log($msg);
    }
}