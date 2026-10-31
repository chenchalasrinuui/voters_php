<?php
// backend/database.php - Direct Server Database Connection
class DB
{
  private $host = "sg2nlmysql25plsk.secureserver.net";
  private $port = 3306;
  private $uname = "voters";
  private $pwd = "Voters$1234";
  private $db = "voters";

  public function getConnection()
  {
    $hostWithPort = "{$this->host}:{$this->port}";
    $conn = new mysqli($hostWithPort, $this->uname, $this->pwd, $this->db);
    if ($conn->connect_error) {
      throw new Exception("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    return $conn;
  }

  public function getPDO()
  {
    $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db};charset=utf8mb4";
    $options = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
      PDO::ATTR_TIMEOUT => 7,
    ];
    return new PDO($dsn, $this->uname, $this->pwd, $options);
  }
}
