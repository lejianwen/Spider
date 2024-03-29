<?php

namespace Ljw\Spider;

class Spider
{
    protected $wait_queue;
    protected $all_queue;
    protected $config = [];
    protected $html_parse;
    /** @var Status $status */
    protected $status;
    protected $task_id = 0;
    public $filter_url;
    public $using_proxy_index = 0; //使用的代理索引
    public $empty_queue_func;
    /** @var Redis|\Redis $redis */
    protected $redis;
    protected $logger;

    public function __construct($config)
    {
        $this->updateConfig($config);
        if (!empty($this->config['queue_redis']) || $this->config['task_num'] > 1) {
            if (empty($this->config['redis'])) {
                echo "task_num > 1 must redis \n";
                exit;
            }
            $redis = Redis::_instance($this->config['redis']);
            $this->redis = $redis;
            $this->wait_queue = new Queue($redis);
            $this->all_queue = new AllQueue($redis);
            $this->status = new Status($redis, $this->config['server_id']);
        } else {
            $this->wait_queue = new Queue();
            $this->all_queue = new AllQueue();
            $this->status = new Status();
        }
        $this->html_parse = new HtmlParse();
        $this->logger = new Log(0, $this->config['log_filename'], $this->config['log_level'], $this->config['log_show']);
    }

    public function ready()
    {
        if ($this->config['ask_continue'] == 'ask'
            && (!empty($this->config['queue_redis']) || $this->config['task_num'] > 1)
            && (!$this->wait_queue->isEmpty() || count($this->all_queue) > 0)) {
            $msg = "Old data in Redis, continue? no will clean data, default is yes \n";
            $msg .= 'continue? [Y/n]';
            fwrite(STDOUT, $msg);
            $arg = strtolower(trim(fgets(STDIN)));
            if (empty($arg)) {
                $arg = 'y';
            }
            if ($arg == 'n') {
                $this->clear();
            }
        }
        if ($this->config['ask_continue'] == 'clear') {
            $this->clear();
        }
//        if ($this->config['ask_continue'] == 'continue') {
//            //
//        }
        $this->addEntries();
    }

    public function addEntries()
    {
        $entries = (array)$this->config['entry'];
        foreach ($entries as $entry) {
            //等待队列为空,入口页面强制加入
            $this->addUrl($entry, [], true, false);
        }
    }

    public function clear()
    {
        $this->wait_queue->clear();
        $this->all_queue->clear();
        for ($i = 0; $i < $this->config['task_num']; $i++) {
            //clear status
            $this->status->setTaskId($i);
            $this->status->clear();
        }
    }

    public function start()
    {
        $this->ready();
        if ($this->config['task_num'] > 1) {
            //不关心子进程的状态
            pcntl_signal(SIGCHLD, SIG_IGN);
            for ($i = 0; $i < $this->config['task_num']; $i++) {
                $pid = pcntl_fork();
                if ($pid > 0) {
                    pcntl_wait($status, WNOHANG);
                    //显示
                    if ($i + 1 == $this->config['task_num'] && !empty($this->config['show_task_panel'])) {
                        //最后一个
                        $this->panel();
                    }
                } else {
                    $this->task_id = $i;
                    $this->logger->setTaskId($this->task_id);
                    $this->logger->debug('task start~~~');
                    $this->using_proxy_index = $i;
                    $this->status->setTaskId($this->task_id);
                    $this->task();
                    break;
                }
            }
        } else {
            $this->task();
        }
    }

