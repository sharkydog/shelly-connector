<?php
namespace SharkyDog\Shelly\Exception;

class ConnNotConnected extends Conn {
  public function __construct() {
    parent::__construct('Not connected', -2);
  }
}
