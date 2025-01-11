<?php
namespace SharkyDog\Shelly\Proxy;
use SharkyDog\Shelly;
use SharkyDog\HTTP;
use SharkyDog\HTTP\Log;
use SharkyDog\HTTP\WebSocket as WS;
use SharkyDog\mDNS;
use React\Dns\Model\Message as DnsMessage;
use React\Promise;

class Server extends WS\Handler {
  private $_httpd;
  private $_dev = [];
  private $_conn = [];
  private $_denycmd = [];
  private $_mdnsd;
  private $_mdnsd_target;

  public function __construct(?HTTP\Server $httpd=null) {
    $this->_httpd = $httpd ?? new HTTP\Server;
    $this->_httpd->setKeepAliveTimeout(0);
    $this->_httpd->route('/shelly', $this);
    $this->_httpd->route('/rpc', $this);
  }

  private function _matchDevice($port, $addr) {
    return $this->_dev[$addr.':'.$port] ?? $this->_dev[':'.$port] ?? null;
  }

  private function _parseQuery($qry) {
    if(!preg_match_all('/([^&\=]+)(?:\=([^&]*))?/',$qry,$m)) {
      return [];
    }

    $params = [];

    foreach($m[1] as $i => $ks) {
      $ks = preg_split('/\./',$ks,-1,PREG_SPLIT_NO_EMPTY);
      $p = &$params;

      while(($k = array_shift($ks)) !== null) {
        if(!empty($ks)) {
          if(!isset($p[$k])) $p[$k] = [];
          else if($p[$k] instanceOf \stdClass) $p[$k] = (array)$p[$k];
          else if(!is_array($p[$k])) $p[$k] = [];
          $p = &$p[$k];
          continue;
        }
        if($v = urldecode($m[2][$i])) {
          if($v == 'true') $v = true;
          else if($v == 'false') $v = false;
          else if($v == 'null') $v = null;
          else if(is_numeric($v)) $v = (float)$v;
          else if(($vj = json_decode($v))) $v = $vj;
        }
        $p[$k] = $v;
      }
    }

    return $params;
  }

  public function onHeaders(HTTP\ServerRequest $request) {
    if(!($dev = $this->_matchDevice($request->localPort, $request->localAddr)) || !$dev->connected()) {
      return 502;
    }

    if($request->hasHeader('Upgrade')) {
      return $request->routePath == '/rpc' ? parent::onHeaders($request) : 400;
    }
    if($request->routePath == '/rpc' && $request->getMethod() == 'POST') {
      $request->setBufferBody(true);
    }

    $request->attr->shelly_proxy_dev = $dev;
    return null;
  }

  public function onRequest(HTTP\ServerRequest $request) {
    if(!($dev = $request->attr->shelly_proxy_dev) || !$dev->connected()) {
      return 502;
    }

    $method = '';
    $params = [];
    $isPOST = false;
    $ffPOST = null;

    if($request->routePath == '/shelly') {
      $method = 'Shelly.GetDeviceInfo';
    } else {
      $method = substr($request->getPath(),5);
      $isPOST = $request->getMethod() == 'POST';
    }

    if($isPOST) {
      $params = json_decode($request->getBody());
      if(!$method) {
        $ffPOST = $params->id ?? 0;
        $method = $params->method ?? '';
        $params = $params->params ?? [];
      }
      $params = $params instanceOf \stdClass ? (array)$params : [];
    } else {
      $params = $this->_parseQuery($request->getQuery());
    }

    if($this->_denycmd[strtolower($method)] ?? null) {
      $msg = ['code'=>403, 'message'=>'Command not allowed'];

      if($ffPOST !== null) {
        $msg = [
          'id' => $ffPOST,
          'src' => $dev->getDevId() ?? 'proxy',
          'error' => $msg
        ];
      }

      return new HTTP\Response(
        403, json_encode($msg),
        ['Content-Type'=>'application/json']
      );
    }

    $res = new HTTP\Promise;

    $dev->proxyCommand($method, $params)->then(function($msg) use($ffPOST,$res) {
      $code = $msg->error->code ?? 200;
      $code = $code < 200 ? 500 : $code;

      if($ffPOST !== null) {
        $msg->id = $ffPOST;
        unset($msg->dst);
      } else {
        $msg = $msg->result ?? $msg->error;
      }

      $res->resolve(new HTTP\Response(
        $code, json_encode($msg),
        ['Content-Type'=>'application/json']
      ));
    });

    return $res;
  }

  protected function wsOpen(WS\Connection $conn) {
    if(!($dev = $this->_matchDevice($conn->localPort, $conn->localAddr)) || !$dev->connected()) {
      $conn->close();
      return;
    }

    $dev->once('close', function() use($conn) {
      $conn->close();
    });

    $conn->attr->shelly_proxy_dev = $dev;
    $conn->attr->shelly_proxy_src = null;

    $this->_conn[spl_object_id($dev)][$conn->ID] = $conn;
  }