    public function task()
    {
        if ($this->redis) {
            $this->redis->disConnect();
        }
        $this->resetStatus();
        $request = new Request($this->config['guzzle'] ?? []);
        if (isset($this->config['multi_num']) && $this->config['multi_num'] > 1) {
            while (1) {
                $this->checkStatusCmd();
                //空转次数
                $wait_count = 0;
                $wait_urls = [];

                //获取足够的url数 或 最多空转5次
                while (count($wait_urls) < $this->config['multi_num'] && $wait_count < 5) {
                    if (!$this->wait_queue->isEmpty()) {
                        $url = $this->wait_queue->dequeue();
                        if (!$url) {
                            continue;
                        }
                        $wait_urls[] = $this->urlInfo($url);
                    } else {
                        usleep(100);
                        $wait_count++;
                    }
                }
                if (empty($wait_urls)) {
                    $this->upTaskStatus('status', 'empty');
                    usleep(100);
                    continue;
                }

                //使用代理必须重新创建 gz客户端，不然不能在多个代理中切换
                if (!empty($this->config['proxy'])) {
                    $request = new Request($this->config['guzzle'] ?? []);
                    $request->setProxy($this->useProxy($wait_urls));
                }
                $responses = $request->requestAsync($wait_urls);
                foreach ($responses as $url => $response) {
                    $this->response($this->urlInfo($url), $response);
                }

                $this->nextStatus();
                if (isset($this->config['interval'])) {
                    if (is_array($this->config['interval'])) {
                        usleep(random_int($this->config['interval'][0], $this->config['interval'][1]) * 1000);
                    } elseif ($this->config['interval'] > 0) {
                        usleep($this->config['interval'] * 1000);
                    }
                }
            }
        } else {
            while (1) {
                $this->checkStatusCmd();
                if ($this->wait_queue->isEmpty()) {
                    $this->logger->debug("dequeue null");
                    $this->upTaskStatus('status', 'empty');
                    sleep(1);

                    if ($this->empty_queue_func) {
                        ($this->empty_queue_func)($this);
                    }

                    continue;
                }
                $url = $this->wait_queue->dequeue();
                //redis队列可能最后一个已经被别的进程获取
                if (!$url) {
                    $this->logger->debug("dequeue null");
                    continue;
                }
                $url_info = $this->urlInfo($url);
                if (!$url_info) {
                    //可能已经被清理掉了
                    sleep(1);
                    continue;
                }
                //使用代理必须重新创建 gz客户端，不然不能在多个代理中切换
                if (!empty($this->config['proxy'])) {
                    $request = new Request($this->config['guzzle'] ?? []);
                    $request->setProxy($this->useProxy($url_info));
                }
                try {
                    $response = $request->request($url_info);
                    $this->response($url_info, $response);
                } catch (RequestException $e) {
                    $this->logger->debug($e->getMessage());
                    $this->response($url_info, false);
                }

                $this->nextStatus();
                if (isset($this->config['interval'])) {
                    if (is_array($this->config['interval'])) {
                        usleep(random_int($this->config['interval'][0], $this->config['interval'][1]) * 1000);
                    } elseif ($this->config['interval'] > 0) {
                        usleep($this->config['interval'] * 1000);
                    }
                }
            }
        }
    }

    /**
     * 构造url结构
     * @param $url
     * @param array $cur_url
     * @param array $extra 额外信息
     * @return array|boolean
     */
    public function makeUrl($url, $cur_url = [], $extra = [])
    {
        $url = trim($url);
        if (!$url
            || strpos($url, '#') !== false
            || 'javascript:' == strtolower(substr($url, 0, 11))) {
            //javascript
            return false;
        }
        $parse = parse_url($url);
        if (!empty($parse['scheme']) && !in_array($parse['scheme'], ['https', 'http'])) {
            //忽略非http和https
            return false;
        }

        if (!empty($parse['host']) && !in_array($parse['host'], $this->config['domains'])) {
            //不在domain中的链接忽略
            return false;
        }
        if (empty($parse['scheme'])) {
            //没有协议 则根据referer构建

            if (empty($cur_url)) {
                return false;
            }
            $cur_parse = parse_url($cur_url['url']);
            if (empty($cur_parse) || empty($cur_parse['scheme']) || empty($cur_parse['host'])) {
                return false;
            }

            if (empty($parse['host'])) {
                $base_uri = $cur_parse['scheme'] . '://' . $cur_parse['host'];
                // 判断是不是 / 开头
                if (substr($url, 0, 1) == '/') {
                    $url = $base_uri . $url;
                } else {
                    //修正url层级
                    $cur_path = $cur_parse['path'] ?? '';
                    $cur_path_arr = explode('/', ltrim($cur_path, '/'));
                    array_pop($cur_path_arr);
                    $url_path_arr = explode('/', str_replace('//', '/', $parse['path']));
                    $result_arr = array_merge($cur_path_arr, $url_path_arr);
                    $arr = [];
                    foreach ($result_arr as $k => $v) {
                        if ($v == '..') {
                            array_pop($arr);
                        } elseif ($v == '.') {
                            //当前目录不用处理
                        } else {
                            $arr[] = $v;
                        }
                    }
                    $url = $base_uri . '/' . implode('/', $arr);
                }
            } else {
                //表示以 // 开头
                $url = $cur_parse['scheme'] . ':' . $url;
            }
        }
        return [
            'url' => $url,
            'method' => 'get',
            'headers' => [
                'Referer' => $cur_url['url'] ?? ''
            ],
            'try_num' => 0,
            'depth' => ($cur_url['depth'] ?? 0) + 1,
            'status' => 0,
            'extra' => $extra
        ];
    }

