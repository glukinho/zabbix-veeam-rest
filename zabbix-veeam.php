#!/usr/bin/php
<?php

// zabbix-veeam-rest
// ver 0.4
// glukinho@gmail.com
// https://github.com/glukinho/zabbix-veeam-rest

// usage: ./zabbix-veeam.php "<Veeam REST API URL>" "<action>" ["<data>"] [debug]
// debug means the script will write log to stdout without truncating large variables


$app = new AppVeeamZabbix($argv);
$app->run();



Class VeeamZabbixException extends Exception { }
Class BadParametersException extends VeeamZabbixException { }
Class VeeamAuthException extends VeeamZabbixException { }
Class BadDataException extends VeeamZabbixException { }
Class CurlErrorException extends VeeamZabbixException { }
Class JsonDecodeException extends VeeamZabbixException { }
Class BadActionNameException extends VeeamZabbixException { }


Class AppOptions
{
    public static $veeamUsername = 'username';    // change to your system
    public static $veeamPassword = 'password';         // change to your system
    public static $logFile = '/tmp/zabbix-veeam.log';   // change to your system
    public static $localTimezone = "Europe/Moscow";     // change to your system



    public static $logToFile = true;
    public static $logToStdout = false;         // set true for debug only!
    public static $loggerTruncateChars = 270;   // put to false or 0 to avoid truncating large variables
	public static $zabbixResults = [            // must fit Zabbix Value mapping "Veeam jobs result"
		'Success' => 0,
		'Warning' => 1,
		'Failed'  => 2,
        'None'    => 3,
	];
	public static $logDateTimeFormat = 'Y-m-d H:i:s';   // see DateTime class documentation
    public static $curlHeadersArr = [
        'Content-Type: application/xml; charset=utf-8',
        'Content-Length: 0',
        'Accept: application/json',
    ];
	public static $curlConfig = [
        CURLOPT_FAILONERROR    => true,
        CURLOPT_HEADER		   => false,
        CURLOPT_VERBOSE		   => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ];
    public static $veeamAuthUrl = "sessionMngr/?v=latest";  // Veeam 10
}


Class AppVeeamZabbix
{
    protected $runId;
	protected $veeamBaseUrl;
	protected $veeamConnector;

	public function __construct($argv)
	{
	    $this->runId = uniqid();
	    Logger::$logId = $this->runId;

	    Logger::dump($argv, __METHOD__ . ' - command line arguments passed');

		if ( isset($argv[2]) ) {
			$this->veeamBaseUrl = $argv[1];
			$this->action = $argv[2];
			$this->data = ( isset($argv[3]) ? $argv[3] : null );
			Logger::$isDebug = ( isset($argv[4]) ? (bool) $argv[4] : false );
		} else {
		    // at least 2 parameters needed
			throw new BadParametersException('at least 2 parameters needed');
		}
	}
	
	public function run()
    {
        $this->veeamConnector = new VeeamConnector($this->veeamBaseUrl);
        $this->action = Action::create($this->veeamConnector, $this->action, $this->data);
        $responseForZabbix = $this->action->run();

        die($responseForZabbix->toJson());
    }
}


