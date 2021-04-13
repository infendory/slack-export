<?php

namespace Southern\SlackExport;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

class SlackExporter
{
    const MAX_ITERATIONS = 100;
    const SLEEP = 1;

    protected $usersInfo;
    
    protected $conversationsInfo;

    /** @var SlackClient  */
    protected $client;

    protected $token;

    public function __construct($token)
    {
        $this->token = $token;
        $this->client = new SlackClient($token);
    }

    protected function ensureUsersLoaded()
    {
        if (null === $this->usersInfo) {
            $this->usersInfo = ArrayUtils::indexByColumn($this->callApi('users.list')['members']);
        }
    }

    protected function ensureConversationsLoaded()
    {
        if (null === $this->conversationsInfo) {
            $apiResponse = $this->callApi('users.conversations', ['types' => 'public_channel,private_channel,mpim,im']);
            $this->conversationsInfo = ArrayUtils::indexByColumn($apiResponse['channels']);
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

    protected function getConversationMessages($channelId, $since, $till)
    {
        return $this->cycle("conversations.history", ['channel' => $channelId], $since, $till);
    }
    
    public function getAllHistories($since, $till)
    {
        $this->ensureConversationsLoaded();
        $messages = [];
        foreach ($this->conversationsInfo as $conversationId => $info) {
            $conversationType = $this->getConversationType($info);
            $conversationCaption = $this->getConversationCaption($info);
            $messages["{$conversationType}s"][$conversationCaption] = $this->getConversationMessages($conversationId, $since, $till);
        }
        return $messages;
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

    protected function getConversationType($conversationInfo)
    {
        if ($conversationInfo['is_im']) {
            return 'im';
        } elseif ($conversationInfo['is_mpim']) {
            return 'mpim';
        } elseif ($conversationInfo['is_channel']) {
            return 'channel';
        } elseif ($conversationInfo['is_group']) {
            return 'group';
        } else {
            throw new LogicException("Unexpected conversation type");
        }
    }
    
    protected function getConversationCaption($conversationInfo)
    {
        if ($conversationInfo['is_im']) {
            $this->ensureUsersLoaded();
            return $this->usersInfo[$conversationInfo['user']]['name'];
        } else {
            return $conversationInfo['name_normalized'];
        }
    }
    
    public function regroup($histories, $intervalTitle)
    {
        $results = [];

        $entities = ['channel', 'im', 'group', 'mpim'];

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
        $response = $this->client->execute($method, $params);

        sleep(self::SLEEP);

        $body = $response;
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