    public function urlInfo($url)
    {
        return $this->all_queue[$url];
    }

    /**
     * @param $url
     * @param array $cur_url 正在访问的url，主要用于refer
     * @param false $repeat 是否覆盖
     * @param bool $filter 是否过滤
     * @return bool
     */
    public function addUrl($url, $cur_url = [], $repeat = false, $filter = true, $extra = [])
    {
        $url_info = $this->makeUrl($url, $cur_url, $extra);
        if (!$url_info) {
            return false;
        }

        if (!$repeat) {
            $ex = $this->urlInfo($url_info['url']);
            if (!empty($ex) && $ex['status'] > -1) {
                //已经添加过了
                return false;
            }
            if (!empty($ex) && isset($this->config['max_try_num']) && $ex['try_num'] >= $this->config['max_try_num']) {
                //达到重试最大次数
                return false;
            }
        }

        if (!empty($this->config['max_depth']) && $this->config['max_depth'] < $url_info['depth']) {
            return false;
        }

        if ($filter && $this->filter_url && $this->filter_url instanceof \Closure) {
            if (!($this->filter_url)($url_info)) {
                return false;
            }
        }

        $this->all_queue[$url_info['url']] = $url_info;
        $this->wait_queue->enqueue($url_info['url']);
        $this->logger->debug("add {$url_info['url']} ");
        return true;
    }

    public function unshiftUrl($url, $cur_url = [], $repeat = false, $filter = true, $extra = [])
    {
        $url_info = $this->makeUrl($url, $cur_url, $extra);
        if (!$url_info) {
            return false;
        }

        if (!$repeat) {
            $ex = $this->urlInfo($url_info['url']);
            if (!empty($ex) && $ex['status'] > -1) {
                //已经添加过了
                return false;
            }
            if (!empty($ex) && isset($this->config['max_try_num']) && $ex['try_num'] >= $this->config['max_try_num']) {
                //达到重试最大次数
                return false;
            }
        }

        if (!empty($this->config['max_depth']) && $this->config['max_depth'] < $url_info['depth']) {
            return false;
        }

        if ($filter && $this->filter_url && $this->filter_url instanceof \Closure) {
            if (!($this->filter_url)($url_info)) {
                return false;
            }
        }

        $this->all_queue[$url_info['url']] = $url_info;
        $this->wait_queue->unshift($url_info['url']);
        $this->logger->debug("unshift {$url_info['url']} ");
        return true;
    }

    public function success($url_info)
    {
        $this->logger->debug("get success {$url_info['url']} ");
        $url_info['try_num']++;
        $url_info['status'] = 1;
        $this->all_queue[$url_info['url']] = $url_info;
        if ($this->status) {
            $this->status['request_num'] += 1;
            $this->status['success_num'] += 1;
        }
    }

    public function fail($url_info)
    {
        $this->logger->debug("get fail {$url_info['url']} ");
        $url_info['try_num']++;
        $url_info['status'] = -1;
        $this->all_queue[$url_info['url']] = $url_info;
        if ($this->status) {
            $this->status['request_num'] += 1;
            $this->status['fail_num'] += 1;
        }
    }

