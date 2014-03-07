<?php

namespace Services;
use Models\JobStatus,
    Library\HttpRequest;

class Jenkins
{
    private $_host;
    private $_user;
    private $_pass;
    private $_jobs;
    private $_httpRequest;
    private $_log;

    public function __construct($config, HttpRequest $httpRequest, $log)
    {
        $this->_host = $config['jenkins']['host'];
        $this->_user = $config['jenkins']['user'];
        $this->_pass = $config['jenkins']['pass'];
        $this->_jobs = $config['jenkins']['jobs'];
        $this->_log = $log;
        $this->_httpRequest = $httpRequest;
    }

    public function push($job)
    {
        $url = $this->_getUrlForJob($job);
        $url .= '/buildWithParameters?';
        $url .= 'env=' . $job->getTargetEnvironment();
        $url .= '&repo=' . $job->getTargetModule();
        $url .= '&revision=' . $job->getTargetVersion();
        return $this->_doPush($job, $url);
    }

    public function pushLive($job)
    {
        $url = $this->_getLiveUrlForJob($job);
        $url .= '/buildWithParameters?';
        $url .= 'tag=' . $job->getTargetVersion();
        return $this->_doPush($job, $url);
    }
    

    public function getLastBuildStatus($job)
    {
        $url = $this->_getUrlForJob($job);
        $url .= "/" . $job->getDeploymentJobId(); // new
        $url .= "/api/json";
        $this->_httpRequest->setUrl($url);
        $rawResponse = $this->_send();
        $jsonResponse = json_decode($rawResponse['body'], true);
        return $jsonResponse['result']; 
    }

    public function getLastBuildId($job)
    {
        $url = "";
        if ( ($job->getStatus() == JobStatus::QUEUED) or ($job->getStatus() == JobStatus::DEPLOYING)) {
            $url = $this->_getUrlForJob($job);
        } else {    
            $url = $this->_getLiveUrlForJob($job);
        }
        $url .= '/lastBuild/api/json';
        $this->_httpRequest->setUrl($url);
        $rawResponse = $this->_send();
        $jsonResponse = json_decode($rawResponse['body'], true);
        return $jsonResponse['number']; 
    }

    public function notifyResult($job, $status)
    {
        $url = 'http://' . $job->getRequestorJenkins() . "/job/";
        $url .= "/{$this->_jobs['notifications']}/";
        $url .= 'buildWithParameters?';
        $url .= 'env=' . $job->getTargetEnvironment();
        $url .= '&repo=' . $job->getTargetModule();
        $url .= '&revision=' . $job->getTargetVersion();
        $url .= '&status=' . $status;       
        $url .= '&jobId=' . $job->getId();
        $this->_httpRequest->setUrl($url);
        
        return $this->_send();
    }
    
    public function ping()
    {
        $url = $this->_host;
        $this->_httpRequest->setUrl($url); 
        $rawResponse = $this->_send();
        if ($this->_httpRequest->getResponseCode() != 200) {
            throw new \Exception(); 
        } 
    }

    private function _send()
    {
        try {
            $this->_log->addInfo("Calling Jenkins:" . $this->_httpRequest->getUrl());
            $httpAuth = $this->_user . ':' . $this->_pass;
            $this->_httpRequest->setOptions(array('httpauth' => $httpAuth));
            $response = $this->_httpRequest->send();
            if ($this->_httpRequest->getResponseCode() > 400) {
                $this->_log->addError("Error while calling jenkins");            
                throw new \Exception();
            }
        } catch (\Exception $e) {
            $this->_log->addError($e->getMessage());            
            throw $e;
        }
        return $response;
    }

    public function getBuildUrl($job)
    {
        $url = $this->_getUrlForJob($job) . "/" . $job->getDeploymentJobId();
        
        return $url;        
    }

    public function getRequestorJobConsoleUrl($job)
    {
        $url = $job->getRequestorJenkins();
        return empty($url) ? "Not available" : $url . "/console";
    }

    public function getTestJobConsoleUrl($job)
    {
        $url = $job->getTestJobUrl();
        return empty($url) ? "Not available" : $url . "/console";
    }

    public function getLiveJobConsoleUrl($job)
    {
        $url = $this->_getLiveUrlForJob($job) . "_LIVE/lastBuild/console";
        return $url;
    }

    private function _getUrlForJob($job)
    {
        $url = $this->_host . "/job/" . 
            $this->_jobs['prefix'] . $job->getTargetModule();
        
        return $url;
    }

    private function _getLiveUrlForJob($job)
    {
        $url = $this->_host . "/job/" . 
            $this->_jobs['liveprefix'] . ucfirst($job->getTargetModule());
    }

    private function _doPush($job, $pushUrl)
    {
        try {
            $currentBuildId = 0; 
            $lastBuildId = $this->getLastBuildId($job);
            $this->_log->addInfo("lastBuildId: " . $lastBuildId);  
            $httpAuth = $this->_user . ':' . $this->_pass;
            $this->_httpRequest->setUrl($pushUrl);
            $this->_httpRequest->setOptions(array('httpauth' => $httpAuth));
            $this->_log->addInfo("About calling JenkinsRM to queue job"); 
            $this->_send();
            $this->_log->addInfo("JenkinsRM called"); 
            while ((! is_numeric($currentBuildId)) || ($lastBuildId>=$currentBuildId)) {
                $this->_log->addInfo("lastBuildId: " . $lastBuildId);  
                $this->_log->addInfo("currentBuildId: " . $currentBuildId);  
                sleep(2);  
                $job->setDeploymentJobId($this->getLastBuildId($job));
                $currentBuildId = $job->getDeploymentJobId();  
            }
            $this->_log->addInfo("currentBuildIdAssigned: " . $job->getDeploymentJobId());  
            return true;
        } catch (\Exception $ex) {
            $this->_log->addError("Error while pushing Job to Jenkins RM:" . $ex->getMessage());  
            return false;
        }

    }
}
