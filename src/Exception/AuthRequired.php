<?php
namespace SharkyDog\Shelly\Exception;

class AuthRequired extends Auth {
  public function __construct() {
    parent::__construct('Authentication required', 401);
  }
}
