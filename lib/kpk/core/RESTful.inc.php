<?php

/**
 * This Namespace encapsulates a RESTful type framework.  It takes care of most of the "dirty work" of managing 
 * a RESTful type solution in PHP.  The main object /RESTful/processor is what should essentially be implimented 
 * by the end user.  It provides 5 abstract methods which should be implimented to handle the HTTP verbs GET, 
 * POST, DELETE and OPTIONS.  HEAD is automatically handled by requesting a GET and just not returning any 
 * content, so implimentors don't need to worry about handling it explicity.
 * 
 * If an requestor support gzip/defalte compression, the RESTful processor will automatically compress the data
 * being sent.  This automatically decreases network bandwidth and the perception of latency.
 * 
 * Copyright 2011 Kitson P. Kelly, All Rights Reserved
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *  * Redistributions of source code must retain the above copyright notice, this
 *    list of conditions and the following disclaimer.
 *  * Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *  * Neither the name of Kitson P. Kelly nor the names of its contributors
 *    may be used to endorse or promote products derived from this software
 *    without specific prior written permission.
 *    
 *  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 *  ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 *  WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 *  DISCLAIMED.  IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE
 *  FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 *  DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 *  SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 *  CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 *  OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 *  OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 * 
 * @author dojo (at) kitsonkelly.com
 *
 */

namespace kpk\core\RESTful;

require_once('logging.inc.php');

/**
 * Processes the current request and returns a request object
 * @return \RESTful\request
 */
function processRequest() {
  $requestMethod = $_SERVER['REQUEST_METHOD'];
  $returnObject = new request();
  $data = array();
  
  switch ($requestMethod) {
    case 'GET' :
      $data = $_GET;
      break;
    case 'POST' :
      if (count($_POST) >= 1) {
        $data = $_POST;
      } else {
        $data['data'] = file_get_contents('php://input');
      }
      break;
    case 'PUT' :
      $data['data'] = file_get_contents('php://input');
      break;
  }
  
  $returnObject->method = $requestMethod;
  $returnObject->vars = $data;
  if ($requestMethod === 'GET') {
    if (count($returnObject->vars)) {
      foreach ($returnObject->vars as $key=>$value) {
        if (preg_match('/sort\(([_+-])(.+)\)/',$key,$attributes)) {
          //sort parameter
          if ($attributes[1] == '-') {
            $returnObject->sort[] = $attributes[2].' DESC';
          } else {
            $returnObject->sort[] = $attributes[2].' ASC';
          }
        } elseif (preg_match('/BETWEEN\s.+\sAND\s.+/',$value)) {
          //BETWEEN value
          $returnObject->filter[] = $key.' '.urldecode($value);
        } else {
          //equals filter
          if (preg_match('/\*/',$value)) {
            //LIKE matching
            $returnObject->filter[] = $key." LIKE '".sqlite_escape_string(strtr($value,'*','%'))."'";
          } else {
            $returnObject->filter[] = $key." = '".sqlite_escape_string(urldecode($value))."'";
          }
        }
      }
    }
  }
  if (isset($data['data'])) {
    $returnObject->data = json_decode($data['data'],true);
  }
  
  if ($_SERVER['SCRIPT_NAME'] !== $_SERVER['REQUEST_URI']) {
    $uri = explode('?',$_SERVER['REQUEST_URI']);
    $returnObject->path = explode('/',ereg_replace('^'.$_SERVER["SCRIPT_NAME"].'/','',$uri[0]));
    if (count($returnObject->path)&&!$returnObject->path[count($returnObject->path)-1]) {
      unset($returnObject->path[count($returnObject->path)-1]);
    }
    $returnObject->pathCount = count($returnObject->path);
  }
  
  return $returnObject;
}

/**
 * Gets the full location of the path for setting the Location: header
 * @param string $path
 * @return string
 */
function getLocation($path) {
  return $_SERVER["SCRIPT_NAME"].$path;
}

/**
 * Send a REST Response based on inputs
 * @param integer $status [opt]
 * @param string $body [opt]
 * @param boolean $sendData [opt]
 * @param string $contentType [opt]
 * @param string $contentRange [opt]
 * @param string $location [opt]
 * @param string $requestMethod [opt]
 */
