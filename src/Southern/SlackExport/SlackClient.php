<?php


namespace Southern\SlackExport;


use GuzzleHttp\Client;

class SlackClient
{
    private $token;
    
    private $guzzle;
    
    public function __construct($token)
    {
        $this->token = $token;
        $this->guzzle = new Client([]);
    }
    
    public function execute($method, array $params = [])
    {
        $uri = "https://slack.com/api/$method?" . http_build_query($params);
        $response = $this->guzzle->get($uri, ['headers' => [
            'Authorization' => "Bearer $this->token",
        ]]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException("Slack response status code {$response->getStatusCode()}");
        }
        return json_decode((string)$response->getBody(), true);
    }
}
