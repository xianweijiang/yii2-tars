<?php
/**
 * Created by PhpStorm.
 * Author: tsingsun
 * Date: 2018/1/15
 * Time: 下午4:41
 */

namespace Lxj\Yii2\Tars;

use Yii;
use yii\base\InvalidConfigException;
use yii\web\HeaderCollection;
use yii\web\RequestParserInterface;
use yii\web\NotFoundHttpException;
use yii\web\Cookie;

/**
 * Class Yii2Request
 * @package Lxj\Yii2\Tars
 */
class Yii2Request extends \yii\web\Request
{
    /**
     * @var \Tars\core\Request
     */
    public $tarsRequest;
    public $ipHeaders = [
        'X-Forwarded-For', // Common
        'X-Real-Ip',
    ];

    /**
     * @param \Tars\core\Request $request
     * @return $this
     */
    public function setTarsRequest(\Tars\core\Request $request)
    {
        $this->tarsRequest = $request;
        $this->clear();
        return $this;
    }

    /**
     * @return \Tars\core\Request
     */
    public function getTarsRequest()
    {
        return $this->tarsRequest;
    }

    /**
     * @inheritdoc
     */
    public function resolve()
    {
        $result = Util::app()->getUrlManager()->parseRequest($this);
        if ($result !== false) {
            list ($route, $params) = $result;
            if ($this->getQueryParams() === null) {
                $this->_queryParams = $params;
            } else {
                $this->_queryParams = $params + $this->_queryParams;
            }
            return [$route, $this->getQueryParams()];
        }
        throw new NotFoundHttpException(Yii::t('yii', 'Page not found.'));
    }

    private $_headers;

    /**
     * @inheritdoc
     */
    public function getHeaders()
    {
        if ($this->_headers === null) {
            $this->_headers = new HeaderCollection();
            $tarsRequest = $this->getTarsRequest();
            $headers = isset($tarsRequest->data['header']) ? $tarsRequest->data['header'] : [];
            foreach ($headers as $name => $value) {
                $this->_headers->add($name, $value);
            }
        }
        return $this->_headers;
    }

    /**
     * @inheritdoc
     */
    public function getMethod()
    {
        $tarsRequest = $this->getTarsRequest();
        $post = isset($tarsRequest->data['post']) ? (is_array($tarsRequest->data['post']) ? $tarsRequest->data['post'] : []) : [];
        if (isset($post[$this->methodParam])) {
            return strtoupper($post[$this->methodParam]);
        }
        if ($this->headers->has('X-Http-Method-Override')) {
            return strtoupper($this->headers->get('X-Http-Method-Override'));
        }

        if (isset($_SERVER['REQUEST_METHOD'])) {
            return strtoupper($_SERVER['REQUEST_METHOD']);
        }
        return 'GET';
    }

    private $_rawBody;

    /**
     * @inheritdoc
     */
    public function getRawBody()
    {
        if ($this->_rawBody === null) {
            $tarsRequest = $this->getTarsRequest();
            $this->_rawBody = isset($tarsRequest->data['post']) ?
                (is_array($tarsRequest->data['post']) ? http_build_query($tarsRequest->data['post']) : $tarsRequest->data['post']) :
                null;
        }
        return $this->_rawBody;
    }

    public $_bodyParams;

    /**
     * @inheritdoc
     */
    public function getBodyParams()
    {
        if ($this->_bodyParams === null) {
            $tarsRequest = $this->getTarsRequest();
            $post = isset($tarsRequest->data['post']) ? (is_array($tarsRequest->data['post']) ? $tarsRequest->data['post'] : []) : [];

            if (isset($post[$this->methodParam])) {
                $this->_bodyParams = $post;
                unset($this->_bodyParams[$this->methodParam]);
                return $this->_bodyParams;
            }
            $contentType = $this->getContentType();
            if (($pos = strpos($contentType, ';')) !== false) {
                // e.g. application/json; charset=UTF-8
                $contentType = substr($contentType, 0, $pos);
            }
            if (isset($this->parsers[$contentType])) {
                $parser = Yii::createObject($this->parsers[$contentType]);
                if (!($parser instanceof RequestParserInterface)) {
                    throw new InvalidConfigException("The '$contentType' request parser is invalid. It must implement the yii\\web\\RequestParserInterface.");
                }
                $this->_bodyParams = $parser->parse($this->getRawBody(), $contentType);
            } elseif (isset($this->parsers['*'])) {
                $parser = Yii::createObject($this->parsers['*']);
                if (!($parser instanceof RequestParserInterface)) {
                    throw new InvalidConfigException("The fallback request parser is invalid. It must implement the yii\\web\\RequestParserInterface.");
                }
                $this->_bodyParams = $parser->parse($this->getRawBody(), $contentType);
            } elseif ($this->getMethod() === 'POST') {
                // PHP has already parsed the body so we have all params in tarsRequest
                $this->_bodyParams = $post;
            } else {
                $this->_bodyParams = [];
                mb_parse_str($this->getRawBody(), $this->_bodyParams);
            }
        }
        return $this->_bodyParams;
    }

    private $_queryParams;

    /**
     * @inheritdoc
     */
    public function getQueryParams()
    {
        if ($this->_queryParams === null) {
            $tarsRequest = $this->getTarsRequest();
            $this->_queryParams = isset($tarsRequest->data['get']) ? $tarsRequest->data['get'] : [];
        }
        return $this->_queryParams;
    }

