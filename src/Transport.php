<?php

namespace tafint_skype;

use Exception;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise;
use Psr\Http\Message\ResponseInterface;

class Transport {

    /**
     * @var Client
     */
    private $client;
    private $skypeToken;
    private $regToken;
    private $cloud;

    /**
     * @var Endpoint []
     */
    private static $Endpoints = null;

    private static function init() {
        if (static::$Endpoints) {
            return;
        }

        static::$Endpoints = [
            'login_get'    => new Endpoint('GET',
                'https://login.skype.com/login?client_id=578134&redirect_uri=https%3A%2F%2Fweb.skype.com'),

            'login_post'   => new Endpoint('POST',
                'https://login.skype.com/login?client_id=578134&redirect_uri=https%3A%2F%2Fweb.skype.com'),

            'asm'          => new Endpoint('POST',
                'https://api.asm.skype.com/v1/skypetokenauth'),

            'endpoint'     => (new Endpoint('POST',
                'https://client-s.gateway.messenger.live.com/v1/users/ME/endpoints'))
                ->needSkypeToken(),

            'contacts'     => (new Endpoint('GET',
                'https://contacts.skype.com/contacts/v1/users/%s/contacts'))
                ->needSkypeToken(),

            'send_message' => (new Endpoint('POST',
                'https://%sclient-s.gateway.messenger.live.com/v1/users/ME/conversations/%s/messages'))
                ->needRegToken(),
                
            'chats' => (new Endpoint('GET',
                'https://%sclient-s.gateway.messenger.live.com/v1/users/ME/conversations?startTime=%d&pageSize=%d&view=msnp24Equivalent&targetType=Passport|Skype|Lync|Thread|PSTN'))
                ->needRegToken(),
                
            'messages' => (new Endpoint('GET',
                'https://%sclient-s.gateway.messenger.live.com/v1/users/ME/conversations/%s/messages?startTime=%d&pageSize=%d&view=msnp24Equivalent|supportsMessageProperties&targetType=Passport|Skype|Lync|Thread|PSTN'))
                ->needRegToken(),

            'logout'  => (new Endpoint('Get', 'https://login.skype.com/logout?client_id=578134&redirect_uri=https%3A%2F%2Fweb.skype.com&intsrc=client-_-webapp-_-production-_-go-signin')),
        ];
    }

    public function __construct() {
        static::init();

        $Stack = new HandlerStack();
        $Stack->setHandler(new CurlHandler());

        /**
         * Здесь ставим ловушку, чтобы с помощью редиректов
         *   определить адрес сервера, который сможет отсылать сообщения
         */
        $Stack->push(Middleware::mapResponse(function (ResponseInterface $Response) {
            $code = $Response->getStatusCode();
            if (($code >= 301 && $code <= 303) || $code == 307 || $code == 308) {
                $location = $Response->getHeader('Location');
                preg_match('/https?://([^-]*-)client-s/', $location, $matches);
                if (array_key_exists(1, $matches)) {
                    $this->cloud = $matches[1];
                }
            }
            return $Response;
        }));

        /**
         * Ловушка для отлова хедера Set-RegistrationToken
         * Тоже нужен для отправки сообщений
         */
        $Stack->push(Middleware::mapResponse(function (ResponseInterface $Response) {
            $header = $Response->getHeader("Set-RegistrationToken");
            if (count($header) > 0) {
                $this->regToken = trim(explode(';', $header[0])[0]);
            }
            return $Response;
        }));

        //$cookieJar = new FileCookieJar('cookie.txt', true);

        $this->client = new Client([
            'handler' => $Stack,
            'cookies' => true
        ]);

    }

    /**
     * Выполнить реквест по имени endpoint из статического массива
     *
     * @param string $endpointName
     * @param array $params
     * @return ResponseInterface
     */
    private function request($endpointName, $params=[]) {
        if ($endpointName instanceof Endpoint){
            $Endpoint = $endpointName;
        }else{
            $Endpoint = static::$Endpoints[$endpointName];
        }

        if (array_key_exists("format", $params)) {
            $format = $params['format'];
            unset($params['format']);
            $Endpoint = $Endpoint->format($format);
        }
        $Request = $Endpoint->getRequest([
            'skypeToken' => $this->skypeToken,
            'regToken'   => $this->regToken,
        ]);

        $res = $this->client->send($Request, $params);
        return $res;
    }

    /**
     * Выполнить реквест по имени endpoint из статического массива
     * и вернуть DOMDocument построенный на body ответа
     *
     * @param string $endpointName
     * @param array $params
     * @return DOMDocument
     */
    private function requestDOM($endpointName, $params=[]) {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->recover = true;
        $body = $this->request($endpointName, $params)->getBody();
        $doc->loadHTML((string) $body);
        libxml_use_internal_errors(false);
        return $doc;
    }

