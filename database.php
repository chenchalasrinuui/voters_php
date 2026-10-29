<?php
class DB
{
  private $remoteHost = "sg2nlmysql25plsk.secureserver.net";
  private $localHost = "127.0.0.1";
  private $port = 3306;
  private $uname = "voters";
  private $pwd = "Voters$1234";
  private $db = "voters";

  public function getConnection()
  {
    // Try local MySQL first if in XAMPP, then remote
    $conn = @new mysqli($this->localHost, "root", "", $this->db, $this->port);
    if (!$conn->connect_error) {
      $conn->set_charset("utf8mb4");
      return $conn;
    }

    $conn = @new mysqli($this->remoteHost, $this->uname, $this->pwd, $this->db, $this->port);
    if ($conn->connect_error) {
      // Don't die() - throw exception so caller can catch and return valid JSON
      throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    return $conn;
  }

  public function getPDO()
  {
    $options = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
      PDO::ATTR_TIMEOUT => 2, // 2-second timeout to prevent connection hangs
    ];

    // Try local MySQL (XAMPP default) first
    try {
      $dsnLocal = "mysql:host={$this->localHost};port={$this->port};dbname={$this->db};charset=utf8mb4";
      return new PDO($dsnLocal, "root", "", $options);
    } catch (Exception $eLocal) {
      // Fallback to remote database with 2-second timeout
      $dsnRemote = "mysql:host={$this->remoteHost};port={$this->port};dbname={$this->db};charset=utf8mb4";
      return new PDO($dsnRemote, $this->uname, $this->pwd, $options);
    }
  }
}