Class VeeamConnector
{
    protected $veeamBaseUrl;
	protected $veeamCredEncoded;
    protected $restsvcsessionid = null;
	protected $ch; // curl


    public function __construct($veeamBaseUrl)
    {
        $this->veeamBaseUrl = $veeamBaseUrl;
        $this->veeamCredEncoded = base64_encode(AppOptions::$veeamUsername . ':' . AppOptions::$veeamPassword);
        $this->ch = curl_init();
    }


    public function request($endpoint, $queryArr, $httpMethod = 'GET')
    {
        $this->_auth();

        Logger::dump($endpoint, __METHOD__ . ' - endpoint');
        Logger::dump($queryArr, __METHOD__ . ' - queryArr');
        Logger::dump($httpMethod, __METHOD__ . ' - httpMethod');

        $fullUrl = $this->veeamBaseUrl . $endpoint . '?' . http_build_query($queryArr);

        $response = $this->_curl(
            $fullUrl,
            $httpMethod,
            [ 'X-RestSvcSessionId: '.$this->restsvcsessionid ]
        );

        $jsonDecodedResponse = json_decode($response);

        if(json_last_error() != JSON_ERROR_NONE) throw new JsonDecodeException;

        return new VeeamResponse($jsonDecodedResponse);
    }


    private function _curl($url, $httpMethod = 'GET', $headersArr = [], $curlOptsArr = [])
    {
        Logger::dump($url, __METHOD__ . ' - url');

        curl_setopt_array($this->ch, AppOptions::$curlConfig);
        curl_setopt_array($this->ch, $curlOptsArr);
        curl_setopt($this->ch,CURLOPT_URL, $url);
        curl_setopt($this->ch,CURLOPT_POST, ($httpMethod == 'POST' ? true : false));
        curl_setopt($this->ch,CURLOPT_HTTPHEADER, array_merge(AppOptions::$curlHeadersArr, $headersArr));

        $response = curl_exec($this->ch);

        if (curl_errno($this->ch)) {
            $error_msg = curl_error($this->ch);
            Logger::dump($error_msg, __METHOD__ . ' - curl error msg');
            throw new CurlErrorException('$error_msg');
        }

        return $response;
    }


    private function _handleCurlHeaders($ch, $headerLine)
    {
        $headerArr = explode(": ", $headerLine);
        if ($headerArr[0] == 'X-RestSvcSessionId') $this->restsvcsessionid = trim($headerArr[1]);
        return strlen($headerLine);
    }


    private function _auth()
	{
	    $authUrl = $this->veeamBaseUrl . AppOptions::$veeamAuthUrl;
	    Logger::dump($authUrl, __METHOD__ . ' - authUrl');
	    Logger::dump($this->veeamCredEncoded, __METHOD__ . ' - veeamCredEncoded');

	    $response = $this->_curl(
            $authUrl,
            'POST',
            [ 'Authorization: Basic ' . $this->veeamCredEncoded ],
            [ CURLOPT_HEADERFUNCTION => 'VeeamConnector::_handleCurlHeaders' ]
        );

        if (is_null($this->restsvcsessionid)) throw new VeeamAuthException('X-RestSvcSessionSd is null');

        Logger::dump($this->restsvcsessionid, __METHOD__ . ' - restsvcsessionid');

        return $this->restsvcsessionid;
	}
}


Class ZabbixProcessor
{
    protected $responseFromVeeam;

    public function __construct($responseFromVeeam)
    {
        $this->responseFromVeeam = $responseFromVeeam;
    }


    public function discoverAgentJobs()
    {
        return json_encode(['abc', 'def', ['qwrty' => 123123, 'dsldfjs', 'k2j31' => 'test'] ]);
    }

    private function _makeDiscoveryObject()
    {
        // foreach... with $obj->data->{#NAME}
    }

    private function _makeItemObject()
    {

    }



}


Class Action
{
    protected $data;
    protected $veeam;
    protected $veeamEndpoint;
    protected $veeamQueryArr;
    protected $responseFromVeeam;
    protected $responseToZabbix;

    public static function create(VeeamConnector $veeam, $actionName, $actionData = null)
    {
        $className = ucfirst($actionName) . 'Action';

        if (! class_exists($className)) throw new BadActionNameException("action {$className} not found");

        $actionObj = new $className($actionData);
        $actionObj->setVeeamConnector($veeam);
        $actionObj->setData($actionData);

        Logger::dump($actionObj, __METHOD__ . ' - $actionObj');

        return $actionObj;
    }

    public function setVeeamConnector(VeeamConnector $veeam)
    {
        Logger::dump($veeam, __METHOD__ . ' - $veeam connector object');

        $this->veeam = $veeam;
    }

    public function setData($data)
    {
        $this->data = $data;
    }

    public function run()
    {
        $this->responseFromVeeam = $this->requestVeeam();
        Logger::dump($this->responseFromVeeam, __METHOD__ . ' - $this->responseFromVeeam');

        $this->responseToZabbix = $this->createResponseForZabbix();
        Logger::dump($this->responseToZabbix, __METHOD__ . ' - $this->responseToZabbix');

        return $this->responseToZabbix;
    }

    public function requestVeeam()
    {
        return $this->veeam->request($this->veeamEndpoint, $this->veeamQueryArr);
    }
}


Class DiscoverRepoAction extends Action
{
    protected $veeamEndpoint = 'repositories';
    protected $veeamQueryArr = [
        'format' => 'Entity',
    ];

    public function createResponseForZabbix()
    {
        $responseForZabbixObj = new ZabbixDiscoveryResponse;

        foreach($this->responseFromVeeam->getData()->Repositories as $repo) {
            $responseForZabbixObj->addRow( [ '{#REPONAME}' => $repo->Name, ] );
        }

        return $responseForZabbixObj;
    }
}