    /**
     * @inheritdoc
     */
    protected function loadCookies()
    {
        $cookies = [];
        $tarsRequest = $this->getTarsRequest();
        $originCookies = isset($tarsRequest->data['cookie']) ? $tarsRequest->data['cookie'] : [];
        if ($this->enableCookieValidation) {
            if ($this->cookieValidationKey == '') {
                throw new InvalidConfigException(get_class($this) . '::cookieValidationKey must be configured with a secret key.');
            }
            foreach ($originCookies as $name => $value) {
                if (!is_string($value)) {
                    continue;
                }
                $data = Util::app()->getSecurity()->validateData($value, $this->cookieValidationKey);
                if ($data === false) {
                    continue;
                }
                $data = @unserialize($data);
                if (is_array($data) && isset($data[0], $data[1]) && $data[0] === $name) {
                    $cookies[$name] = new Cookie([
                        'name' => $name,
                        'value' => $data[1],
                        'expire' => null,
                    ]);
                }
            }
        } else {
            foreach ($originCookies as $name => $value) {
                $cookies[$name] = new Cookie([
                    'name' => $name,
                    'value' => $value,
                    'expire' => null,
                ]);
            }
        }
        return $cookies;
    }

    /**
     * @inheritdoc
     */
    protected function resolveRequestUri()
    {
        if ($this->headers->has('X-Rewrite-Url')) { // IIS
            $requestUri = $this->headers->get('X-Rewrite-Url');
        } elseif (isset($_SERVER['REQUEST_URI'])) {
            $requestUri = $_SERVER['REQUEST_URI'];
            if ($requestUri !== '' && $requestUri[0] !== '/') {
                $requestUri = preg_replace('/^(http|https):\/\/[^\/]+/i', '', $requestUri);
            }
        } else {
            throw new InvalidConfigException('Unable to determine the request URI.');
        }

        return $requestUri;
    }

    /**
     * @inheritdoc
     */
    public function getQueryString()
    {
        //todo rewrite r
        return isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
    }

    /**
     * @inheritdoc
     */
    public function getIsSecureConnection()
    {
        if (isset($_SERVER['HTTPS']) && (strcasecmp($_SERVER['HTTPS'], 'on') === 0 || $_SERVER['HTTPS'] == 1)) {
            return true;
        }
        foreach ($this->secureProtocolHeaders as $header => $values) {
            if (($headerValue = $this->headers->get($header, null)) !== null) {
                foreach ($values as $value) {
                    if (strcasecmp($headerValue, $value) === 0) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @inheritdoc
     */
    public function getServerName()
    {
        return $this->headers->get('SERVER_NAME');
    }

    /**
     * @inheritdoc
     */
    public function getServerPort()
    {
        return isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : null;
    }

    /**
     * @inheritdoc
     */
    public function getRemoteIP()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
    }

    /**
     * @inheritdoc
     */
    public function getRemoteHost()
    {
        return isset($_SERVER['REMOTE_HOST']) ? $_SERVER['REMOTE_HOST'] : null;
    }

    /**
     * @inheritdoc
     */
    public function getAuthCredentials()
    {
        $auth_token = $this->getHeaders()->get('Authorization');
        if ($auth_token !== null && strncasecmp($auth_token, 'basic', 5) === 0) {
            $parts = array_map(function ($value) {
                return strlen($value) === 0 ? null : $value;
            }, explode(':', base64_decode(mb_substr($auth_token, 6)), 2));
            if (count($parts) < 2) {
                return [$parts[0], null];
            }
            return $parts;
        }
        return [null, null];
    }

    /**
     * 清理变量
     */
    public function clear()
    {
        $this->_headers = null;
        $this->_bodyParams = null;
        $this->_queryParams = null;
        $this->_rawBody = null;
        $this->setHostInfo(null);
        $this->setPathInfo(null);
        $this->setUrl(null);
        $this->setAcceptableContentTypes(null);
        $this->setAcceptableLanguages(null);
        $tarsRequest = $this->getTarsRequest();
        $_SERVER = [
            'SCRIPT_FILENAME' => '/index.php',
            'SCRIPT_NAME' => '/index.php',
            'PHP_SELF' => '',
            'ORIG_SCRIPT_NAME' => '',
            'DOCUMENT_ROOT' => '',
        ];
        $server = isset($tarsRequest->data['server']) ? $tarsRequest->data['server'] : [];
        foreach ($server as $key => $value) {
            $_SERVER[strtoupper($key)] = $value;
        }
        $headers = isset($tarsRequest->data['header']) ? $tarsRequest->data['header'] : [];
        foreach ($headers as $key => $value) {
            $key = str_replace('-', '_', $key);
            $key = strtoupper($key);

            if (! in_array($key, ['CONTENT_LENGTH', 'CONTENT_MD5', 'CONTENT_TYPE', 'REMOTE_ADDR', 'SERVER_PORT', 'HTTPS'])) {
                $key = 'HTTP_' . $key;
            }

            $_SERVER[$key] = $value;
        }
        if ('cli-server' === PHP_SAPI) {
            if (array_key_exists('HTTP_CONTENT_LENGTH', $_SERVER)) {
                $_SERVER['CONTENT_LENGTH'] = $_SERVER['HTTP_CONTENT_LENGTH'];
            }
            if (array_key_exists('HTTP_CONTENT_TYPE', $_SERVER)) {
                $_SERVER['CONTENT_TYPE'] = $_SERVER['HTTP_CONTENT_TYPE'];
            }
        }
        if (isset($_SERVER['argv'])) {
            unset($_SERVER['argv']);
        }
        if (isset($_SERVER['argc'])) {
            unset($_SERVER['argc']);
        }
    }
}
