<?php
  
  namespace ChromeDevTools;
  
  use WebSocket\Client;
  
  /**
   * Class Chrome
   * @package ChromeDevTools
   *
   * @property $this $DOM
   * @property $this $DOMDebugger
   * @property $this $Debugger
   * @property $this $Emulation
   * @property $this $Input
   * @property $this $Network
   * @property $this $Page
   * @property $this $Profiler
   * @property $this $Runtime
   * @property $this $Schema
   * info
   *   https://chromedevtools.github.io/devtools-protocol/
   *   https://chromedevtools.github.io/devtools-protocol/tot/Page
   */
  class Chrome {
    /**
     * Web Socket Client
     * @var Client
     */
    private $wsClient;
    
    /**
     * List of available domains
     * @var array
     */
    private $domains = [
      //stable @see https://chromedevtools.github.io/devtools-protocol/1-2/
      'DOM',
      'DOMDebugger',
      'Debugger',
      'Emulation',
      'Input',
      'Network',
      'Page',
      'Profiler',
      'Runtime',
      'Schema',
      
      //latest @ see https://chromedevtools.github.io/devtools-protocol/tot/
      'Accessibility',
      'Animation',
      'ApplicationCache',
      'Audits',
      'Browser',
      'CSS',
      'CacheStorage',
      'Console',
      'DOMSnapshot',
      'DOMStorage',
      'Database',
      'DeviceOrientation',
      'HeapProfiler',
      'IO',
      'IndexedDB',
      'Inspector',
      'LayerTree',
      'Log',
      'Memory',
      'Overlay',
      'Performance',
      'Security',
      'ServiceWorker',
      'Storage',
      'SystemInfo',
      'Target',
      'Tethering',
      'Tracing',
    ];
    
    /**
     * Message ID
     * @var int
     */
    private $messageId = 0;
    
    /**
     * Current domain to call methods on
     * @var string
     */
    private $currentDomain;
    
    /**
     * Host for Web Socket Client
     * @var string
     */
    private $host;
    /**
     * Port for Web Socket Client
     * @var int
     */
    private $port;
    /**
     * List of open Google Chrome tabs
     * @var array
     */
    private $tabs;
    /**
     * Timeout in seconds
     * @var int
     */
    private $timeout;
    
    /**
     * Chrome constructor.
     * @param string $host
     * @param int $port
     * @param int $tab
     * @param int $timeout
     * @param bool $autoConnect
     */
    public function __construct($timeout = 5, $host = 'localhost', $port = 9222) {
      $this->host = $host;
      $this->port = $port;
      $this->timeout = $timeout;
      $this->connect();
    }
    
    /**
     * Connect to $tab
     * @param int $tab
     * @param bool $updateTabs
     */
    public function connect() {
      // You should always send command Page.close after your work, becouse every cration will be new tab in chrome and may be memmory leack
      $newPage = $this->getNewPage();
      if(!isset($newPage['webSocketDebuggerUrl'])) throw new \Exception('Chrome: cannot create new page');
      $wsUrl = $newPage['webSocketDebuggerUrl'];
      $this->closeClient();
      $this->wsClient = $this->getWsClient($wsUrl);
      $this->wsClient->setTimeout($this->timeout);
    }
    
    public function getWsClient($wsUrl) {
      return new Client($wsUrl);
    }
    
    /**
     * Close socket
     */
    public function closeClient() {
      if ($this->wsClient) {
        $this->wsClient->close();
      }
    }
    
    public function getNewPage() {
      $endpoint = sprintf('http://%s:%s/json/new', $this->host, $this->port);
      $ch = curl_init($endpoint);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
      curl_setopt($ch, CURLOPT_TIMEOUT, 5);
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      $result = curl_exec($ch);
      if (false === $result) {
        throw new \RuntimeException(sprintf('Can not get Google Chrome open tabs. ' . 'Endpoint %s is not reachable or Google Chrome process is not running', $endpoint));
      }
      return json_decode($result, true);
    }
    
    /**
     * Get open Google Chrome tabs
     */
    public function getTabs() {
      $endpoint = sprintf('http://%s:%s/json', $this->host, $this->port);
      $ch = curl_init($endpoint);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
      curl_setopt($ch, CURLOPT_TIMEOUT, 5);
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      $result = curl_exec($ch);
      if (false === $result) {
        throw new \RuntimeException(sprintf('Can not get Google Chrome open tabs. ' . 'Endpoint %s is not reachable or Google Chrome process is not running', $endpoint));
      }
      $this->tabs = json_decode($result, true);
    }
    
    /**
     * @param $name
     * @return $this
     */
    public function __get($name) {
      if (in_array($name, $this->domains)) {
        $this->currentDomain = $name;
        return $this;
      }
      throw new \InvalidArgumentException("Unknown domain $name");
    }
    
    /**
     * @param $name
     * @param $arguments
     * @return mixed|null
     */
    public function __call($name, $arguments) {
      $payload = [
        'method' => $this->currentDomain . '.' . $name,
        'id'     => $this->messageId
      ];
      if (!empty($arguments)) {
        $payload['params'] = $arguments[0];
      }
      $this->wsClient->send(json_encode($payload));
      $response = $this->waitResult($this->messageId);
      return $response;
    }
    
    /**
     * Wait for message from Web Socket
     * @param integer $timeout Timeout in seconds
     * @return mixed|null
     */
    public function waitMessage($timeout = null) {
      $timeout = $timeout ? $timeout : $this->timeout;
      $this->wsClient->setTimeout($timeout);
      try {
        $message = $this->wsClient->receive();
      } catch (\Exception $e) {
        return null;
      } finally {
        $this->wsClient->setTimeout($this->timeout);
      }
      return !empty($message) ? json_decode($message, true) : null;
    }
    
    /**
     * Wait for specified event
     * @param $eventName
     * @param null $timeout
     * @return array
     */
    public function waitEvent($eventName, $timeout = null) {
      $timeout = $timeout ? $timeout : $this->timeout;
      $startTime = time();
      $messages = [];
      $matchingMessage = null;
      while (true) {
        $now = time();
        if (($now - $startTime) > $timeout) {
          break;
        }
        try {
          $message = json_decode($this->wsClient->receive(), true);
          $messages[] = $message;
          if (isset($message['method']) && $message['method'] === $eventName) {
            $matchingMessage = $message;
            break;
          }
        } catch (\Exception $e) {
          break;
        }
      }
      return ['matching_message' => $matchingMessage, 'messages' => $messages];
    }
    
    /**
     *
     * @param int $resultId ID of the Result
     * @param int $timeout Timeout in seconds
     * @return mixed|null
     */
    public function waitResult($resultId, $timeout = null) {
      $timeout = $timeout ? $timeout : $this->timeout;
      $startTime = time();
      $result = null;
      while (true) {
        $now = time();
        if (($now - $startTime) > $timeout) {
          break;
        }
        try {
          $message = json_decode($this->wsClient->receive(), true);
          if (isset($message['result']) && $message['id'] == $resultId) {
            $result = $message;
            break;
          }
        } catch (\Exception $e) {
          break;
        }
      }
      return $result;
    }
    
    /**
     * @param $tab
     * @return mixed
     */
    protected function getWsUrl($tab) {
      return isset($this->tabs[$tab]) && $this->tabs[$tab]['webSocketDebuggerUrl'] ? $this->tabs[$tab]['webSocketDebuggerUrl'] : null;
    }
  }
