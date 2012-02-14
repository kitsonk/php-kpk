<?php

/**
 * This is a namespace that provides a class that allows centralised logging of events
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

namespace kpk\core\logging;

  /**
   * Class that handles Log Files
   * @author kitson.kelly (at) asseverate.co.uk
   *
   */
  class LogFile {
    const CHECK_INTERVAL = 20;
    const FILE_TS = 'Ymd_His';
    
    private $basefilename;
    private $append;
    private $maxsize;
    private $maxsegments;
    private $fh;
    private $filename;
    private $isOpen = false;
    private $writecount = 0;
    
    /**
     * Constructor Method
     * @param $basefilename string
     * @param $append bool
     * @param $maxsize integer
     * @param $maxsegments integer
     * @return LogFile
     */
    function __construct($basefilename='',$append=false,$maxsize=0,$maxsegments=0) {
      $this->basefilename = $basefilename;
      $this->append = $append;
      $this->maxsize = $maxsize;
      $this->maxsegments = $maxsegments;
    }
    
    /**
     * Destructor Method
     *
     */
    function __destruct() {
      if ($this->isOpen) {
        fclose($this->fh);
      }
    }
    
    /**
     * Cleans up the log files
     *
     */
    private function clean() {
      if ($this->maxsegments > 0) {
        $pathinfo = pathinfo($this->basefilename);
        $fileglob = '';
        if (!empty($pathinfo['dirname'])) {
          $fileglob .= $pathinfo['dirname'].DIRECTORY_SEPARATOR;
        }
        $fileglob .= $pathinfo['filename'].'.*.';
        if (!empty($pathinfo['extension'])) {
          $fileglob .= $pathinfo['extension'];
        } else {
          $fileglob .= 'log';
        }
        $files = glob($fileglob);
        sort($files);
        for ($i=0;$i<=(count($files)-$this->maxsegments-1);$i++) {
          unlink($files[$i]);
        }
      }
    }
    
    /**
     * Generates a new filename
     *
     */
    private function newfilename() {
      $pathinfo = pathinfo($this->basefilename);
      $filename = '';
      if (!empty($pathinfo['dirname'])) {
        $filename .= $pathinfo['dirname'].DIRECTORY_SEPARATOR;
      }
      $filename .= $pathinfo['filename'].'.'.date(self::FILE_TS).'.';
      if (empty($pathinfo['extension'])) {
        $filename .= 'log';
      } else {
        $filename .= $pathinfo['extension'];
      }
      return $filename;
    }
    
    /**
     * Opens or appends a the log file
     *
     */
    private function open() {
      $this->clean();
      $pathinfo = pathinfo($this->basefilename);
      if ($this->append) {
        $fileglob = '';
        if (!empty($pathinfo['dirname'])) {
          $fileglob .= $pathinfo['dirname'].'/';
        }
        $fileglob .= $pathinfo['filename'].'*';
        if (!empty($pathinfo['extension'])) {
          $fileglob .= '.'.$pathinfo['extension'];
        } else {
          $fileglob .= '.log';
        }
        $files = glob($fileglob);
        if (count($files) > 0) {
          rsort($files);
          $filename = $files[0];
          $size = filesize($filename);
          if ($size > $this->maxsize) {
            $filename = $this->newfilename();
          }
        } else {
          $filename = $this->newfilename();
        }
      } else {
        $filename = $this->newfilename();
      }
      $this->fh = fopen($filename,'a');
      $this->isOpen = true;
      $this->filename = $filename;
      $this->writecount = 0;
    }
    
    /**
     * Checks the log to see if it is too big
     *
     */
    private function check() {
      if ($this->isOpen) {
        fclose($this->fh);
        $this->fh = null;
        clearstatcache();
        $filesize = filesize($this->filename);
        if ($filesize>$this->maxsize) {
          $this->filename = $this->newfilename();
          $this->clean();
        }
        $this->fh = fopen($this->filename,'a');
      }
      $this->writecount = 0;
    }
    
    /**
     * Writes a line to the log file
     * @param $line string
     *
     */
    public function write($line) {
      if (!$this->isOpen) {
        $this->open();
      }
      if ($this->writecount >= self::CHECK_INTERVAL) {
        $this->check();
      }
      if ($this->isOpen) {
        fwrite($this->fh,$line."\n");
        $this->writecount++;
      }
    }
  }
  
  /**
   * A class the handles logging to the database
   * @author kitson.kelly (at) uk.didata.com
   *
   */
  class LogDatabase {
    private $db;
    private $maxrecords;
  }

  /**
   * Log Class to enable the logging of applications via PHP
   *
   * @author kitson.kelly (at) uk.didata.com
   *
   */
  class Log {
    
    const EVENT_DEBUG = 5;
    const EVENT_INFORMATION = 4;
    const EVENT_WARNING = 3;
    const EVENT_CRITICAL = 2;
    const EVENT_ERROR = 1;
    
    const LOG_TIME_FORMAT = 'Y-m-d H:i:s';
    
    public $LogLevelDatabase = self::EVENT_ERROR;
    public $LogLevelConsole = self::EVENT_ERROR;
    public $LogLevelSystemLog = self::EVENT_ERROR;
    public $LogLevelFile = self::EVENT_ERROR;
    public $FileName = './logfile';
    public $AppendFile = true;
    public $MaxFileSize = '10000000';
    public $MaxFileSegments = '20';
    
    public $DatabaseEnabled = false;
    public $ConsoleEnabled = true;
    public $SystemLoggerEnabled = false;
    public $FileEnabled = false;
    
    private $logfile;
    private $logdatabase;
    
    /**
     * Constructor Method
     *
     */
    function __construct() {
      
    }
    
    /**
     * Destructor Method
     *
     */
    function __destruct() {
      
    }
    
    /**
     * Prints a line to the enabled devices
     * @param $level const
     * @param $logLine string
     *
     */
    private function PrintLine($level,$logLine) {
      if ($this->ConsoleEnabled) {
        if ($level<=$this->LogLevelConsole) {
          print $logLine."\n";
        }
      }
      $logTimeStamp = date(self::LOG_TIME_FORMAT);
      $logLine = $logTimeStamp.' '.$logLine;
      if ($this->SystemLoggerEnabled) {
        if ($level<=$this->LogLevelSystemLog) {
          error_log($logLine);
        }
      }
      if ($this->FileEnabled) {
        if (!isset($this->logfile)) {
          $this->logfile = new LogFile($this->FileName,$this->AppendFile,$this->MaxFileSize,$this->MaxFileSegments);
        }
        if ($level<=$this->LogLevelFile) {
          $this->logfile->write($logLine);
        }
      }
    }
    
    /**
     * Prints the startup line for a process
     * @param $process string
     * @param $version string
     *
     */
    public function printStartup($process,$version) {
      $logTimeStamp = date(self::LOG_TIME_FORMAT);
      $logLine = $logTimeStamp.' '.$process.' ['.$version.'] Starting...';
      $this->PrintLine(self::EVENT_ERROR,$logLine);
    }
    
    /**
     * Log an Event
     * @param $component string
     * @param $message string
     * @param $level int[optional]
     *
     */
    public function logEvent($component='',$message,$level=self::EVENT_DEBUG) {
      $logLine = '';
      switch ($level) {
        case self::EVENT_ERROR:
          $logLine .= '[ERR]';
          break;
        case self::EVENT_CRITICAL:
          $logLine .= '[CRT]';
          break;
        case self::EVENT_WARNING:
          $logLine .= '[WRN]';
          break;
        case self::EVENT_INFORMATION:
          $logLine .= '[INF]';
          break;
        default:
          $logLine .= '[DBG]';
      }
      if (!empty($component)) {
        $logLine .= ' {'.$component.'}';
      }
      $logLine .= ' '.$message;
      $this->PrintLine($level,$logLine);
    }
  }

/* @var \bskyb\logging\Log */
$applog = new Log();
  
?>