<?php

namespace Southern\SlackExport;

use Frlnc\Slack\Core\Commander;
use Frlnc\Slack\Http\CurlInteractor;
use Frlnc\Slack\Http\SlackResponseFactory;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class SlackExporter
{
    const MAX_ITERATIONS = 100;
    const SLEEP = 1;

    protected $usersInfo;
    protected $channelsInfo;
    protected $groupsInfo;
    protected $imsInfo;

    protected $api;

    protected $token;

    protected $debugMode = true;

    public function __construct($token)
    {
        $interactor = new CurlInteractor;
        $interactor->setResponseFactory(new SlackResponseFactory);
        $commander = new Commander($token, $interactor);

        $this->api = $commander;
        $this->token = $token;
    }

    protected function ensureUsersLoaded()
    {
        if (null === $this->usersInfo) {
            $this->usersInfo = ArrayUtils::indexByColumn($this->callApi('users.list')['members']);
        }
    }

    protected function ensureImsLoaded()
    {
        if (null === $this->imsInfo) {
            $this->imsInfo = ArrayUtils::indexByColumn($this->callApi('im.list')['ims']);
        }
    }

    protected function ensureChannelsLoaded()
    {
        if (null === $this->channelsInfo) {
            $this->channelsInfo = ArrayUtils::indexByColumn($this->callApi('channels.list')['channels']);
        }
    }

    protected function ensureGroupsLoaded()
    {
        if (null === $this->groupsInfo) {
            $this->groupsInfo = ArrayUtils::indexByColumn($this->callApi('groups.list')['groups']);
        }
    }

    protected function getUserInfo($id)
    {
        $this->ensureUsersLoaded();
        if (!isset($this->usersInfo[$id])) {
            throw new InvalidArgumentException("User $id not found");
        }
        return $this->usersInfo[$id];
    }

    protected function getMessages($objectsType, $channelId, $since, $till)
    {
        return $this->cycle("$objectsType.history", ['channel' => $channelId], $since, $till);
    }

    public function getChannelsHistory($since, $till)
    {
        $this->ensureChannelsLoaded();
        $channelMessages = [];
        foreach ($this->channelsInfo as $channelId => $info) {
            $channelMessages[$info['name_normalized']] = $this->getMessages("channels", $channelId, $since, $till);
        }
        return $channelMessages;
    }
    
    public function getImsHistory($since, $till)
    {
        $this->ensureUsersLoaded();
        $this->ensureImsLoaded();
        $imMessages = [];
        foreach ($this->imsInfo as $imId => $info) {
            $name = $this->usersInfo[$info['user']]['name'];
            $imMessages[$name] = $this->getMessages("im", $imId, $since, $till);
        }
        return $imMessages;
    }

    public function getGroupsHistory($since, $till)
    {
        $this->ensureGroupsLoaded();
        $groupMessages = [];
        foreach ($this->groupsInfo as $groupId => $info) {
            $groupMessages[$info['name_normalized']] = $this->getMessages("groups", $groupId, $since, $till);
        }
        return $groupMessages;
    }

    public function getAllHistories($since, $till)
    {
        $histories = [
            'channels' => $this->getChannelsHistory($since, $till),
            'ims' => $this->getImsHistory($since, $till),
            'groups' => $this->getGroupsHistory($since, $till),
        ];
        return $histories;
    }

    public function getForWeek()
    {
        return $this->getAllHistories(time()-86400*7, time());
    }

    public function getForToday()
    {
        return $this->getAllHistories(strtotime('yesterday 0:00'), time());
    }

    public function getForDate($date)
    {
        $since = strtotime("$date 00:00:00");
        $till = strtotime("+1 day", $since);

        return $this->getAllHistories($since, $till);
    }

    public function regroup($histories, $intervalTitle)
    {
        $results = [];

        $entities = ['channel', 'im', 'group'];

        foreach ($entities as $entity) {
            foreach ($histories["{$entity}s"] as $name => $messages) {
                if (count($messages) == 0) {
                    continue;
                }
                $results["History for slack $entity $name ($intervalTitle)"] = $messages;
            }
        }
        return $results;
    }

    public function getHtmlsForDate($date)
    {
        $histories = $this->getForDate($date);
        $histories = $this->regroup($histories, $date);

        $htmls = [];
        foreach ($histories as $title => $messages) {
            $html = $this->format($title, $messages);
            $htmls[$title] = $html;
        }
        return $htmls;
    }

    public function sendEmails($emails, $from, $to)
    {
        foreach ($emails as $title => $html)
        {
            $this->sendEmail($from, $to, $title, $html);
        }
    }

    public function format($title, $messages)
    {
        $formattedMessages = implode("\n", array_map([$this, 'formatMessage'], $messages));

        $format = <<<ABC
<h1>%s</h1>
%s
ABC;
        $html = sprintf($format, htmlspecialchars($title), $formattedMessages);
        return $html;
    }

    public function formatMessage($message)
    {
        $this->ensureUsersLoaded();

        $format = <<<ABC
<div>
    <em>%s</em>
    <strong>%s</strong>
    <span>%s</span>
</div>
ABC;

        $date = date("Y-m-d H:i:s", (int)$message['ts']);

        $username = isset($message['user'])
            ? $this->usersInfo[$message['user']]['name']
            : "nobody";
        $text = $message['text'];

        $html = sprintf($format, htmlspecialchars($date), htmlspecialchars($username), nl2br(htmlspecialchars($text)));
        return $html;
    }


    protected function cycle($method, $params, $since, $till)
    {
        $currentSince = $since;
        $currentTill = $till;
        $messages = [];
        $counter = 0;
        do {
            if ($counter > self::MAX_ITERATIONS) {
                throw new LogicException("MAX_ITERATIONS reached");
            }
            $currentResponse = $this->callApi($method, $params + ['count' => 100, 'oldest' => $currentSince, 'latest' => $currentTill, 'inclusive' => false]);
            $messages = array_merge($messages, $currentResponse['messages']);
            $counter++;
            if ($currentResponse['has_more']) {
                $currentTill = end($currentResponse['messages'])['ts'];
            }
        } while ($currentResponse['has_more']);
        return array_reverse($messages);
    }

    /**
     * @param string $method
     * @param array $params
     * @return array
     */
    protected function callApi($method, array $params = [])
    {
        $response = $this->api->execute($method, $params + ['token' => $this->token]);

        sleep(self::SLEEP);

        $body = $response->getBody();
        /** @var array $body */
        if (true !== $body['ok']) {
            throw new RuntimeException("Calling $method failed: {$body['error']}, params: " . var_export($params, true));
        }
        return $body;
    }

    protected function sendEmail($from, $to, $subject, $body)
    {
        //fix fields
        $_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = "From: $from\n" .
            'X-Mailer: PHP/' . phpversion()."\n".
            "MIME-Version: 1.0\n";
        $headers .= "Content-type: text/html; charset=UTF-8";

        $fParameter = "-f$from";

        //do send
        $result = mail($to, $_subject, $body, $headers, $fParameter);

        if (!$result) {
            trigger_error("Cannot send mail to `$to` from `$from`", E_USER_WARNING);
        }

        return $result;
    }
}