<?php
session_start(); // Start the session at the top, before any output
require_once __DIR__ . '/cors.php';
require_once './jwt_helper.php';
$postdata = file_get_contents("php://input");
// convert json object data into php object
$data = json_decode($postdata, true);
$data = (Object) $data;
//Inclue external database file into currenct file
include_once './database.php';
//create object for DB class
$dbObj = new DB();
//Get Connect Object
$dbConn = $dbObj->getConnection();

$option = $data->option;
switch ($option) {
    case 0:
        $userId = $data->phone;
        if($data->mode=='E'){
            $userId = $data->email;
        }
        $token = JWTHandler::generateToken($userId);
        echo json_encode(["token" => $token]);
        break;
    case 1:
        $query = "SELECT *FROM employees where is_active='T'";
        $result = $dbConn->query($query);
        $rows = $result->num_rows;
        $brData = Array();
        if ($rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $brData[] = $row;
            }
        }
        echo json_encode($brData);
        break;
    case 2:
         $query = "SELECT *FROM employees where  phone='".$data->phone."'";
        $result = $dbConn->query($query);
        $rows = $result->num_rows;
        if ($rows > 0) {
          echo 2;
        }else{
        $query = "SELECT *FROM employees where  email='".$data->email."'";
        $result = $dbConn->query($query);
        $rows = $result->num_rows; 
        if ($rows > 0) {
          echo 3;
        }else{
        $query = "INSERT INTO employees (name,email,phone,address,role,is_active) VALUES ('".$data->name."','".$data->email."','".$data->phone."','".$data->address."','Employee','T')";
        $result = $dbConn->query($query);
        if($result==1){
            $message="Welcome to CAgMkR !!! Dear Employee your registration with CAgMkR is successful. Welcome to the team ! You can access your employee portal. For any assistance call 9110383335 or mail to camkr@cagmkr.com";
            $tempId='1707173884833762965';
            $queryString = http_build_query([
                'apikey' => 'ceee2c-74b7c3-27d875-6bba8b-41ce16',
                'senderid' => 'CAGMKR',
                'dest_mobileno' => $data->phone,
                'msgtype' => 'TXT',
                'dltentityid' => '1501459440000019495',
                'dltheaderid' => '1705173883905395515',
                'dlttempid' =>$tempId,
                'response' => 'Y',
                'message' => $message,
            ]);
            // API URL
            $url = "https://smsjust.com/sms/user/urlsms.php?$queryString";
            // Send request using cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $response = curl_exec($ch);
            curl_close($ch);
        }
        echo $result;
         }
        }
        break;
    case 3:
        $query="update employees set  is_active='F' where id=".$data->id;
        $records = $dbConn->query($query);
        echo $records;
        break;
    case 4:
        $query = "SELECT *FROM employees where  phone='".$data->phone."' and id !=".$data->id;
        $result = $dbConn->query($query);
        $rows = $result->num_rows;
        if ($rows > 0) {
          echo 2;
        }else{
        $query = "SELECT *FROM employees where  email='".$data->email."'and id !=".$data->id;
        $result = $dbConn->query($query);
        $rows = $result->num_rows; 
        if ($rows > 0) {
          echo 3;
        }else{
        $query="update employees set address='" . $data->address  . "',  email='" . $data->email  . "', phone='" . $data->phone  . "', name='" . $data->name  . "' where id=".$data->id;
        $result=$dbConn->query($query);
        echo $result;
        }
        }
       
        break;
}
?>

