<?php
date_default_timezone_set('PRC');
define("REDIS_HOST", "192.168.19.215");
define("REDIS_PORT", 6379);
define('PHP_CLI_PATH', '/usr/local/php/bin/php');
define('LOG_DIR', __DIR__);
define("DEBUG", TRUE);
class AsyncHttp {
    public static $globalEvent = null;

    /**
     * @var int [0-stop, 1-running]
     */
    public static $status = 0;

    /**
     * 进程以什么用户启动, 默认nobody
     * @var type 
     */
    public static $username = 'nobody';

    /**
     * @var \Redis redis连接实例
     */
    public static $redis = null;
    private static $key = 'async_http_pool';

    /**
     * @var int 进程pid文件
     */
    public static $pidFile = null;

    public static function boot() {
        global $argv;
        if (PHP_SAPI != 'cli' || $argv[0] != 'AsyncHttp.php' || !isset($argv[1]) || $argv[1] != 'start') {
            exit('Usage: php AsyncHttp.php start' . PHP_EOL);
        }
        if (!extension_loaded('libevent')) {
            exit('Pls install libevent extension!' . PHP_EOL);
        }
        if (is_file(self::getPidFile()) && posix_kill(intval(file_get_contents(self::getPidFile())), 0)) {
            exit("the process has running!" . PHP_EOL);
        }
        self::daemon();
        self::$redis = new \Redis();
        $flag = self::$redis->pconnect(REDIS_HOST, REDIS_PORT, 3);
        $flag || exit('can not connect to redis!' . PHP_EOL);
        self::$globalEvent = event_base_new();
        self::installSignalHandler();
        event_base_loop(self::$globalEvent);
    }

    private static function getPidFile() {
        return sys_get_temp_dir() . '/asynchttp.pid';
    }

    private static function daemon() {
        umask(0);
        $pid = pcntl_fork();
        $pid === -1 && exit('fork process error!' . PHP_EOL);
        if ($pid > 0) {
            exit();
        }
        posix_setsid() === -1 && exit('setsid error!' . PHP_EOL);
        if (self::$username != null) {
            $user = posix_getpwnam(self::$username);
            posix_setgid($user['gid']) && posix_setuid($user['uid']);
        }
        self::$pidFile = self::getPidFile();
        file_put_contents(self::$pidFile, posix_getpid());
        self::installHook();
    }