Class DiscoverBackupJobsAction extends Action
{
    protected $veeamEndpoint = 'query';
    protected $veeamQueryArr = [
        'type' => 'Job',
        'filter' => "JobType==Backup,JobType==BackupCopy",
        'format' => 'entities',
    ];

    public function createResponseForZabbix()
    {
        $responseForZabbixObj = new ZabbixDiscoveryResponse;

        foreach($this->responseFromVeeam->getData()->Entities->Jobs->Jobs as $j) {
            $responseForZabbixObj
                ->addRow( [
                    '{#BACKUPJOBNAME}' => $j->Name,
                    '{#BACKUPJOBTYPE}' => $j->JobType,
                ] );
        }

        return $responseForZabbixObj;
    }
}


Class DiscoverReplicaJobsAction extends Action
{
    protected $veeamEndpoint = 'query';
    protected $veeamQueryArr = [
        'type' => 'Job',
        'filter' => "JobType==Replica",
        'format' => 'entities',
    ];

    public function createResponseForZabbix()
    {
        $responseForZabbixObj = new ZabbixDiscoveryResponse;

        foreach($this->responseFromVeeam->getData()->Entities->Jobs->Jobs as $j) {
            $html = new DOMDocument;
            $html->loadHTML($j->Description);
            $vars = $html->getElementsByTagName('zabbix_replica_time');

            $responseForZabbixObj
                ->addRow( [
                    '{#REPLICAJOBNAME}' => $j->Name,
                    '{#REPLICAJOBTIME}' => $vars->length > 0 ? $vars->item(0)->nodeValue : '{$VEEAM_REPLICA_FAILED_TIME}',
                    '{#REPLICAJOBSCHEDULE}' => $j->ScheduleConfigured ? 'true' : 'false',
                ] );
        }

        return $responseForZabbixObj;
    }
}


Class DiscoverAgentJobsAction extends Action
{
    protected $veeamEndpoint = 'query';
    protected $veeamQueryArr = [
        'type' => 'AgentBackupJob',
        'format' => 'entities',
    ];

    public function createResponseForZabbix()
    {
        $responseForZabbixObj = new ZabbixDiscoveryResponse;

        foreach($this->responseFromVeeam->getData()->Entities->AgentBackupJob->AgentBackupJobs as $j) {
            $responseForZabbixObj->addRow( [
                '{#AGENTJOBNAME}' => $j->Name
            ] );
        }

        return $responseForZabbixObj;
    }
}


Class GetRepoInfoAction extends Action
{
    protected $veeamEndpoint = 'repositories';
    protected $veeamQueryArr = [
        'format' => 'Entity',
    ];
    protected $comment = "gets info of given repo (total/free space)";

    public function __construct($data = null)
    {
        if (is_null($data)) throw new BadDataException('repo name not provided');
    }

    public function createResponseForZabbix()
    {
        $data = $this->responseFromVeeam->getData();

        $responseForZabbixObj = (new ZabbixItemResponse);

        foreach ($data->Repositories as $r) {
            if ($r->Name == $this->data) {
                $responseForZabbixObj
                    ->addField('Name', $r->Name)
                    ->addField('Capacity', $r->Capacity)
                    ->addField('FreeSpace', $r->FreeSpace);
            }
        }

        return $responseForZabbixObj;
    }
}


