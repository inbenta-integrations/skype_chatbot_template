<?php
namespace Inbenta\SkypeConnector\ExternalAPI;

use Exception;
use GuzzleHttp\Client as Guzzle;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;

class SkypeBot
{
    /**
     * Skype App ID and Password.
     *
     * @var string|null
     */
    protected $appId;
    protected $appPassword;
    protected $serviceUrl;
    protected $accessToken = '';

    CONST TOKEN_AUTH_URL            = 'https://login.microsoftonline.com/botframework.com/oauth2/v2.0/token';
    CONST CONVERSATIONS_ENDPOINT    = '/v3/conversations/{{conversation_id}}/activities/{{replyToId}}';

    /**
     * Create a new instance.
     *
     * @param string|null $appId
     * @param string|null $appPassword
     * @param string|null $serviceUrl
     */
    public function __construct($appId = null, $appPassword = null, $serviceUrl = null)
    {
        $this->appId = $appId;
        $this->appPassword = $appPassword;
        $this->serviceUrl = $serviceUrl;
    }

    public function setServiceUrl($serviceUrl)
    {
        $this->serviceUrl = $serviceUrl;
    }

    /**
     * Send an outgoing message.
     *
     * @param array $activity
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function send(array $activity)
    {
        $needles        = ['{{conversation_id}}', '{{replyToId}}'];
        $replacements   = [$activity['conversation']['id'], $activity['replyToId']];
        $endpoint       = str_replace($needles, $replacements, static::CONVERSATIONS_ENDPOINT);

        return $this->auth_send('POST', $endpoint, [
            'json' => $activity,
        ]);

    }

    /**
     * Send a request to the Skype API.
     *
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function auth_send($method, $uri, array $options = [])
    {
        $this->accessToken = $this->getToken();
        $guzzle = new Guzzle([
            'base_uri' => $this->serviceUrl,
        ]);

        try {
            $result = $guzzle->request($method, $uri, array_merge_recursive($options, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ],
            ]));
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            echo json_encode(["error" => $e->getMessage() ]);
            $result = $e->getResponse();
        }
        return $result;

    }

    protected function getToken()
    {
        $guzzle = new Guzzle();
        $url = static::TOKEN_AUTH_URL;
        try {
            $result = $guzzle->request('POST', $url, [
                'headers' => [
                    'Host' => 'login.microsoftonline.com'
                ],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->appId,
                    'client_secret' => $this->appPassword,
                    'scope' => 'https://api.botframework.com/.default'
                ]
            ]);            
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $result = $e->getResponse();
        }

        $body = $result->getBody();
        $obj = json_decode($body);
        $this->accessToken = !empty($obj->access_token) ? $obj->access_token : '';
        $this->tokenExpiry = !empty($obj->expires_in) ? $obj->expires_in : '';
        return $this->accessToken;
    }
}
