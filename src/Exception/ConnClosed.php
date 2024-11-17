<?php
namespace SharkyDog\Shelly\Exception;

class ConnClosed extends Conn {
  public function __construct() {
    parent::__construct('Connection closed', -1);
  }
}