Class GetLastBackupJobInfoAction extends Action
{
    protected $veeamEndpoint = 'query';
    protected $veeamQueryArr = [
        'type' => 'BackupJobSession',
        'sortDesc' => 'EndTime',
        'pageSize' => 1,
        'format' => 'entities',
    ];
    protected $comment = "gets last job info for given JobName";

    public function __construct($data = null)
    {
        if (is_null($data)) throw new BadDataException('job name not provided');

        $this->veeamQueryArr['filter'] = "JobName==\"{$data}\"";
    }

    public function createResponseForZabbix()
    {
        $data = $this->responseFromVeeam->getData();
        $result = $data->Entities->BackupJobSessions->BackupJobSessions[0]->Result;
        $creationtime = (new DateTime($data->Entities->BackupJobSessions->BackupJobSessions[0]->CreationTimeUTC, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(AppOptions::$localTimezone));
        $endtime = (new DateTime($data->Entities->BackupJobSessions->BackupJobSessions[0]->EndTimeUTC, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(AppOptions::$localTimezone));

        $responseForZabbixObj = (new ZabbixItemResponse)
            ->addField('JobName', $data->Entities->BackupJobSessions->BackupJobSessions[0]->JobName)
            ->addField('Result', $result)
            ->addField('ResultNum', ZabbixResult::getResultInt($result))
            ->addField('CreationTime', $creationtime->format(AppOptions::$logDateTimeFormat))
            ->addField('CreationTimeEpoch', $creationtime->getTimestamp())
            ->addField('EndTime', $endtime->format(AppOptions::$logDateTimeFormat))
            ->addField('EndTimeEpoch', $endtime->getTimestamp())
            ->addField('Duration', $endtime->getTimestamp() - $creationtime->getTimestamp())
            ->addField('Comment', $this->comment);

        return $responseForZabbixObj;
    }
}


Class GetLastReplicaJobInfoAction extends Action
{
    protected $veeamEndpoint = 'query';
    protected $veeamQueryArr = [
        'type' => 'ReplicaJobSession',
        'sortDesc' => 'EndTime',
        'pageSize' => 1,
        'format' => 'entities',
    ];
    protected $comment = "gets last successful job info for given JobName";

    public function __construct($data = null)
    {
        if (is_null($data)) throw new BadDataException('job name not provided');

        $this->veeamQueryArr['filter'] = "JobName==\"{$data}\";Result==Success";
    }

    public function createResponseForZabbix()
    {
        $data = $this->responseFromVeeam->getData();
        $result = $data->Entities->ReplicaJobSessions->ReplicaJobSessions[0]->Result;
        $creationtime = (new DateTime($data->Entities->ReplicaJobSessions->ReplicaJobSessions[0]->CreationTimeUTC, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(AppOptions::$localTimezone));
        $endtime = (new DateTime($data->Entities->ReplicaJobSessions->ReplicaJobSessions[0]->EndTimeUTC, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(AppOptions::$localTimezone));

        $responseForZabbixObj = (new ZabbixItemResponse)
            ->addField('JobName', $data->Entities->ReplicaJobSessions->ReplicaJobSessions[0]->JobName)
            ->addField('Result', $result)
            ->addField('ResultNum', AppOptions::$zabbixResults[$result])
            ->addField('CreationTime', $creationtime->format(AppOptions::$logDateTimeFormat))
            ->addField('CreationTimeEpoch', $creationtime->getTimestamp())
            ->addField('EndTime', $endtime->format(AppOptions::$logDateTimeFormat))
            ->addField('EndTimeEpoch', $endtime->getTimestamp())
            ->addField('Duration', $endtime->getTimestamp() - $creationtime->getTimestamp())
            ->addField('Comment', $this->comment);

        return $responseForZabbixObj;
    }
}


Class ZabbixResponse
{
    protected $data;
}


Class ZabbixDiscoveryResponse extends ZabbixResponse
{
    public function __construct()
    {
        $this->data = [];
    }

    public function addRow($rowArr)
    {
        $this->data[] = (object) $rowArr;

        return $this;
    }

    public function toJson()
    {
        return json_encode( ['data' => $this->data ] );
    }
}


Class ZabbixItemResponse extends ZabbixResponse
{
    public function __construct()
    {
        $this->data = new StdClass;
    }

    public function addField($fieldName, $fieldValue)
    {
        $this->data->{$fieldName} = $fieldValue;

        return $this;
    }

    public function toJson()
    {
        return json_encode( $this->data );
    }
}


Class VeeamResponse
{
    protected $data;

    public function __construct(StdClass $veeamResponseObj)
    {
        $this->data = $veeamResponseObj;
    }

    public function getData()
    {
        return $this->data;
    }
}


Class ZabbixResult
{
    public static function getResultInt($resultString){
        if (array_key_exists($resultString, AppOptions::$zabbixResults)) {
            return AppOptions::$zabbixResults[$resultString];
        }

        return 99;
    }
}


Class Logger
{
    public static $logId;
    public static $isDebug;

    public static function dump($variable, $text = null, $notruncate = false)
    {
        $variableText = print_r($variable, true);

        // truncate on these conditions
        if (AppOptions::$loggerTruncateChars == false || $notruncate == true || self::$isDebug == false) {
            $variableText = (
                strlen($variableText) > AppOptions::$loggerTruncateChars
                    ? substr($variableText, 0, AppOptions::$loggerTruncateChars) . '...<truncated>'
                    : $variableText
            );
        }
        self::log((is_null($text) ? '' : $text . ' - ') . $variableText);
    }

    public static function log($text)
    {
        $textToLog = (new DateTime())->format(AppOptions::$logDateTimeFormat) . " - " . self::$logId . " - " . $text . "\n";

        if (AppOptions::$logToFile) file_put_contents(AppOptions::$logFile, $textToLog, FILE_APPEND);

        if (AppOptions::$logToStdout) echo $textToLog;

        if (self::$isDebug) echo $textToLog;
    }
}