function sendResponse($status=200,$body='',$sendData=true,$contentType='text/html',$contentRange='',$location='',$requestMethod='GET') {
  $statusHeader = 'HTTP/1.1 '.$status.' '.getStatusCodeMessage($status);
  header($statusHeader);
  header('Content-type: '.$contentType);
  if ($contentRange) {
    header('Content-Range: '.$contentRange);
  }
  if ($location) {
    header('Location: '.$location);
  }
  if (($status != 204)&&($requestMethod !== 'HEAD')) { // 204 = No Content and HEAD returns no body
    // Enables gzip/deflate compression if the browser supports it
    if(!ob_start("ob_gzhandler")) ob_start();
    if ($body) {
      print $body;
    } elseif ($sendData) {
      $message = '';
      switch ($status) {
        case 400 :
          $message = 'You have specified an invalid resource.';
        case 401 :
          $message = 'Your must be authorised to view this page.';
          break;
        case 404 :
          $message = 'The requested resource <code>'.$_SERVER['REQUEST_URI'].'</code> was not found.';
          break;
        case 406 :
          $message = 'The requested resource <code>'.$_SERVER['REQUEST_URI'].'</code> is unacceptable.';
          break;
        case 500 :
          $message = 'The server encountered an error processing your request.';
          break;
        case 501 :
          $message = 'The requested method is not implemented.';
          break;
        default :
          $message = getStatusCodeMessage($status);
      }
      
      $signature = ($_SERVER['SERVER_SIGNATURE'] == '') ? $_SERVER['SERVER_SOFTWARE'] . ' Server at ' . $_SERVER['SERVER_NAME'] . ' Port ' . $_SERVER['SERVER_PORT'] : $_SERVER['SERVER_SIGNATURE']; 
      
      $body = '<!DOCTYPE html>
      		<html lang="en">
      			<head>
      				<meta charset="UTF-8">
      				<title>'.$status.' '.getStatusCodeMessage($status).'</title>
      			</head>
      			<body>
      				<h1>'.$status.' &mdash; '.getStatusCodeMessage($status).'</h1>
      				<p>'.$message.'</p>
      				<hr>
      				<address>'.$signature.'</address>
      			</body>
      		</html>'."\n";
      print $body;
      // Causes response data to be flushed and sent to client
      ob_end_flush();
    }
  }
}

/**
 * Returns the text for a particular HTTP status code
 * @param integer $status
 * @return string
 */
function getStatusCodeMessage($status) {
  $codes = Array(  
    100 => 'Continue',  
    101 => 'Switching Protocols',  
    200 => 'OK',  
    201 => 'Created',  
    202 => 'Accepted',  
    203 => 'Non-Authoritative Information',  
    204 => 'No Content',  
    205 => 'Reset Content',  
    206 => 'Partial Content',  
    300 => 'Multiple Choices',  
    301 => 'Moved Permanently',  
    302 => 'Found',  
    303 => 'See Other',  
    304 => 'Not Modified',  
    305 => 'Use Proxy',  
    306 => '(Unused)',  
    307 => 'Temporary Redirect',  
    400 => 'Bad Request',  
    401 => 'Unauthorized',  
    402 => 'Payment Required',  
    403 => 'Forbidden',  
    404 => 'Not Found',  
    405 => 'Method Not Allowed',  
    406 => 'Not Acceptable',  
    407 => 'Proxy Authentication Required',  
    408 => 'Request Timeout',  
    409 => 'Conflict',  
    410 => 'Gone',  
    411 => 'Length Required',  
    412 => 'Precondition Failed',  
    413 => 'Request Entity Too Large',  
    414 => 'Request-URI Too Long',  
    415 => 'Unsupported Media Type',  
    416 => 'Requested Range Not Satisfiable',  
    417 => 'Expectation Failed',  
    500 => 'Internal Server Error',  
    501 => 'Not Implemented',  
    502 => 'Bad Gateway',  
    503 => 'Service Unavailable',  
    504 => 'Gateway Timeout',  
    505 => 'HTTP Version Not Supported'  
  );
  return (isset($codes[$status])) ? $codes[$status] : '';  
}