    /**
     * Выполнить реквест по имени endpoint из статического массива
     * и преобразовать JSON-ответ в array
     * @param string $endpointName
     * @param array $params
     * @return array
     */
    private function requestJSON($endpointName, $params=[]) {
        return json_decode($this->request($endpointName, $params)->getBody());
    }

    /**
     * Запрос для входа.
     * @param string $username
     * @param string $password
     * @param null $captchaData Можем передать массив с решением капчи
     * @return DOMDocument
     */
    private function postToLogin($username, $password, $captchaData=null) {
        $Doc = $this->requestDOM('login_get');
        $LoginForm = $Doc->getElementById('loginForm');
        $inputs = $LoginForm->getElementsByTagName('input');
        $formData = [];
        /* @var $input \DOMElement */
        foreach ($inputs as $input) {
            $formData[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $now = time();
        $formData['timezone_field'] = str_replace(':', '|', date('P', $now));
        $formData['username'] = $username;
        $formData['password'] = $password;
        $formData['js_time'] = $now;
        if ($captchaData) {
            $formData['hip_solution'] = $captchaData['hip_solution'];
            $formData['hip_token'] = $captchaData['hip_token'];
            $formData['fid'] = $captchaData['fid'];
            $formData['hip_type'] = 'visual';
            $formData['captcha_provider'] = 'Hip';
        } else {
            unset($formData['hip_solution']);
            unset($formData['hip_token']);
            unset($formData['fid']);
            unset($formData['hip_type']);
            unset($formData['captcha_provider']);
        }

        return $this->requestDOM('login_post', [
            'form_params' => $formData
        ]);
    }

    /**
     * Выполняем запрос для входа, ловим из ответа skypeToken
     * Проверяем не спросили ли у нас капчу и не возникло ли другой ошибки
     * Если всё плохо, то бросим исключение, иначе вернём true
     * @param $username
     * @param $password
     * @param null $captchaData
     * @return bool
     * @throws Exception
     */
    public function login($username, $password, $captchaData=null) {
        $Doc = $this->postToLogin($username, $password, $captchaData);
        $XPath = new DOMXPath($Doc);
        $Inputs = $XPath->query("//input[@name='skypetoken']");
        if ($Inputs->length) {
            $this->skypeToken = $Inputs->item(0)->attributes->getNamedItem('value')->nodeValue;
            $this->request('asm', [
                'form_params' => [
                    'skypetoken' => $this->skypeToken,
                ],
            ]);
            $this->request('endpoint', [
                'headers' => [
                    'Authentication' => "skypetoken=$this->skypeToken"
                ],
                'json' => [
                    'skypetoken' => $this->skypeToken
                ]
            ]);
            return true;
        }

        $CaptchaContainer = $Doc->getElementById("captchaContainer");
        if ($CaptchaContainer) {
            // Вот здесь определяем данные капчи
            $Scripts = $CaptchaContainer->getElementsByTagName('script');
            if ($Scripts->length > 0) {
                $script = "";
                foreach ($Scripts as $item) {
                    $script .= $item->textContent;
                }
                preg_match_all("/skypeHipUrl = \"(.*)\"/", $script, $matches);
                $url = $matches[1][0];
                $rawjs = $this->client->get($url)->getBody();
                $captchaData = $this->processCaptcha($rawjs);
                // Если решение получено, пытаемся ещё раз залогиниться, но уже с решением капчи
                if ($this->login($username, $password, $captchaData)) {
                    return true;
                } else {
                    throw new Exception("Captcha error: $url");
                }
            }
        }
        $Errors = $XPath->query('//*[contains(concat(" ", normalize-space(@class), " "), " message_error ")]');
        if ($Errors->length) {
            $errorMsg = '';
            foreach ($Errors as $Error) {
                $errorMsg = $errorMsg . PHP_EOL . $Error->textContent;
            }
            throw new Exception($errorMsg);
        }
        throw new Exception("Unable to find skype token");
    }

    /**
     * Выход
     * @return bool
     */
    public function logout() {
        $this->request('logout');
        return true;
    }

    /**
     * Заглушка для ввода капчи. Сейчас просто выводит в консоли урл картинки
     * и ждёт ввода с клавиатуры решения
     * @param $script
     * @return array
     */
    private function processCaptcha($script) {
        preg_match_all("/imageurl:'([^']*)'/", $script, $matches);
        $imgurl = $matches[1][0];
        preg_match_all("/hid=([^&]*)/", $imgurl, $matches);
        $hid = $matches[1][0];
        preg_match_all("/fid=([^&]*)/", $imgurl, $matches);
        $fid = $matches[1][0];
        print_r(PHP_EOL . "url: " . $imgurl . PHP_EOL);
        return [
            'hip_solution' => trim(readline()),
            'hip_token'    => $hid,
            'fid'          => $fid,
        ];
    }


    public function send($username, $text, $edit_id = false) {
        $milliseconds = round(microtime(true) * 1000);

        $message_json = [
            'content' => $text,
            'messagetype' => 'RichText',
            'contenttype' => 'text',
            'clientmessageid' => "$milliseconds",
        ];

        if ($edit_id){
            $message_json['skypeeditedid'] = $edit_id;
            unset($message_json['clientmessageid']);
        }

        $Response = $this->requestJSON('send_message', [
            'json' => $message_json,
            'format' => [$this->cloud, "8:$username"]
        ]);

        if (array_key_exists("OriginalArrivalTime", $Response)){//if successful sended
            return $milliseconds;//message ID
        }else{
            return false;
        }
    }

    /**
     * Скачиваем список всех контактов и информацию о них для залогиненного юзера
     * @param $username
     * @return null
     */
    public function loadContacts($username) {
        $response = $this->requestJSON('contacts', [
            'format' => [$username],
        ]);

        return isset($response->contacts) ? $response->contacts : null;
    }
	
	public function loadMessages($username,$time,$pageSize) {
        $response = $this->requestJSON('messages', [
            'format' => [$this->cloud, $username,$time,$pageSize],
        ]);

        return isset($response->messages) ? $response->messages : null;
    }

	public function loadChats($time,$pageSize) {
        $response = $this->requestJSON('chats', [
            'format' => [$this->cloud,$time,$pageSize],
        ]);

        return $response;
    }

	public function skypeToken() {
        return $this->skypeToken;
    }
	public function regToken() {
        return $this->regToken;
    }

    public function loadProfile()
    {
        $request = new Endpoint('GET', 'https://api.skype.com/users/self/displayname');
        $request->needSkypeToken();

        $response = $this->requestJSON($request);

        return isset($response->username) ? $response : null;
    }

    /**
     * Скачиваем информацию о конкретном контакте, только если его нет в кеше
     * @param $username
     * @return array
     */
    public function loadContact($username) {
        $request = new Endpoint('POST', 'https://api.skype.com/users/self/contacts/profiles');
        $request->needSkypeToken();

        $Result = $this->requestJSON($request, [
            'form_params' => [
                'contacts' => [$username]
            ]
        ]);
        return $Result;
    }


    public function getNewMessages($username){
        $request = new Endpoint('POST', 'https://%sclient-s.gateway.messenger.live.com/v1/users/ME/endpoints/SELF/subscriptions/0/poll');
        $request->needRegToken();

        $response = $this->requestJSON($request, [
            'format' => [$this->cloud, "8:$username"]
        ]);

        return isset($response->eventMessages) ? $response->eventMessages : null;
    }

    public function subscribeToResources()
    {
        $request = new Endpoint('POST', 'https://client-s.gateway.messenger.live.com/v1/users/ME/endpoints/SELF/subscriptions');
        $request->needRegToken();

        return $this->requestJSON($request, [
            'json' => [
                'interestedResources' => [
                    '/v1/threads/ALL',
                    '/v1/users/ME/contacts/ALL',
                    '/v1/users/ME/conversations/ALL/messages',
                    '/v1/users/ME/conversations/ALL/properties',
                ],
                'template' => 'raw',
                'channelType' => 'httpLongPoll'
            ]
        ]);
    }

    public function createStatusEndpoint()
    {
        $request = new Endpoint('PUT', 'https://client-s.gateway.messenger.live.com/v1/users/ME/endpoints/SELF/presenceDocs/messagingService');
        $request->needRegToken();

        $this->request($request, [
            'json' => [
                'id' => 'messagingService',
                'type' => 'EndpointPresenceDoc',
                'selfLink' => 'uri',
                'privateInfo' =>  ["epname" => "skype"],
                'publicInfo' =>  [
                    "capabilities" => "video|audio",
                    "type" => 1,
                    "skypeNameVersion" => 'skype.com',
                    "nodeInfo" => 'xx',
                    "version" => '908/1.30.0.128//skype.com',
                ],
            ]
        ]);
    }

    public function setStatus($status)
    {
        $request = new Endpoint('PUT', 'https://client-s.gateway.messenger.live.com/v1/users/ME/presenceDocs/messagingService');
        $request->needRegToken();

        $this->request($request, [
            'json' => [
                'status' => $status
            ]
        ]);
    }
}