    private static function installHook() {
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            if (error_reporting() == 0) {
                //Error reporting is currently turned off or suppressed with @
                return;
            }
            $errType = '';
            switch ($errno) {
                case E_ERROR:
                    $errType = 'PHP ERROR: ';
                    break;
                case E_WARNING:
                    $errType = 'PHP WARNING: ';
                    break;
                case E_NOTICE:
                    $errType = 'PHP NOTICE: ';
                    break;
                default:
                    $errType = 'Unknown error type: ';
                    break;
            }
            return AsyncHttp::log($errType . $errno . ' ' . $errstr . ' ' . $errfile . ' ' . $errline);
        });
        set_exception_handler(function (\Exception $ex) {
            return AsyncHttp::log('Uncaught exception: ' . $ex->getFile() . ', Line:' . $ex->getLine() . ', ' . $ex->getMessage());
        });
        register_shutdown_function(function () {
            if (is_file(self::$pidFile)) {
                unlink(self::$pidFile);
                $errinfo = error_get_last();
                $errstr = 'unlink[' . self::$pidFile . ']' . (is_array($errinfo) ? implode(' ', $errinfo) : '');
                AsyncHttp::log($errstr);
            }
        });
    }

    private static function log($msg, $extra = '') {
        $msgHeader = '[' . date('Y-m-d H:i:s') . '] AsyncHttp Log: ';
        $msg .= ' ' . is_array($extra) ? json_encode($extra) : $extra;
        return error_log($msgHeader . $msg . "\r\n", 3, LOG_DIR . '/asynchttp.log');
    }

    private static function addEventListener($fd, $flag, $callback, $params = null) {
        $event = event_new();
        $args = is_null($params) ? [$event, self::$globalEvent] : [$event, self::$globalEvent, $params];
        event_set($event, $fd, $flag, $callback, $args);
        event_base_set($event, self::$globalEvent);
        event_add($event);
    }

    private static function doJob() {
        while (TRUE) {
            $item = self::$redis->rPop(self::$key);
            if (empty($item)) {
                return;
            }
            $options = unserialize($item);
            if (!is_array($options) || !isset($options['host'])) {
                continue;
            }
            $client = stream_socket_client("tcp://{$options['host']}:{$options['port']}", $errno, $errstr, 1);
            if (!$client) {
                self::log($errno . $errstr);
                continue;
            }
            stream_set_blocking($client, 0);
            $requestData = $options['method'] . ' ' . $options['path'] . ' HTTP/1.1' . "\r\n";
            $requestData .= "Host: {$options['host']}\r\n";
            foreach ($options['headers'] as $key => $val) {
                $requestData .= "{$key}: {$val}\r\n";
            }
            $requestData .= "\r\n";
            $requestData .= is_array($options['params']) ? http_build_query($options['params']) : $options['params'];
            fwrite($client, $requestData);
            self::addEventListener($client, EV_READ, ['AsyncHttp', 'onJobDone'], $options['callback']);
        }
    }

    private static function onJobDone($fd, $event, $args) {
        $resp = fread($fd, 65535);
        list($header, $body) = explode("\r\n\r\n", $resp);
        $data = '';
        if (strpos($header, 'Transfer-Encoding') !== FALSE) {
            self::chunkedDecode($fd, $data, $body);
        } else {
            $data = $body;
        }
        $urlInfo = parse_url($args[2]);
        isset($urlInfo['port']) || $urlInfo['port'] = 80;
        $client = stream_socket_client("tcp://{$urlInfo['host']}:{$urlInfo['port']}", $errno, $errstr, 1);
        if (!$client) {
            self::log("callback error: {$errno} {$errstr}");
            return;
        }
        $req = "POST " . $urlInfo['path'] . (isset($urlInfo['query']) ? '?' . $urlInfo['query'] : '') . " HTTP/1.1\r\n";
        $req.="Host: {$urlInfo['host']}\r\n";
        $req.="Content-Length: " . strlen($data) . "\r\n";
        $req.="Connection: close\r\n";
        $req.="\r\n";
        $req.= $data;
        fwrite($client, $req);
        fclose($fd);
        fclose($client);
    }

    private static function chunkedDecode($fd, &$data, $body) {
        while (1) {
            list($hexLen, $body) = explode("\r\n", $body, 2);
            $clen = hexdec($hexLen);
            if (empty($body)) {
                break;
            }
            $data .= substr($body, 0, $clen);
            $body = substr($body, $clen);
        }
    }

    private static function requestValidate(array $options) {
        if (!isset($options['host'])) {
            throw new \Exception("Pls set host, invoke AsyncHttp::help() to view example");
        }
        if (!isset($options['port'])) {
            throw new \Exception("Pls set port, invoke AsyncHttp::help() to view example");
        }
        if (!isset($options['path'])) {
            throw new \Exception("Pls set path, invoke AsyncHttp::help() to view example");
        }
        if (!isset($options['method'])) {
            throw new \Exception("Pls set method, invoke AsyncHttp::help() to view example");
        }
        if (!isset($options['headers']) || !is_array($options['headers'])) {
            throw new \Exception("Pls set headers, invoke AsyncHttp::help() to view example");
        }
        if (!isset($options['callback'])) {
            $url = parse_url($options['callback']);
            if (!isset($url['scheme']) || !in_array($url['scheme'], ['http', 'https']) || !isset($url['host'])) {
                throw new \Exception("the callback url is illegal");
            }
            throw new \Exception("Pls set callback, invoke AsyncHttp::help() to view example");
        }
    }

    public static function help() {
        $options = '$options';
        $code = <<<CODE
        <pre>
        $options = array(
            'host' => 'www.google.com',
            'port'     => 80,
            'path'     => '/upload',
            'method'   => 'POST',
            'params'   => '{"user":"root","pwd":"123"}',
            'headers'  => array(
                'Content-Type'   => 'application/json',
                'Content-Length' => 27
            ),
            'callback' => 'http://192.168.62.15/notify.php'
        );
        AsyncHttp::request($options);
        </pre>
CODE;
        echo $code;
    }

    private static function installSignalHandler() {
        self::addEventListener(SIGUSR1, EV_SIGNAL | EV_PERSIST, ['AsyncHttp', 'doJob']);
    }

    /**
     * 异步http请求，使用方法请调用AsyncHttp::help()查看
     * @param array $options
     * @return boolean
     */
    public static function request(array $options) {
        self::requestValidate($options);
        $redis = new \Redis();
        $redis->connect(REDIS_HOST, REDIS_PORT, 3);
        $redis->lPush(self::$key, serialize($options));
        $pid = is_file(self::getPidFile()) ? (int) file_get_contents(self::getPidFile()) : 0;
        if ($pid == 0 || posix_kill($pid, 0) == FALSE) {
            if (DEBUG) {
                self::log("Pls boot the AsyncHttp service!");
                return FALSE;
            }
            pclose(popen(PHP_CLI_PATH . ' AsyncHttp.php start >/dev/null &', 'r'));
        }
        if (posix_kill($pid, SIGUSR1) == FALSE) {
            self::log("send signal:SIGUSR1 to {$pid} failure, Pls try again!");
            return FALSE;
        }
        return TRUE;
    }

}
if (DEBUG) {
    PHP_SAPI == 'cli' && $argv[0] == 'AsyncHttp.php' && AsyncHttp::boot();
}
