<?php
namespace SharkyDog\Shelly\Exception;

class AuthFailed extends Auth {
  public function __construct() {
    parent::__construct('Authentication failed', 403);
  }
}
