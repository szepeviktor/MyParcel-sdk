<?php /** @noinspection PhpInternalEntityUsedInspection */
/**
 * This model represents one request
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/sdk
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Sdk\src\Model;

use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
use MyParcelNL\Sdk\src\Helper\RequestError;
use MyParcelNL\Sdk\src\Support\Arr;
use MyParcelNL\Sdk\src\Helper\MyParcelCurl;

class MyParcelRequest
{
    /**
     * API URL
     */
    const REQUEST_URL = 'https://api.myparcel.nl';

    /**
     * Supported request types.
     */
    const REQUEST_TYPE_SHIPMENTS = 'shipments';
    const REQUEST_TYPE_RETRIEVE_LABEL = 'shipment_labels';

    /**
     * API headers
     */
    const REQUEST_HEADER_SHIPMENT = 'Content-Type: application/vnd.shipment+json; charset=utf-8';
    const REQUEST_HEADER_RETRIEVE_SHIPMENT = 'Accept: application/json; charset=utf8';
    const REQUEST_HEADER_RETRIEVE_LABEL_LINK = 'Accept: application/json; charset=utf8';
    const REQUEST_HEADER_RETRIEVE_LABEL_PDF = 'Accept: application/pdf';
    const REQUEST_HEADER_RETURN = 'Content-Type: application/vnd.return_shipment+json; charset=utf-8';
    const REQUEST_HEADER_DELETE = 'Accept: application/json; charset=utf8';

    /**
     * Delivery type
     */
    const MORNING       = 'morning';
    const STANDARD      = 'standard';
    const NIGHT         = 'night';
    const AVOND         = 'avond';
    const RETAIL        = 'retail';
    const RETAILEXPRESS = 'retailexpress';

    /**
     * @var string
     */
    private $api_key = '';
    private $header = [];
    private $body = '';
    private $error = null;
    private $result = null;
    private $userAgent = null;

    /**
     * Get an item from tje result using "dot" notation.
     *
     * @param string $key
     * @param string $pluck
     *
     * @return mixed
     */
    public function getResult($key = null, $pluck = null)
    {
        if (null === $key) {
            return $this->result;
        }

        $result = Arr::get($this->result, $key);
        if ($pluck) {
            $result = Arr::pluck($result, $pluck);
        }
        return $result;
    }

    /**
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }


    /**
     * Sets the parameters for an API call based on a string with all required request parameters and the requested API
     * method.
     *
     * @param string $apiKey
     * @param string $body
     * @param string $requestHeader
     *
     * @return $this
     */
    public function setRequestParameters($apiKey, $body = '', $requestHeader = '')
    {
        $this->api_key = $apiKey;
        $this->body = $body;

        $header[] = $requestHeader . 'charset=utf-8';
        $header[] = 'Authorization: basic ' . base64_encode($this->api_key);

        $this->header = $header;

        return $this;
    }

    /**
     * send the created request to MyParcel
     *
     * @param string $method
     *
     * @param string $uri
     *
     * @return MyParcelRequest|array|false|string
     * @throws \Exception
     */
    public function sendRequest($method = 'POST', $uri = self::REQUEST_TYPE_SHIPMENTS)
    {
        if (!$this->checkConfigForRequest()) {
            return false;
        }

        $request = $this->instantiateCurl();

        $this->setUserAgent();

        $header = $this->header;
        $url = $this->getRequestUrl($uri);
        if ($method !== 'POST' && $this->body) {
            $url .= '/' . $this->body;
        }

        $request->write($method, $url, $header, $this->body);

        $this->setResult($request);
        $request->close();

        if ($this->getError()) {
            throw new \Exception('Error in MyParcel API request: ' . $this->getError() . ' Url: ' . $url . ' Request: ' . $this->body);
        }

        return $this;
    }

    /**
     * Check if MyParcel gives an error
     *
     * @return $this|void
     */
    private function checkMyParcelErrors()
    {
        if (!is_array($this->result) || empty($this->result['errors'])) {
            return;
        }

        $error = reset($this->result['errors']);
        if ((int) key($error) > 0) {
            $error = current($error);
        }

        $this->error = RequestError::getTotalMessage($error, $this->result);
    }

    /**
     * Get request url
     *
     * @param string $uri
     *
     * @return string
     */
    private function getRequestUrl($uri)
    {
        $url = self::REQUEST_URL . '/' . $uri;

        return $url;
    }

    /**
     * Checks if all the requirements are set to send a request to MyParcel
     *
     * @return bool
     * @throws \Exception
     */
    private function checkConfigForRequest()
    {
        if (empty($this->api_key)) {
            throw new \Exception('api_key not found');
        }

        return true;
    }

    /**
     * @return string
     */
    public function getUserAgent()
    {
        return $this->userAgent;
    }

    /**
     * @param string $userAgent
     * @return $this
     */
    public function setUserAgent($userAgent = null)
    {
        if ($userAgent) {
            $this->userAgent = $userAgent;
        }
        if ($this->getUserAgent() == null && $this->getUserAgentFromComposer() !== null) {
            $this->userAgent = trim($this->getUserAgent() . ' ' . $this->getUserAgentFromComposer());
        }

        return $this;
    }

    /**
     * Get version of SDK from composer file
     */
    public function getUserAgentFromComposer()
    {
        $composerData = file_get_contents($this->getComposerPath());
        $jsonComposerData = json_decode($composerData, true);

        if (!empty($jsonComposerData['name'])
            && $jsonComposerData['name'] == 'myparcelnl/sdk'
            && !empty($jsonComposerData['version'])
        ) {
            $version = str_replace('v', '', $jsonComposerData['version']);
        } else {
            $version = 'unknown';
        }

        return 'MyParcelNL-SDK/' . $version;
    }

    /**
     * Get composer.json
     *
     * @return string|null
     */
    private function getComposerPath()
    {
        $composer_locations = [
            'vendor/myparcelnl/sdk/composer.json',
            './composer.json'
        ];

        foreach ($composer_locations as $composer_file) {
            if (file_exists($composer_file)) {
                return $composer_file;
            }
        }
        return null;
    }

    /**
     * @param $size
     * @param MyParcelCollection $collection
     * @param $key
     * @return string|null
     */
    public function getLatestDataParams($size, $collection, &$key)
    {
        $params = null;
        $consignmentIds = $collection->getConsignmentIds($key);

        if ($consignmentIds !== null) {
            $params = implode(';', $consignmentIds) . '?size=' . $size;
        } else {
            $referenceIds = $this->getConsignmentReferenceIds($collection, $key);
            if (! empty($referenceIds)) {
                $params = '?reference_identifier=' . implode(';', $referenceIds) . '&size=' . $size;
            }
        }

        return $params;
    }

    /**
     * Get all consignment ids
     *
     * @param MyParcelCollection|MyParcelConsignment[] $consignments
     * @param $key
     *
     * @return array
     */
    private function getConsignmentReferenceIds($consignments, &$key)
    {
        $referenceIds = [];
        foreach ($consignments as $consignment) {
            if ($consignment->getReferenceId()) {
                $referenceIds[] = $consignment->getReferenceId();
                $key = $consignment->getApiKey();
            }
        }

        return $referenceIds;
    }

    /**
     * @param MyParcelCurl $request
     */
    private function setResult($request)
    {
        $response = $request->read();

        if (preg_match("/^%PDF-1./", $response)) {
            $this->result = $response;
        } else {
            $this->result = json_decode($response, true);

            if ($response === false) {
                $this->error = $request->getError();
            }
            $this
                ->checkMyParcelErrors();
        }
    }

    /**
     * @return MyParcelCurl
     */
    private function instantiateCurl()
    {
        return (new MyParcelCurl())
            ->setConfig([
                'header' => 0,
                'timeout' => 60,
            ])
            ->addOptions([
                CURLOPT_POST => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_AUTOREFERER => true,
            ]);
    }
}
