<?php namespace SalesforceRestAPI;

use DateTime;

/**
 * The Salesforce REST API PHP Wrapper.
 *
 * This class connects to the Salesforce REST API and performs actions on that API
 *
 * @author  Anthony Humes <jah.humes@gmail.com>
 * @license GPL, or GNU General Public License, version 2
 */
class SalesforceAPI
{
    /**
     * @var string
     */
    private $accessToken;

    /**
     * @var string
     */
    protected $clientId;

    /**
     * @var string
     */
    protected $clientSecret;

    /**
     * @var int|string
     */
    protected $apiVersion;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var string
     */
    protected $instanceUrl;

    /**
     * @var string
     */
    protected $batchUrl;

    /**
     * @var string
     */
    protected $returnType;

    /**
     * @var array
     */
    protected $headers;

    /**
     * @var resource
     */
    private $handle;

    /**
     * @var mixed
     */
    public $lastResponse;

    // Supported request methods

    const METHOD_DELETE = 'DELETE';
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_PATCH = 'PATCH';

    // Return types
    const RETURN_OBJECT = 'object';
    const RETURN_ARRAY_A = 'array_a';

    const LOGIN_PATH = '/services/oauth2/token';
    const OBJECT_PATH = 'sobjects/';
    const GRANT_TYPE = 'password';

