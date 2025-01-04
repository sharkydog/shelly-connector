<?php
namespace SharkyDog\Shelly\Proxy;
use SharkyDog\Shelly;
use SharkyDog\HTTP\Helpers\WsClientDecorator;

class Client extends WsClientDecorator {
  private $_conn = false;
  private $_tmo = 0;
  private $_dev;

  public function __construct(Shelly\Device $dev, string $url, array $headers=[]) {
    $headers['Sec-Websocket-Protocol'] = 'json-rpc';
    parent::__construct($url, $headers);
    $this->ws->reconnect(2);

    if(!($dev instanceOf Device)) {
      $dev = new Device($dev);
    }
    $this->_dev = $dev;

    $this->_dev->on('close', function() {
      if(!$this->ws->connected()) return;
      $this->ws->close();
    });

    $this->_dev->on('notify-raw', function($msg) {
      if(!$this->ws->connected()) return;
      $msg->dst = 'ws';
      $this->ws->send(json_encode($msg));
    });
  }

  protected function _event_open() {
    $this->_emit('open');

    $this->_dev->proxyCommand('Shelly.GetStatus')->then(function($msg) {
      if(!$this->ws->connected()) return;

      if(!($res = $msg->result??null)) {
        $this->ws->close();
        return;
      }

      $res->ts = round(microtime(true),2);

      $this->ws->send(json_encode([
        'src' => $msg->src ?? 'proxy',
        'dst' => 'ws',
        'method' => 'NotifyFullStatus',
        'params' => $res
      ]));
    });
  }

  protected function _event_message($json) {
    if(!($msg = json_decode($json))) return;

    $cmdId = $msg->id ?? 0;
    $cmdSrc = $msg->src ?? 'ws';
    $method = $msg->method ?? '';
    $params = $msg->params ?? [];
    $params = $params instanceOf \stdClass ? (array)$params : [];

    $this->_dev->proxyCommand($method, $params)->then(function($msg) use($cmdId,$cmdSrc) {
      if(!$this->ws->connected()) return;
      $msg->id = $cmdId;
      $msg->dst = $cmdSrc;
      $this->ws->send(json_encode($msg));
    });
  }

  public function connect(int $timeout=0) {
    $this->_tmo = $timeout;

    if($this->_conn) return;
    $this->_conn = true;

    $this->_dev->on('open', function() {
      parent::connect($this->_tmo);
    });
  }

  public function send() {
  }

  public function disableCommand(string $method, bool $disable=true) {
    $this->_dev->disableCommand($method, $disable);
  }
}