  protected function wsMsg(WS\Connection $conn, string $json) {
    if(!($msg = json_decode($json))) return;

    $dev = $conn->attr->shelly_proxy_dev;
    $cmdId = $msg->id ?? 0;
    $cmdSrc = $msg->src ?? null;
    $method = $msg->method ?? '';
    $params = $msg->params ?? [];
    $params = $params instanceOf \stdClass ? (array)$params : [];
    $conn->attr->shelly_proxy_src = $cmdSrc;

    if($this->_denycmd[strtolower($method)] ?? null) {
      $conn->send(json_encode([
        'id' => $cmdId,
        'src' => $dev->getDevId() ?? 'proxy',
        'dst' => $cmdSrc ?? 'ws',
        'error' => ['code'=>403, 'message'=>'Command not allowed']
      ]));
      return;
    }

    $dev->proxyCommand($method, $params)->then(function($msg) use($conn,$cmdId,$cmdSrc) {
      if(!$conn->attr->shelly_proxy_dev) return;
      $msg->id = $cmdId;
      $msg->dst = $cmdSrc ?? 'ws';
      $conn->send(json_encode($msg));
    });
  }

  protected function wsClose(WS\Connection $conn) {
    $devid = spl_object_id($conn->attr->shelly_proxy_dev);
    $conn->attr->shelly_proxy_dev = null;
    unset($this->_conn[$devid][$conn->ID]);
  }

  public function registerDevice(Shelly\Device $dev, int $port, string $addr='') {
    $key = $addr.':'.$port;

    if(isset($this->_dev[$key])) {
      return;
    }

    $this->_httpd->listen($addr ?: '0.0.0.0', $port);

    if(!($dev instanceOf Device)) {
      $dev = new Device($dev);
    }

    $devid = spl_object_id($dev);
    $this->_conn[$devid] = [];
    $this->_dev[$key] = $dev;

    $dev->on('notify-raw', function($msg) use($devid) {
      if(empty($this->_conn[$devid])) return;
      foreach($this->_conn[$devid] as $conn) {
        if(!$conn->attr->shelly_proxy_src) continue;
        $msg->dst = $conn->attr->shelly_proxy_src;
        $conn->send(json_encode($msg));
      }
    });
  }

  public function disableCommand(string $method, bool $disable=true, int $port=0, string $addr='') {
    if($port) {
      if(!($dev = $this->_dev[$addr.':'.$port] ?? null)) return;
      $dev->disableCommand($method, $disable);
    } else if($disable) {
      $this->_denycmd[strtolower($method)] = true;
    } else {
      unset($this->_denycmd[strtolower($method)]);
    }
  }

  public function enableMDNS(mDNS\SimpleResponder $mdnsd, string $domain='', string $addr='') {
    $this->_mdnsd = $mdnsd;
    $this->_mdnsd_target = $domain;
    if($addr) $mdnsd->addRecordIPv4($domain, $addr, 120, true);
  }

  public function enableDeviceMDNS(int $port, string $addr='') {
    if(!$this->_mdnsd) {
      throw new \Exception('mDNS is not enabled');
    }
    if(!$this->_mdnsd_target && !$addr) {
      throw new \Exception('Can not enable mDNS for a proxy with no IPv4 address when global domain is not set');
    }
    if(!($dev = $this->_dev[$addr.':'.$port] ?? null)) {
      throw new \Exception('Proxy device '.$addr.':'.$port.' not found');
    }

    $shellyid = null;

    $dev->on('open', function($dev) use(&$shellyid,$port,$addr) {
      if(!$shellyid) {
        $pr = $dev->sendCommandSilent('Shelly.GetDeviceInfo');
        $pr = $pr->then(function($res) use(&$shellyid,$port,$addr) {
          if(!($res['id'] ?? null)) return null;

          $shellyid = $res['id'];
          $target = $this->_mdnsd_target;

          if($addr) {
            $target = $shellyid.'.local';
            $this->_mdnsd->addRecordIPv4($target, $addr, 120, true);
          }

          $this->_mdnsd->addService(
            '_http._tcp', $shellyid, 120, $target, $port,
            'gen='.$res['gen']
          );
          $this->_mdnsd->addService(
            '_shelly._tcp', $shellyid, 120, $target, $port,
            'gen='.$res['gen'], 'app='.$res['app'], 'ver='.$res['ver']
          );

          return $shellyid;
        });
      } else {
        $pr = Promise\resolve($shellyid);
      }

      $pr->then(function($shellyid) use($addr) {
        if(!$shellyid) return;

        if($addr) $this->_mdnsd->enableRecord($shellyid.'.local', DnsMessage::TYPE_A, $addr, true);
        $this->_mdnsd->enableService('_http._tcp', $shellyid, true);
        $this->_mdnsd->enableService('_shelly._tcp', $shellyid, true);
        $this->_mdnsd->advertiseService('_http._tcp', $shellyid);
        $this->_mdnsd->advertiseService('_shelly._tcp', $shellyid);
      });
    });

    $dev->on('close', function($clean=false) use(&$shellyid,$addr) {
      if(!$shellyid) return;

      $this->_mdnsd->advertiseService('_http._tcp', $shellyid, 0);
      $this->_mdnsd->advertiseService('_shelly._tcp', $shellyid, 0);

      if($clean) {
        if($addr) $this->_mdnsd->delRecord($shellyid.'.local', DnsMessage::TYPE_A, $addr);
        $this->_mdnsd->delService('_http._tcp', $shellyid);
        $this->_mdnsd->delService('_shelly._tcp', $shellyid);
      } else {
        if($addr) $this->_mdnsd->enableRecord($shellyid.'.local', DnsMessage::TYPE_A, $addr, false);
        $this->_mdnsd->enableService('_http._tcp', $shellyid, false);
        $this->_mdnsd->enableService('_shelly._tcp', $shellyid, false);
      }
    });
  }
}