    public function response($url_info, $response)
    {
        if ($response === false) {
            $this->fail($url_info);
            //重试
            if ($url_info['try_num'] < $this->config['max_try_num']) {
                $this->wait_queue->enqueue($url_info['url']);
                $this->logger->debug("retry {$url_info['url']} ");
            }
        } else {
            $this->success($url_info);
            if ($response) {
                //to utf-8
                $response = $this->convertResponse($response);
                //解析页面所有链接
                if ($this->config['auto_add']) {
                    $html_a = $this->html_parse->select($response, "//a/@href");
                    if (!empty($html_a)) {
                        foreach ($html_a as $a) {
                            $this->addUrl($a, $url_info);
                        }
                    }
                }
                foreach ($this->config['pages'] as $page) {
                    if (preg_match('#^' . $page['url'] . '$#', $url_info['url'])) {
                        //匹配到page
                        $data = $this->select($response, $page);

                        if (!empty($page['callback'])) {
                            $page['callback']($data, $url_info, $response, $this);
                        }
                    }
                }
            } else {
                $this->logger->debug("get success {$url_info['url']} , but response is empty, retry");
                //重试
                if ($url_info['try_num'] < $this->config['max_try_num']) {
                    $this->wait_queue->enqueue($url_info['url']);
                }
            }
        }
    }

    public function convertResponse($response)
    {
        $response_charset = mb_detect_encoding($response, ['UTF-8', 'GBK', 'GB2312', 'LATIN1', 'ASCII', 'BIG5', 'ISO-8859-1']);
        if ($response_charset != 'UTF-8') {
            $response = mb_convert_encoding($response, 'UTF-8', $response_charset);
            $pattern = '/<meta[^>]*?charset=.*?>/i';
            $response = preg_replace($pattern, '', $response, 1);
        }
        return $response;
    }

    public function select($html, $selector)
    {
        $data = [];
        if (is_array($selector['selector'])) {
            foreach ($selector['selector'] as $_selector) {
                $data[$_selector['name']] = $this->select($html, $_selector);
            }
        } else {
            $only_one = $selector['only_one'] ?? false;
            $_data = $this->html_parse->select($html, $selector['selector'], $selector['selector_type'] ?? 'xpath');
            return $only_one ? ($_data[0] ?? '') : $_data;
        }
        return $data;
    }

    public function upTaskStatus($type, $value)
    {
        if ($this->status) {
            $this->status[$type] = $value;
        }
    }

    public function resetStatus()
    {
        $this->upTaskStatus('start_time', microtime(true));
        $this->upTaskStatus('last_time', microtime(true));
        $this->upTaskStatus('request_num', 0);
        $this->upTaskStatus('success_num', 0);
        $this->upTaskStatus('fail_num', 0);
        $this->upTaskStatus('status', 'running');
        $this->upTaskStatus('memory', memory_get_usage(true));
    }

    public function panel()
    {
        $statuses = [];
        for ($i = 0; $i < $this->config['task_num']; $i++) {
            $statuses[] = new Status($this->redis, 1, $i);
        }
        echo "\033[2J";
        while (1) {
            $str = "\033[0;0H"
                . "\033[1A"
                . "------------------------ TASKS ------------------------\n"
                . "\033[47;30m"
                . str_pad('task_index', 15)
                . str_pad('request_num', 15)
                . str_pad('success_num', 15)
                . str_pad('fail_num', 15)
                . str_pad('mem', 15)
                . str_pad('time', 15)
                . str_pad('speed', 15)
                . "\033[0m" . PHP_EOL;
            $time = microtime(true);
            foreach ($statuses as $key => $status) {
                $use_time = ($time - $status['start_time']);
                $str .= str_pad($key, 15)
                    . str_pad($status['request_num'], 15)
                    . str_pad($status['success_num'], 15)
                    . str_pad($status['fail_num'], 15)
                    . str_pad(round($status['memory'] / 1024 / 1024, 2) . 'M', 15)
                    . str_pad(round($use_time, 2) . 's', 15)
                    . str_pad(round($status['request_num'] / $use_time, 2) . '/s', 15)
                    . PHP_EOL;
            }
            $str .= "\033[0m";
            echo $str;
            sleep(1);
        }
    }

