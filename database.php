<?php
// backend/database.php - Direct Server Database Connection with Local XAMPP Fallback
class DB
{
  private $host = "sg2nlmysql25plsk.secureserver.net";
  private $port = 3306;
  private $uname = "voters";
  private $pwd = "Voters$1234";
  private $db = "voters";

  public function getConnection()
  {
    // Try remote database first
    try {
      $hostWithPort = "{$this->host}:{$this->port}";
      $conn = @new mysqli($hostWithPort, $this->uname, $this->pwd, $this->db);
      if (!$conn->connect_error) {
        $conn->set_charset("utf8mb4");
        return $conn;
      }
    } catch (Throwable $e) {}

    // Fallback to local XAMPP MySQL
    try {
      $conn = @new mysqli("127.0.0.1", "root", "", $this->db, 3306);
      if (!$conn->connect_error) {
        $conn->set_charset("utf8mb4");
        return $conn;
      }
    } catch (Throwable $e) {}

    throw new Exception("MySQL Database Connection failed.");
  }

  public function getPDO()
  {
    $options = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
      PDO::ATTR_TIMEOUT => 4,
    ];

    // Try remote database first
    try {
      $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db};charset=utf8mb4";
      return new PDO($dsn, $this->uname, $this->pwd, $options);
    } catch (Throwable $e) {
      // Fallback to local XAMPP MySQL (standard root user with empty password)
      try {
        $localDsn = "mysql:host=127.0.0.1;port=3306;dbname={$this->db};charset=utf8mb4";
        return new PDO($localDsn, "root", "", $options);
      } catch (Throwable $localE) {
        throw $e;
      }
    }
  }
}