    /**
     * Constructs the SalesforceConnection.
     *
     * This sets up the connection to salesforce and instantiates all default variables
     *
     * @param string     $instanceUrl  The url to connect to
     * @param string|int $version      The version of the API to connect to
     * @param string     $clientId     The Consumer Key from Salesforce
     * @param string     $clientSecret The Consumer Secret from Salesforce
     */
    public function __construct($instanceUrl, $version, $clientId, $clientSecret, $returnType = self::RETURN_ARRAY_A)
    {
        // Instantiate base variables
        $this->instanceUrl = $instanceUrl;
        $this->apiVersion = $version;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->returnType = $returnType;

        $this->baseUrl = $instanceUrl;
        $this->instanceUrl = $instanceUrl . '/services/data/v' . $version . '/';
        $this->batchUrl = $instanceUrl . '/services/async/' . $version . '/job';

        $this->headers = [
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Logs in the user to Salesforce with a username, password, and security token.
     *
     * @param string $username
     * @param string $password
     * @param string $securityToken
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function login($username, $password, $securityToken)
    {
        $loginData = [
            'grant_type'    => self::GRANT_TYPE,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username'      => $username,
            'password'      => $password . $securityToken,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . '/services/oauth2/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $loginData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($ch);

        $this->checkForRequestErrors($ret, $ch);
        curl_close($ch);

        $ret = json_decode($ret);
        $this->afterLoginSetup($ret);

        return $ret;
    }

    /**
     * afterLoginSetup
     *
     * @param object $loginResponse json_decoded /services/oauth2/token response
     */
    protected function afterLoginSetup($loginResponse)
    {
        $this->accessToken = $loginResponse->access_token;
        $this->baseUrl = $loginResponse->instance_url;
        $this->instanceUrl = $loginResponse->instance_url . '/services/data/v' . $this->apiVersion . '/';
        $this->batchUrl = $loginResponse->instance_url . '/services/async/' . $this->apiVersion . '/job';
    }

    /**
     * Get a list of all the API Versions for the instance.
     *
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function getApiVersions()
    {
        return $this->httpRequest($this->baseUrl . '/services/data');
    }

    /**
     * Lists the limits for the organization. This is in beta and won't return for most people.
     *
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function getOrgLimits()
    {
        return $this->requestInstance('limits/');
    }

    /**
     * Gets a list of all the available REST resources.
     *
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function getAvailableResources()
    {
        return $this->requestInstance('');
    }

    /**
     * Get a list of all available objects for the organization.
     *
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function getAllObjects()
    {
        return $this->requestInstance(self::OBJECT_PATH);
    }

    /**
     * Get metadata about an Object.
     *
     * @param string   $objectName
     * @param bool     $all   Should this return all meta data including information about each field, URLs, and child relationships
     * @param DateTime $since Only return metadata if it has been modified since the date provided
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function getObjectMetadata($objectName, $all = false, DateTime $since = null)
    {
        $headers = [];
        // Check if the If-Modified-Since header should be set
        if ($since !== null && $since instanceof DateTime) {
            $headers['IF-Modified-Since'] = $since->format('D, j M Y H:i:s e');
        } elseif ($since !== null && !$since instanceof DateTime) {
            // If the $since flag has been set and is not a DateTime instance, throw an error
            throw new SalesforceAPIException('To get object metadata for an object, you must provide a DateTime object');
        }

        // Should this return all meta data including information about each field, URLs, and child relationships
        if ($all === true) {
            return $this->requestInstance(self::OBJECT_PATH . $objectName . '/describe/', [], self::METHOD_GET, $headers);
        } else {
            return $this->requestInstance(self::OBJECT_PATH . $objectName, [], self::METHOD_GET, $headers);
        }
    }

    /**
     * Create a new record.
     *
     * @param string $objectName
     * @param array  $data
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function create($objectName, $data)
    {
        return $this->requestInstance(self::OBJECT_PATH . (string)$objectName, $data, self::METHOD_POST);
    }

    /**
     * Update or Insert a record based on an external field and value.
     *
     *
     * @param string $objectName object_name/field_name/field_value to identify the record
     * @param array  $data
     * @return mixed
     *
     * @throws SalesforceAPIException
     */
    public function upsert($objectName, $data)
    {
        return $this->requestInstance(self::OBJECT_PATH . (string)$objectName, $data, self::METHOD_PATCH);
    }

    /**
     * Update an existing object.
     *
     * @param string $objectName
     * @param string $objectId
     * @param array  $data
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function update($objectName, $objectId, $data)
    {
        return $this->requestInstance(self::OBJECT_PATH . (string)$objectName . '/' . $objectId, $data, self::METHOD_PATCH);
    }

    /**
     * Delete a record.
     *
     * @param string $objectName
     * @param string $objectId
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function delete($objectName, $objectId)
    {
        return $this->requestInstance(self::OBJECT_PATH . (string)$objectName . '/' . $objectId, null, self::METHOD_DELETE);
    }

    /**
     * Get a record.
     *
     * @param string     $objectName
     * @param string     $objectId
     * @param array|null $fields
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function get($objectName, $objectId, $fields = null)
    {
        $params = [];

        // If fields are included, append them to the parameters
        if ($fields !== null && is_array($fields)) {
            $params['fields'] = implode(',', $fields);
        }

        return $this->requestInstance(self::OBJECT_PATH . (string)$objectName . '/' . $objectId, $params);
    }

    /**
     * Searches using a SOQL Query.
     *
     * @param string $query   The query to perform
     * @param bool   $all     Search through deleted and merged data as well
     * @param bool   $explain If the explain flag is set, it will return feedback on the query performance
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function search($query, $all = false, $explain = false)
    {
        $searchData = ['q' => $query];

        // If the explain flag is set, it will return feedback on the query performance
        if ($explain) {
            $searchData['explain'] = $searchData['q'];
            unset($searchData['q']);
        }

        // If is set, search through deleted and merged data as well
        if ($all) {
            $path = 'queryAll/';
        } else {
            $path = 'query/';
        }

        return $this->requestInstance($path, $searchData, self::METHOD_GET);
    }

    /**
     * @param string $query
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function getQueryFromUrl($query)
    {
        return $this->requestBase($query, [], self::METHOD_GET, []);
    }

    /**
     * Creates a new batch job instance
     *
     * @param string      $operation
     * @param string      $object
     * @param string      $contentType
     * @param string|bool $externalIdFieldName
     * @return Job
     * @throws SalesforceAPIException
     */
    public function createJob($operation, $object, $contentType, $externalIdFieldName = false)
    {
        $payload = [
            'operation'   => $operation,
            'object'      => $object,
            'contentType' => $contentType,
        ];

        if ($externalIdFieldName && $operation == Job::OPERATION_UPSERT) {
            $payload['externalIdFieldName'] = $externalIdFieldName;
        }

        $data = $this->httpBatchRequest('', $payload);

        return new Job($data);
    }

    /**
     * Resolves the given data to a Job Id
     *
     * @param mixed $data
     * @return string
     * @throws SalesforceAPIException
     */
    private function resolveToJobId($data)
    {
        $jobId = false;
        if (is_string($data)) {
            $jobId = $data;
        } elseif ($data instanceof Job) {
            $jobId = $data->id;
        }

        if (!$jobId) {
            throw new SalesforceAPIException('A Job ID or instance of Job must be provided.');
        }

        return $jobId;
    }

    /**
     * Resolves the given data to a BatchInfo Id
     *
     * @param mixed $data
     * @return string
     * @throws SalesforceAPIException
     */
    private function resolveToBatchInfoId($data)
    {
        $batchInfoId = false;
        if (is_string($data)) {
            $batchInfoId = $data;
        } elseif ($data instanceof BatchInfo) {
            $batchInfoId = $data->id;
        }

        if (!$batchInfoId) {
            throw new SalesforceAPIException('A BatchInfo ID or instance of BatchInfo must be provided.');
        }

        return $batchInfoId;
    }

    /**
     * Close an open job
     *
     * @param mixed $job
     * @return Job
     * @throws SalesforceAPIException
     */
    public function closeJob($job)
    {
        $jobId = $this->resolveToJobId($job);

        $payload = ['state' => Job::STATE_CLOSED];
        $data = $this->httpBatchRequest(sprintf('/%s', $jobId), $payload);

        if ($data['state'] != Job::STATE_CLOSED) {
            throw new SalesforceAPIException(sprintf('Job %s could not be closed.', $jobId));
        }

        return new Job($data);
    }

    /**
     * Close an open job
     *
     * @param mixed $job
     * @return Job
     * @throws SalesforceAPIException
     */
    public function abortJob($job)
    {
        $jobId = $this->resolveToJobId($job);

        $payload = ['state' => Job::STATE_ABORTED];
        $data = $this->httpBatchRequest(sprintf('/%s', $jobId), $payload);

        if ($data['state'] != Job::STATE_ABORTED) {
            throw new SalesforceAPIException(sprintf('Job %s could not be aborted.', $jobId));
        }

        return new Job($data);
    }

    /**
     * Return the job by Job ID
     *
     * @param mixed $jobId
     * @return Job
     * @throws SalesforceAPIException
     */
    public function getJob($job)
    {
        $jobId = $this->resolveToJobId($job);

        $data = $this->httpBatchRequest(sprintf('/%s', $jobId), [], self::METHOD_GET);

        return new Job($data);
    }

    /**
     * Get the information about a batch
     *
     * @param mixed $job
     * @return array
     * @throws SalesforceAPIException
     */
    public function getJobBatches($job)
    {
        $jobId = $this->resolveToJobId($job);
        $data = $this->httpBatchRequest(sprintf('/%s/batch', $jobId), [], self::METHOD_GET);

        if (!$job instanceof Job) {
            $job = $this->getJob($job);
        }

        $result = [];
        foreach ($data['batchInfo'] as $batch) {
            $result[] = new BatchInfo($batch, $job);
        }

        return $result;
    }

    /**
     * Add a batch to process inside a job
     *
     * @param mixed $job
     * @param mixed $payload
     * @return BatchInfo
     * @throws SalesforceAPIException
     */
    public function addBatch($job, $payload)
    {
        $jobId = $this->resolveToJobId($job);
        $data = $this->httpBatchRequest(sprintf('/%s/batch', $jobId), $payload);

        if (!$job instanceof Job) {
            $job = $this->getJob($job);
        }

        return new BatchInfo($data, $job);
    }

    /**
     * Get the information about a batch
     *
     * @param mixed $job
     * @param mixed $batchId
     * @return BatchInfo
     * @throws SalesforceAPIException
     */
    public function getBatchInfo($job, $batchInfo)
    {
        $jobId = $this->resolveToJobId($job);
        $batchId = $this->resolveToBatchInfoId($batchInfo);

        $data = $this->httpBatchRequest(sprintf('/%s/batch/%s', $jobId, $batchId), [], self::METHOD_GET);

        if (!$job instanceof Job) {
            $job = $this->getJob($job);
        }

        return new BatchInfo($data, $job);
    }

    /**
     * Get the results about a batch
     *
     * @param mixed $job
     * @param mixed $batchInfo
     * @return array
     * @throws SalesforceAPIException
     */
    public function getBatchResults($job, $batchInfo)
    {
        $jobId = $this->resolveToJobId($job);
        $batchId = $this->resolveToBatchInfoId($batchInfo);

        $data = $this->httpBatchRequest(sprintf('/%s/batch/%s/result', $jobId, $batchId), [], self::METHOD_GET);

        if (!$batchInfo instanceof BatchInfo) {
            $batchInfo = $this->getBatchInfo($job, $batchInfo);
        }

        $result = [];
        foreach ($data as $batchResult) {
            $result[] = new BatchResult($batchResult, $batchInfo);
        }

        return $result;
    }

    /**
     * Makes a request to the API using the base url and the given path using the access key.
     *
     * @param string $path
     * @param array  $params
     * @param string $method
     * @param array  $headers
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function requestBase($path, $params = [], $method = self::METHOD_GET, $headers = [])
    {
        return $this->request($this->baseUrl . $path, $params, $method, $headers);
    }

    /**
     * Makes a request to the API using the instance url and the given path using the access key.
     *
     * @param string $path
     * @param array  $params
     * @param string $method
     * @param array  $headers
     * @return mixed
     * @throws SalesforceAPIException
     */
    public function requestInstance($path, $params = [], $method = self::METHOD_GET, $headers = [])
    {
        return $this->request($this->instanceUrl . $path, $params, $method, $headers);
    }

    /**
     * Makes a request to the API using the access key.
     *
     * @param string $url
     * @param array  $params
     * @param string $method
     * @param array  $headers
     * @return mixed
     * @throws SalesforceAPIException
     */
    private function request($url, $params = [], $method = self::METHOD_GET, $headers = [])
    {
        if (!isset($this->accessToken)) {
            throw new SalesforceAPIException('You have not logged in yet.');
        }

        // Set the Authorization header
        $requestHeaders = [
            'Authorization' => 'Bearer ' . $this->accessToken,
        ];

        // Merge all the headers
        $requestHeaders = array_merge($requestHeaders, $headers);

        return $this->httpRequest($url, $params, $requestHeaders, $method);
    }

    /**
     * Makes an HTTP batch request
     *
     * @param string $path
     * @param array  $payload (default: [])
     * @param string $method  (default: 'POST')
     * @return mixed
     * @throws SalesforceAPIException
     */
    private function httpBatchRequest($path, $payload = [], $method = self::METHOD_POST)
    {
        if (!isset($this->accessToken)) {
            throw new SalesforceAPIException('You have not logged in yet.');
        }

        // Set the Authorization header (must be set as session, not Authorization Bearer)
        $requestHeaders = [
            'X-SFDC-Session' => $this->accessToken,
        ];

        return $this->httpRequest($this->batchUrl . $path, $payload, $requestHeaders, $method);
    }

    /**
     * Performs the actual HTTP request to the Salesforce API.
     *
     * @param string     $url
     * @param array|null $params
     * @param array|null $headers
     * @param string     $method
     * @return mixed
     * @throws SalesforceAPIException
     */
    private function httpRequest($url, $params = null, $headers = null, $method = self::METHOD_GET)
    {
        $this->handle = curl_init();
        $options = [
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_BUFFERSIZE     => 128000,
            CURLINFO_HEADER_OUT    => true,
        ];
        curl_setopt_array($this->handle, $options);

        // Set the headers
        if (isset($headers) && $headers !== null && !empty($headers)) {
            $requestHeaders = array_merge($this->headers, $headers);
        } else {
            $requestHeaders = $this->headers;
        }

        // Add any custom fields to the request
        if (isset($params) && $params !== null && !empty($params)) {
            if ($requestHeaders['Content-Type'] == 'application/json') {
                $jsonParams = json_encode($params);
                curl_setopt($this->handle, CURLOPT_POSTFIELDS, $jsonParams);
            } else {
                $httpParams = http_build_query($params);
                curl_setopt($this->handle, CURLOPT_POSTFIELDS, $httpParams);
            }
        }

        // Modify the request depending on the type of request
        switch ($method) {
            case 'POST':
                curl_setopt($this->handle, CURLOPT_POST, true);
                break;
            case 'GET':
                curl_setopt($this->handle, CURLOPT_HTTPGET, true);
                if (isset($params) && $params !== null && !empty($params)) {
                    $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                }
                break;
            default:
                curl_setopt($this->handle, CURLOPT_CUSTOMREQUEST, $method);
                break;
        }

        curl_setopt($this->handle, CURLOPT_URL, $url);
        curl_setopt($this->handle, CURLOPT_HTTPHEADER, $this->createCurlHeaderArray($requestHeaders));

        $response = curl_exec($this->handle);
        $response = $this->checkForRequestErrors($response, $this->handle);

        $result = false;
        if ($this->returnType === self::RETURN_OBJECT) {
            $result = json_decode($response);
        } elseif ($this->returnType === self::RETURN_ARRAY_A) {
            $result = json_decode($response, true);
        }

        curl_close($this->handle);

        return $result;
    }

    /**
     * Makes the header array have the right format for the Salesforce API.
     *
     * @param array $headers
     * @return array
     */
    private function createCurlHeaderArray($headers)
    {
        $curlHeaders = [];
        foreach ($headers as $key => $header) {
            $curlHeaders[] = $key . ': ' . $header;
        }

        return $curlHeaders;
    }

    /**
     * Checks for errors in a request.
     *
     * @see http://www.salesforce.com/us/developer/docs/api_rest/index_Left.htm#CSHID=errorcodes.htm|StartTopic=Content%2Ferrorcodes.htm|SkinName=webhelp
     *
     * @param string   $response The response from the server
     * @param Resource $handle   The CURL handle
     * @return string The response from the API
     * @throws SalesforceAPIException
     */
    private function checkForRequestErrors($response, $handle)
    {
        $curlError = curl_error($handle);
        if ($curlError !== '') {
            throw new SalesforceAPIException($curlError);
        }

        $requestInfo = curl_getinfo($handle);
        switch ($requestInfo['http_code']) {
            case 304:
                if ($response === '') {
                    return json_encode(['message' => 'The requested object has not changed since the specified time']);
                }
                break;
            case 300:
            case 200:
            case 201:
            case 204:
                if ($response === '') {
                    return json_encode(['success' => true]);
                }
                break;
            default:
                if (empty($response) || $response !== '') {
                    $err = new SalesforceAPIException($response, $requestInfo);
                    throw $err;
                } else {
                    $result = json_decode($response);
                    if (isset($result->error)) {
                        throw new SalesforceAPIException($result->error_description, $requestInfo);
                    }
                }
                break;
        }

        $this->lastResponse = $response;

        return $response;
    }
}
