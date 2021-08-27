<?php

namespace Ljw\Spider;

class Redis
{
    /**
     * @var \Redis $client
     */
    protected $client;
    protected $config;

    /**
     * @param $config
     * @return mixed|static
     */
    public static function _instance($config = [])
    {
        static $store;
        $key = md5(json_encode($config));
        if (empty($store[$key])) {
            $store[$key] = new static($config);
        }
        return $store[$key];
    }

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function connect()
    {
        if (!$this->client) {
            $config = $this->config;
            $this->client = new \Redis();
            $this->client->connect($config['host'], $config['port']);
            if (!empty($config['pwd'])) {
                $this->client->auth($config['pwd']);
            }
            if (!empty($config['database'])) {
                $this->client->select($config['database']);
            }
            if (!empty($config['prefix'])) {
                $this->client->setOption(\Redis::OPT_PREFIX, $config['prefix']);
            }
        }
        return $this;
    }

    public function disConnect()
    {
        if ($this->client) {
            $this->client = null;
        }
    }

    public static function __callStatic($func, $params)
    {
        return static::_instance()->$func(...$params);
    }

    public function __call($func, $params)
    {
        return $this->connect()->client->$func(...$params);
    }
}