    public function useProxy($url_info = null)
    {
        $proxy = null;
        if (!empty($this->config['proxy'])) {
            if (is_array($this->config['proxy'])) {
                $this->using_proxy_index++;
                if ($this->using_proxy_index >= count($this->config['proxy'])) {
                    $this->using_proxy_index = 0;
                }
                $proxy = $this->config['proxy'][$this->using_proxy_index];
            }
            if ($this->config['proxy'] instanceof \Closure) {
                $proxy = $this->config['proxy']($url_info);
            }
            $this->logger->debug("use proxy {$proxy} " . (!empty($_SERVER['HTTP_HOST']) ? ($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) : 'PHP-CLI'));
        }
        return $proxy;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function updateConfig($conf = [])
    {
        $this->config = array_merge($this->config, $conf);

        $this->config['task_num'] = $this->config['task_num'] ?? 1;
        $this->config['ask_continue'] = $this->config['ask_continue'] ?? 'continue';
        $this->config['auto_add'] = $this->config['auto_add'] ?? false;
        $this->config['server_id'] = $this->config['server_id'] ?? 1;
        $this->config['log_filename'] = $this->config['log_filename'] ?? '';
        $this->config['log_level'] = $this->config['log_level'] ?? Log::LEVEL_DEBUG;
        $this->config['log_show'] = $this->config['log_show'] ?? true;

        if ($this->logger) {
            $this->logger->setFilename($this->config['log_filename']);
            $this->logger->setLevel($this->config['log_level']);
            $this->logger->setShow($this->config['log_show']);
        }
    }

    protected function checkStatusCmd()
    {
        $next_status = $this->status->getCmd();
        if ($next_status == 'reload') {
            $this->logger->debug('reload');
            if ($this->config['reload_func']) {
                $this->config['reload_func']($this);
            }
            $this->status->setCmd('');
        } elseif ($next_status == 'exit') {
            $this->logger->debug('exit');
            $this->status->clear();
            exit;
        }
    }

    protected function nextStatus()
    {
        $this->upTaskStatus('status', 'running');
        $this->upTaskStatus('memory', memory_get_usage(true));
        $this->upTaskStatus('last_time', microtime(true));
    }

    /**
     * 重置，进程内调用
     */
    public function reset()
    {
        $this->logger->debug('ready reset');

        if (!$this->redis) {
            $this->wait_queue->clear();
            $this->all_queue->clear();
            $this->addEntries();
            return;
        }
        
        $nx_key = 'sp:reset';
        $lock = $this->redis->set($nx_key, 1, ['nx', 'ex' => 5]);
        if ($lock) {
            $all_empty = true;
            //获取所有进程状态
            for ($i = 0; $i < $this->config['task_num']; $i++) {
                $st = new Status($this->redis, $this->config['server_id'], $i);
                if ($st->loadFromRemote('status') != 'empty') {
                    $all_empty = false;
                    break;
                }
            }
            if ($all_empty) {
                $this->wait_queue->clear();
                $this->all_queue->clear();
                $this->addEntries();
                //等待锁自己过期,如果过快，可能一个进程释放锁，另一个立马就获得了
                $this->logger->debug('reset success');
//                gc_collect_cycles();
            } else {
                $this->logger->debug('cancel reset, because one running');
                $this->redis->del($nx_key);
            }
        } else {
            $this->logger->debug('reset waiting');
//            gc_collect_cycles();
            //等待其他进程重置
            sleep(10);
        }
    }

    /**
     * 准备退出，由外部进程调用
     */
    public function readyExit()
    {
        $nx_key = 'sp:rd:exit';
        $lock = $this->redis->set($nx_key, 1, ['nx', 'ex' => 5]);
        if ($lock) {
            for ($i = 0; $i < $this->config['task_num']; $i++) {
                $this->status->setTaskId($i);
                $this->status->setCmd('exit');
            }
            $this->redis->del($nx_key);
        }
    }

    public function logger()
    {
        return $this->logger;
    }
}