<?php
namespace SharkyDog\Shelly\Proxy;
use SharkyDog\Shelly;
use React\Promise;

class Device extends Shelly\Device {
  private $_dev;
  private $_denycmd = [];

  public function __construct(Shelly\Device $dev, ?string $devId=null) {
    $this->emitter($emitter);
    $this->_dev = $dev;

    $sender = function($msg) use($emitter) {
      $cmdId = $msg['id'];
      $method = $msg['method'];
      $params = $msg['params'] ?? [];

      $this->proxyCommand($method, $params)->then(function($msg) use($emitter,$cmdId) {
        $msg->id = $cmdId;
        $emitter('message', [$msg]);
      });
    };

    $this->_dev->on('open', function() use($emitter,$sender,$devId) {
      $emitter('open', [$sender, $devId]);
    });

    $this->_dev->on('close', function($clean) use($emitter) {
      $emitter('close', [$clean]);

      if($clean) {
        $this->_dev = null;
        $this->removeAllListeners();
      }
    });

    $this->_dev->on('notify-raw', function($msg) use($emitter) {
      $msg->src = $this->getDevId() ?? 'proxy';
      $msg->dst = 'ws';

      if($this->_proxy_notify($msg) === false) {
        return;
      }

      $emitter('message', [$msg]);
    });
  }

  protected function _dev(): ?Shelly\Device {
    return $this->_dev;
  }

  protected function _proxy_command(string $method, array $params=[]): Promise\PromiseInterface {
    if(!$this->_dev) {
      return Promise\reject(new Shelly\Exception\Error('Bad device', 502));
    }
    if($this->_denycmd[strtolower($method)] ?? null) {
      return Promise\reject(new Shelly\Exception\Error('Command not allowed', 403));
    }

    $this->_dev->silenceNext(false);
    $this->_dev->rawResultNext(true);

    $pr = $this->_dev->sendCommand($method, $params);

    $pr = $pr->then(function($msg) use($method) {
      if($method == 'Shelly.GetDeviceInfo') {
        $msg->result->id = $this->getDevId();

        if(preg_match('/[a-f0-9]{12}$/i',$msg->result->id,$m)) {
          $msg->result->mac = strtoupper($m[0]);
        }

        $msg->result->auth_en = false;
        $msg->result->auth_domain = null;
      }

      return $msg->result;
    });

    return $pr;
  }

  protected function _proxy_notify(\stdClass $msg) {
  }

  public function getDevId(): ?string {
    return parent::getDevId() ?? ($this->_dev ? $this->_dev->getDevId() : null);
  }

  public function setPassword(string $password) {
  }

  public function proxyCommand(string $method, array $params=[]): Promise\PromiseInterface {
    $pr = $this->_proxy_command($method, $params);

    $pr = $pr->then(function(\stdClass $result) {
      return ['result' => $result];
    });
    $pr = $pr->catch(function(\Throwable $e) {
      return ['error' => (object)['code'=>$e->getCode(), 'message'=>$e->getMessage()]];
    });

    $pr = $pr->then(function($msg) {
      return (object)([
        'id' => 0,
        'src' => $this->getDevId() ?? 'proxy',
        'dst' => 'ws'
      ] + $msg);
    });

    return $pr;
  }

  public function disableCommand(string $method, bool $disable=true) {
    if($disable) {
      $this->_denycmd[strtolower($method)] = true;
    } else {
      unset($this->_denycmd[strtolower($method)]);
    }
  }
}
