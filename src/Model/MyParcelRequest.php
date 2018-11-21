<?php
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
     * @param string $key
     * @param string $pluk
     * @return mixed
     */
    public function getResult($key = null, $pluk = null)
    {
        $result = Arr::get($this->result, $key);
        if ($pluk) {
            $result = Arr::pluck($result, $pluk);
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

        $request->write($method, $url, '1.1', $header, $this->body);

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
        if (!is_array($this->result)) {
            return;
        }

        if (empty($this->result['errors'])) {
            return;
        }

        foreach ($this->result['errors'] as $error) {

            if ((int) key($error) > 0) {
                $error = current($error);
            }

            $errorMessage = '';
            if (key_exists('message', $this->result)) {
                $message = $this->result['message'];
            } elseif (key_exists('message', $error)) {
                $message = $error['message'];
            } else {
                $message = 'Unknown error: ' . json_encode($error) . '. Please contact MyParcel.';
            }

            if (key_exists('code', $error)) {
                $errorMessage = $error['code'];
            } elseif (key_exists('fields', $error)) {
                $errorMessage = $error['fields'][0];
            }

            $humanMessage = key_exists('human', $error) ? $error['human'][0] : '';
            $this->error = $errorMessage . ' - ' . $humanMessage . ' - ' . $message;
            break;
        }
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
        if ($this->getUserAgent() == false && $this->getUserAgentFromComposer() !== null) {
            $this->userAgent = $this->getUserAgentFromComposer();
        }

        return $this;
    }

    /**
     * Get version of in composer file
     */
    public function getUserAgentFromComposer()
    {
        $composer = 'vendor/myparcelnl/sdk/composer.json';
        if (file_exists($composer)) {
            $composerData = file_get_contents($composer);
            $jsonComposerData = json_decode($composerData, true);
            if (!empty($jsonComposerData['version'])) {
                $version = str_replace('v', '', $jsonComposerData['version']);
                return 'MyParcelNL-SDK/' . $version;
            }
        }

        return null;
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