/**
 * Object Class to Hold an REST Request Structure
 * @author kitson.kelly at asseverate.co.uk
 *
 */
class request {
  public $vars = array();
  public $sort = array();
  public $filter = array();
  public $data;
  public $httpAccept;
  public $method = 'GET';
  public $path = array();
  public $pathCount;
  public $range;
  public $rangeOffset;
  public $rangeCount;
  
  function __construct() {
    $this->httpAccept = (strpos($_SERVER['HTTP_ACCEPT'], 'json')) ? 'json' : 'xml';
    if (key_exists('HTTP_RANGE',$_SERVER)) {
      $this->range = $_SERVER['HTTP_RANGE'];
      if (preg_match('/items=(\d*)-(\d*)/',$_SERVER['HTTP_RANGE'],$attributes)) {
        $start = $attributes[1];
        $end = $attributes[2];
        $this->rangeOffset = $start;
        if ($end !== '') {
          $this->rangeCount = $end - $start + 1;
        } else {
          $this->rangeCount = 0;
        }
      }
    }
  }
}

/**
 * Abstract class that creates the framework for a REST processor
 * @author kitson.kelly at asseverate.co.uk
 *
 */
abstract class processor {
  const TYPE_JSON = 'application/json';
  const TYPE_HTML = 'text/html';
  const TYPE_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
  
  /**
   * @var \kpk\core\RESTful\request
   */
  protected $request;
  /**
   * @var \kpk\core\logging\Log
   */
  protected $logger;
  public $responseCode = 200;
  public $responseData = '';
  public $responseSendData = true;
  public $responseType = self::TYPE_HTML;
  public $responseRange = '';
  public $responseLocation = '';
  
  function __construct() {
    global $applog;
    $this->request = processRequest();
    if (isset($applog)) {
      $this->logger = $applog;
    }
  }
  
  /**
   * Gets the filter from the current request
   * @return string
   */
  protected function getFilter() {
    if (count($this->request->filter)) {
      return implode(' AND ',$this->request->filter);
    } else {
      return '';
    }
  }
  
  /**
   * Gets the sort from the current request
   * @return string
   */
  protected function getSort() {
    if (count($this->request->sort)) {
      return implode(',',$this->request->sort);
    } else {
      return '';
    }
  }
  
  /**
   * Sends the response back to the client
   */
  protected function sendResponse() {
    sendResponse($this->responseCode,$this->responseData,$this->responseSendData,$this->responseType,$this->responseRange,$this->responseLocation,$this->request->method);
  }
  
  /**
   * The the results as a JSON encoded string
   * @param array $results [opt]
   * @return boolean
   */
  protected function sendResults(array $results = null) {
    if (($this->responseCode >= 200) && ($this->responseCode < 300)) {
      if ($this->responseCode == 200) {
        $this->responseData = json_encode($results);
        $this->responseType = self::TYPE_JSON;
      } else {
        $this->responseSendData = false;
      }
      $this->sendResponse();
      return true;
    } else {
      return false;
    }
  }
  
  /**
   * Sets the range to return in the response
   * @param integer $start
   * @param integer $count
   * @param integer $total
   */
  protected function setResponseRange($start,$count,$total) {
    if ($count) {
      $this->responseRange = 'items '.$start.'-'.($start+$count-1).'/'.$total;
    } else {
      $this->responseRange = '/'.$total;
    }
  }
  
  abstract protected function get();
  
  private function head() {
    return $this->get();
  }
  
  abstract protected function post();
  
  abstract protected function put();
  
  abstract protected function delete();
  
  abstract protected function options();
  
  /**
   * Main function that causes the object to process the inbound request
   * @return boolean
   */
  public function process() {
    switch ($this->request->method) {
      case 'GET' :
        return $this->get();
        break;
      case 'HEAD' :
        return $this->head();
        break;
      case 'POST' :
        return $this->post();
        break;
      case 'PUT' :
        return $this->put();
        break;
      case 'DELETE' :
        return $this->delete();
        break;
      case 'OPTIONS' :
        return $this->options();
        break;
      default :
        $this->responseCode = 501; //METHOD NOT IMPLIMENTED
        sendResponse($this->responseCode);
        return true;
    }
  }
  
}
?>